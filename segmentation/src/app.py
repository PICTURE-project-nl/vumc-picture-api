#!flask/bin/python
import time
import logging
import os
import json
import uuid
import shutil
from flask import Flask, request, url_for, jsonify, send_from_directory
from celery import Celery
from doInference import do_segmentation
import base64
import SimpleITK as sitk

# Configure logging
logging.basicConfig(format='%(asctime)s - %(message)s', level=logging.INFO)

UPLOAD_FOLDER = '/uploads'
ALLOWED_EXTENSIONS = set(['mha', 'nii', 'gz'])

app = Flask(__name__)
app.config['UPLOAD_FOLDER'] = UPLOAD_FOLDER
app.config['CELERY_BROKER_URL'] = 'redis://redis:6379/2'
app.config['result_backend'] = 'redis://redis:6379/2'

celery = Celery(app.name, broker=app.config['CELERY_BROKER_URL'])
celery.conf.update(app.config)

def allowed_file(filename):
    return '.' in filename and \
           filename.rsplit('.', 1)[1].lower() in ALLOWED_EXTENSIONS

@celery.task(bind=True)
def do_segment(self, upload_dir, extension):
    T1c_path = os.path.join(app.config['UPLOAD_FOLDER'], upload_dir, 'T1c' + extension)
    T1w_path = os.path.join(app.config['UPLOAD_FOLDER'], upload_dir, 'T1w' + extension)
    T2w_path = os.path.join(app.config['UPLOAD_FOLDER'], upload_dir, 'T2w' + extension)
    Flr_path = os.path.join(app.config['UPLOAD_FOLDER'], upload_dir, 'Flr' + extension)
    self.update_state(state='PROGRESS')

    logging.info(f"Starting segmentation for upload directory: {upload_dir}")

    try:
        do_segmentation(T1c=T1c_path, T1w=T1w_path, T2w=T2w_path, Flr=Flr_path, upload_dir=os.path.join('/wdir/out/', upload_dir))
    except Exception as e:
        logging.error(f"Segmentation failed: {e}")
        raise

    out_file = '/wdir/out/' + upload_dir + '/Atlas_segmentation.mha'
    sitk.WriteImage(sitk.ReadImage(out_file), out_file.replace('.mha', '.nii'))
    result = {'upload_dir': upload_dir, 'segmentation_mha': out_file}

    logging.info(f"Segmentation completed for upload directory: {upload_dir}")

    return {'status': 'task completed', 'result': result, 'upload_dir': upload_dir}

@app.route('/downloads/<path:path>')
def send_files(path):
    logging.info(f"Download request for file: {path}")
    return send_from_directory('/wdir/out/', path)

@app.route('/remove/<upload_dir>', methods=['DELETE'])
def remove_(upload_dir):
    shutil.rmtree('/wdir/out/' + str(upload_dir))
    logging.info(f"Removed directory: {upload_dir}")
    response = {'status': 'OK'}
    return jsonify(response), 200

def _get_task_status(task_id):
    task = do_segment.AsyncResult(task_id)
    if task.state == 'PENDING':
        response = {
            'state': task.state,
            'status': 'Pending...'
        }
    elif task.state != 'FAILURE':
        response = {
            'state': task.state,
            'status': task.state,
        }
        if task.ready():
            response['result'] = task.result
    else:
        response = {
            'state': task.state,
            'status': task.state,
        }
    logging.info(f"Task status for {task_id}: {response}")
    return response

@app.route('/status/<task_id>', methods=['GET'])
def get_task_status(task_id):
    response = _get_task_status(task_id)
    return jsonify(response), 200

@app.route('/responses', methods=['POST'])
def get_responses():
    data = request.values
    status = _get_task_status(data['request_id'])
    if status['state'] in ['PENDING', 'PROGRESS']:
        response = [{'type': 'DelayedResponse', 'request_id': data['request_id']}]
    elif status['state'] == 'FAILURE':
        response = [{'type': 'PlainText', 'content': 'The results of request {} cannot be retrieved'.format(data['request_id']), 'label': ''}]
    elif status['state'] == 'SUCCESS':
        seg = status['result']['result']['segmentation_mha']
        with open(seg, 'rb') as f:
            vol_string = base64.encodestring(f.read()).decode('utf-8')
        response = [status, {'type': 'LabelVolume', 'content': vol_string, 'label': ''}]
    else:
        response = [status]
    return jsonify(response), 200

@app.route('/segment', methods=['POST'])
def segment():
    classifications = ['T1c', 'T1w', 'T2w', 'Flr']
    upload_dir = str(uuid.uuid4())
    os.makedirs(os.path.join(app.config['UPLOAD_FOLDER'], upload_dir), exist_ok=True)

    for ft in classifications:
        file = request.files[ft]
        if file and allowed_file(file.filename):
            if file.filename.endswith('.nii.gz'):
                ext = '.nii.gz'
            elif file.filename.endswith('.nii'):
                ext = '.nii'
            elif file.filename.endswith('.mha'):
                ext = '.mha'
            filename = ft + ext
            logging.info(f"Saving file {filename} to {upload_dir}")
            file.save(os.path.join(app.config['UPLOAD_FOLDER'], upload_dir, filename))

    task = do_segment.delay(upload_dir, ext)
    logging.info(f"Segmentation task started with id: {task.id}")
    response = {'location': url_for('get_task_status', task_id=task.id)}
    return jsonify(response), 202

@app.route('/predict', methods=['POST'])
def predict():
    classification_mapping = {'t1ce': 'T1c', 't1': 'T1w', 't2': 'T2w', 'flair': 'Flr'}
    classifications = ['t1ce', 't1', 't2', 'flair']
    upload_dir = str(uuid.uuid4())
    os.makedirs(os.path.join(app.config['UPLOAD_FOLDER'], upload_dir), exist_ok=True)

    for c in classifications:
        file = request.files[c]
        app.logger.info(file.filename)
        if file:
            filename = classification_mapping[c] + '.mha'
            logging.info(f"Saving file {filename} to {upload_dir}")
            file.save(os.path.join(app.config['UPLOAD_FOLDER'], upload_dir, filename))

    task = do_segment.delay(upload_dir, '.mha')
    logging.info(f"Prediction task started with id: {task.id}")
    response = [{'request_id': task.id}, {'location': url_for('get_task_status', task_id=task.id)}]
    return jsonify(response), 202

if __name__ == '__main__':
    app.run(debug=False, host='0.0.0.0')