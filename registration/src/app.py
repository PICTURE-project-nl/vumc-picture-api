#!flask/bin/python
import time
import os
import json
import uuid
import shutil
from flask import Flask, request, url_for, jsonify, send_from_directory
from celery import Celery
from doReg import _do_reg, _do_reg_hd

# Configuration: Set the folder where files will be uploaded and the allowed file extensions
UPLOAD_FOLDER = '/uploads'
ATLAS_FILE = '/EM_Map_mask_edit_20120723.nii'
ALLOWED_EXTENSIONS = set(['nii'])  # Only NIfTI files are allowed

# Initialize the Flask application
app = Flask(__name__)
app.config['UPLOAD_FOLDER'] = UPLOAD_FOLDER
app.config['CELERY_BROKER_URL'] = 'redis://redis:6379/0'  # URL for the Redis broker
app.config['result_backend'] = 'redis://redis:6379/0'  # URL for the Redis result backend

# Initialize Celery with the Flask app configuration
celery = Celery(app.name, broker=app.config['CELERY_BROKER_URL'])
celery.conf.update(app.config)

# Function to check if the uploaded file has an allowed extension
def allowed_file(filename):
    # Check if the file has an extension and if it is in the allowed extensions set
    return '.' in filename and filename.rsplit('.', 1)[1].lower() in ALLOWED_EXTENSIONS

# Celery task to handle the registration of the atlas
@celery.task(bind=True)
def register_atlas(self, upload_dir):
    # This function is kept for backward compatibility; it integrates with an external registration function
    inFile = '/wdir/out/' + upload_dir + '/outputs.json'
    inputs = json.load(open(inFile, 'r'))
    inputs['images']['Atlas_segmentation'] = os.path.join(app.config['UPLOAD_FOLDER'], upload_dir, 'segmentation.nii')

    # Log the start of the high-definition registration process
    self.update_state(state='STARTING', meta={'status': 'Starting registration with high definition'})

    try:
        # Perform the high-definition registration using an external function
        _do_reg_hd(inputs=inputs, upload_dir=upload_dir)
    except Exception as e:
        self.update_state(state='FAILURE', meta={'status': f'Registration failed: {e}'})
        raise

    result = {'upload_dir': upload_dir}
    # Log the completion of the task
    self.update_state(state='COMPLETED', meta={'status': 'Task completed', 'result': result})
    return {'status': 'task completed', 'result': result}

# Celery task to handle the main registration process
@celery.task(bind=True)
def register(self, upload_dir):
    # Define paths to the uploaded files
    T1c_path = os.path.join(app.config['UPLOAD_FOLDER'], upload_dir, 'T1c.nii')
    T1w_path = os.path.join(app.config['UPLOAD_FOLDER'], upload_dir, 'T1w.nii')
    T2w_path = os.path.join(app.config['UPLOAD_FOLDER'], upload_dir, 'T2w.nii')
    Flr_path = os.path.join(app.config['UPLOAD_FOLDER'], upload_dir, 'Flr.nii')

    # Log the start of the registration process
    self.update_state(state='STARTING', meta={'status': 'Starting registration'})

    try:
        # Perform the registration
        _do_reg(T1c=T1c_path, T1w=T1w_path, T2w=T2w_path, Flr=Flr_path, upload_dir=upload_dir)
    except Exception as e:
        self.update_state(state='FAILURE', meta={'status': f'Registration failed: {e}'})
        raise

    # Check if the output file is created and update the task state periodically
    out_file = '/wdir/out/' + upload_dir + '/outputs.json'
    while not os.path.isfile(out_file):
        self.update_state(state='PROGRESS', meta={'status': 'Waiting for output file to be created'})
        time.sleep(2)  # Wait for 2 seconds before checking again

    result = {'upload_dir': upload_dir}
    # Log the completion of the task
    self.update_state(state='COMPLETED', meta={'status': 'Task completed', 'result': result})
    return {'status': 'task completed', 'result': result}

# Endpoint to download files
@app.route('/downloads/<path:path>')
def send_files(path):
    # Log the download request
    print(f'Download request for file: {path}')
    # Send the requested file from the output directory
    return send_from_directory('/wdir/out/', path)

# Endpoint to remove uploaded files and associated output
@app.route('/remove/<upload_dir>', methods=['DELETE'])
def remove_(upload_dir):
    # Delete the directory corresponding to the given upload_dir
    shutil.rmtree('/wdir/out/' + str(upload_dir))
    # Respond with a status of OK
    response = {'status': 'OK'}
    return jsonify(response), 200

# Endpoint to get the status of a task
@app.route('/status/<task_type>/<task_id>', methods=['GET'])
def get_task_status(task_type, task_id):
    # Determine which task type we are checking the status for
    if task_type == 'register_atlas':
        task = register_atlas.AsyncResult(task_id)
    elif task_type == 'register':
        task = register.AsyncResult(task_id)

    # Check the state of the task and log the current status
    if task.state == 'PENDING':
        response = {
            'state': task.state,
            'status': 'Pending...'  # Task is still waiting to be executed
        }
    elif task.state != 'FAILURE':
        response = {
            'state': task.state,
            'status': task.info.get('status', '')  # Task is in progress or completed
        }
        if 'result' in task.info:
            response['result'] = task.info['result']  # Include the result if available
    else:
        response = {
            'state': task.state,
            'status': str(task.info),  # Task failed; include the error message
        }
    return jsonify(response), 200

# Endpoint to initiate atlas registration
@app.route('/register_atlas/<upload_dir>', methods=['POST'])
def do_register_atlas(upload_dir):
    file = request.files['segmentation']
    # Save the uploaded segmentation file
    file.save(os.path.join(app.config['UPLOAD_FOLDER'], upload_dir, 'segmentation.nii'))

    # Initiate the registration task
    task = register_atlas.delay(upload_dir)
    response = {'location': url_for('get_task_status', task_type='register_atlas', task_id=task.id)}
    return jsonify(response), 202  # Respond with a status indicating the request was accepted

# Endpoint to initiate the main registration process
@app.route('/register', methods=['POST'])
def do_register():
    classifications = ['T1c', 'T1w', 'T2w', 'Flr']
    upload_dir = str(uuid.uuid4())  # Create a unique directory name
    os.makedirs(app.config['UPLOAD_FOLDER'] + '/' + upload_dir, exist_ok=True)  # Create the directory

    # Save each uploaded file to the appropriate path
    for c in classifications:
        file = request.files[c]
        if file and allowed_file(file.filename):
            filename = c + '.nii'
            file.save(os.path.join(app.config['UPLOAD_FOLDER'], upload_dir, filename))

    # Initiate the registration task
    task = register.delay(upload_dir)
    response = {'location': url_for('get_task_status', task_type='register', task_id=task.id)}
    return jsonify(response), 202  # Respond with a status indicating the request was accepted

# Run the Flask application
if __name__ == '__main__':
    # Start the Flask app; make it accessible on all network interfaces
    app.run(debug=False, host='0.0.0.0')