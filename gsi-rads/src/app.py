#!flask/bin/python
import time
import os
import json
import uuid
import shutil
from flask import Flask, request, url_for, jsonify, send_from_directory
from celery import Celery
import subprocess

UPLOAD_FOLDER = '/uploads'

ALLOWED_EXTENSIONS = set(['nii', 'gz', 'h5'])

app = Flask(__name__)
app.config['UPLOAD_FOLDER'] = UPLOAD_FOLDER
app.config['CELERY_BROKER_URL'] = 'redis://redis:6379/1'
app.config['CELERY_RESULT_BACKEND'] = 'redis://redis:6379/1'

celery = Celery(app.name, broker=app.config['CELERY_BROKER_URL'])
celery.conf.update(app.config)


def allowed_file(filename):
    return '.' in filename and \
           filename.rsplit('.', 1)[1].lower() in ALLOWED_EXTENSIONS


@celery.task(bind=True)
def gsi_rads_process(self, upload_dir, file_names):

    file_paths = {}

    for k, v in file_names.items():
        file_path = os.path.join(app.config['UPLOAD_FOLDER'], upload_dir, v)

        if os.path.isfile(file_path):
            file_paths[k] = file_path

    out_dir = '/gsi_rads/out/' + upload_dir
    out_file_json = out_dir + "/output.json"
    out_file_xlsx = out_dir + "/output.xlsx"
    subprocess.run("python main_ac.py " + file_paths['T1C'] + " " + file_paths['Segmentation'] + " " + file_paths['Composite'] + " " + file_paths['InverseComposite'] + " " + out_file_json + " " + out_file_xlsx, shell=True)

    while not (os.path.isfile(out_file_json) and os.path.isfile(out_file_xlsx)):
        self.update_state(state='PROGRESS')
        time.sleep(2)

    result = {'upload_dir': upload_dir}

    return {'status': 'task completed',
            'result': result}


def gsi_rads_process_sync(upload_dir, file_names):

    file_paths = {}

    for k, v in file_names.items():
        file_path = os.path.join(app.config['UPLOAD_FOLDER'], upload_dir, v)

        if os.path.isfile(file_path):
            file_paths[k] = file_path

    out_dir = '/gsi_rads/out/' + upload_dir
    out_file_json = out_dir + "/output.json"
    out_file_xlsx = out_dir + "/output.xlsx"

    return ["python main_ac.py " + file_paths['T1C'] + " " + file_paths['Segmentation'] + " " + file_paths['Composite'] + " " + file_paths['InverseComposite'] + " " + out_file_json + " " + out_file_xlsx]

    #while not os.path.isfile(out_file):
    #    time.sleep(2)

    #shutil.rmtree(app.config['UPLOAD_FOLDER'] + "/" + str(upload_dir))

    #result = {'upload_dir': upload_dir}

    #return {'status': 'task completed',
    #        'result': result}


@app.route('/downloads/<path:path>')
def send_files(path):
    return send_from_directory('/gsi_rads/out/', path)


@app.route('/remove/<upload_dir>', methods=['DELETE'])
def remove_(upload_dir):

    shutil.rmtree('/gsi_rads/out/' + str(upload_dir))

    response = {'status': 'OK'}
    return jsonify(response), 200


@app.route('/status/<task_id>', methods=['GET'])
def get_task_status(task_id):

    task = gsi_rads_process.AsyncResult(task_id)

    if task.state == 'PENDING':
        response = {
            'state': task.state,
            'status': 'Pending...'
        }
    elif task.state != 'FAILURE':
        response = {
            'state': task.state,
            'status': task.info.get('status', '')
        }
        if 'result' in task.info:
            response['result'] = task.info['result']
    else:
        response = {
            'state': task.state,
            'status': str(task.info),
        }
    return jsonify(response), 200



@app.route('/process', methods=['POST'])
def do_process():

    file_types = ['T1C', 'Segmentation', 'Composite', 'InverseComposite']
    upload_dir = str(uuid.uuid4())
    os.makedirs(app.config['UPLOAD_FOLDER'] + '/' + upload_dir, exist_ok=True)
    os.makedirs('/gsi_rads/out/' + upload_dir, exist_ok=True)

    file_names = {}

    for ft in file_types:
        file = request.files[ft]

        if file and allowed_file(file.filename):

            filename = ft + '.nii'

            if file.filename.endswith('gz'):
                filename = filename + '.gz'

            file_names[ft] = filename

            file.save(os.path.join(app.config['UPLOAD_FOLDER'], upload_dir, filename))

    if not 'T1C' in file_names.keys():
        response = {'error': 'missing T1C input'}

        return jsonify(response), 400

    if not 'Segmentation' in file_names.keys():
        response = {'error': 'missing Segmentation input'}

        return jsonify(response), 400

    if not 'Composite' in file_names.keys():
        response = {'error': 'missing Composite input'}

        return jsonify(response), 400

    if not 'InverseComposite' in file_names.keys():
        response = {'error': 'missing InverseComposite input'}

        return jsonify(response), 400

    task = gsi_rads_process.delay(upload_dir, file_names)

    response = {'location': url_for('get_task_status', task_id=task.id)}

    return jsonify(response), 202


if __name__ == '__main__':
    app.run(debug=False, host='0.0.0.0')
