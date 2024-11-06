import base64
import SimpleITK as sitk
import shutil
import subprocess
import os
import torch
import logging

logging.basicConfig(format='%(asctime)s - %(message)s', level=logging.INFO)

if torch.cuda.is_available():
    device_id = "cuda:0"
    logging.info('Found GPU, using cuda device 0: ' + str(torch.cuda.get_device_name(0)))
else:
    device_id = 'cpu'
    logging.warning(
        "\x1b[31;1m" + 'No GPU found, using CPU. Will be slow!!\x1b[0m (approx. 35 min. for 1 scan on a 12 core CPU)')
    torch.set_num_threads(max([os.cpu_count() - 5, 1]))

def do_segmentation(*, T1c='', T1w='', T2w='', Flr='', upload_dir=''):
    scans = {'T1c': T1c, 'T1w': T1w, 'T2w': T2w, 'FLR': Flr}
    logging.info("Load segmentation network")
    nnUNet_workdir = os.path.join(upload_dir, 'nnUNet_workdir')
    if os.path.isdir(nnUNet_workdir):
        shutil.rmtree(nnUNet_workdir)
    os.makedirs(nnUNet_workdir, exist_ok=True)

    mod_labels = {'T1c': '0000', 'T1w': '0001', 'T2w': '0002', 'FLR': '0003'}
    for mod, filepath in scans.items():
        logging.info(f"Processing modality {mod} from {filepath}")
        img = sitk.ReadImage(filepath)
        sitk.WriteImage(img, os.path.join(nnUNet_workdir, f'tmp_{mod_labels[mod]}.nii.gz'))

    if device_id == 'cpu':
        MP = ' --disable_mixed_precision'
    else:
        MP = ''

    nnUNet_cmd = f"nnUNet_predict -i {nnUNet_workdir} -o {nnUNet_workdir}/output -tr nnUNetTrainerV2 -ctr nnUNetTrainerV2CascadeFullRes -m 3d_fullres -p nnUNetPlansv2.1 -t Task101_PICTURE{MP}"
    logging.info(f"Running nnUNet command: {nnUNet_cmd}")
    try:
        subprocess.run(nnUNet_cmd.split(' '), check=True)
    except subprocess.CalledProcessError as e:
        logging.error(f"nnUNet prediction failed: {e}")
        raise

    seg_img = sitk.ReadImage(os.path.join(nnUNet_workdir, 'output/tmp.nii.gz')) > 1.5
    sitk.WriteImage(seg_img, os.path.join(upload_dir, 'Atlas_segmentation.nii.gz'))
    sitk.WriteImage(seg_img, os.path.join(upload_dir, 'Atlas_segmentation.mha'), useCompression=True)

    with open(os.path.join(upload_dir, 'Atlas_segmentation.mha'), 'rb') as f:
        vol_string = base64.encodestring(f.read()).decode('utf-8')

    shutil.rmtree(nnUNet_workdir)
    return os.path.join(upload_dir, 'Atlas_segmentation.nii.gz'), vol_string