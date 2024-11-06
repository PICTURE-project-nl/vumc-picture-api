# -*- coding: utf-8 -*-
"""
Created on Wed Nov 21 13:54:46 2018

Author: picture
"""

import os

# Set the maximum number of CPUs to use, leaving 2 for other processes
MAX_CPUS = max([os.cpu_count() - 2, 1])
os.environ['ITK_GLOBAL_DEFAULT_NUMBER_OF_THREADS'] = str(MAX_CPUS)

import shutil
import ants
import subprocess
import json
import SimpleITK as sitk
import time

# Function to get the size of memory available
def getMemSize():
    mem_bytes = os.sysconf('SC_PAGE_SIZE') * os.sysconf('SC_PHYS_PAGES')
    mem_gib = mem_bytes / (1024.**3)
    mem_gib = mem_gib - 1  # Leave 1 GiB free
    return mem_gib

# Function to check if GPU resources are available
# Function to check if GPU resources are available
def useGPUResource():
    try:
        subprocess.check_output(["nvidia-smi", "-L"]).decode('utf-8').count('UUID')
        return True
    except FileNotFoundError as e:
        print(f"nvidia-smi not found: {e}")
        return False
    except subprocess.CalledProcessError as e:
        print(f"Error checking GPU resources: {e}")
        return False

# Function to perform registration of T1c images to MNI atlas with affine transformation without skull stripping
# Function to perform registration of T1c images to MNI atlas with affine transformation without skull stripping
def do_registration_to_mni_affine(fname, wdir, atlas_path='./common/mni_1mm.nii.gz'):
    """
    Load selected T1c scan from path. Normalize, and register (SyN) without skull stripping.
    Save interim NIfTI files and output registration.
    """
    import torch

    # Set the number of threads for PyTorch
    torch.set_num_threads(MAX_CPUS)

    # Read the input image
    im = ants.image_read(fname)
    scantype = 'T1c'
    atlas = ants.image_read(atlas_path)

    # Normalize the image by subtracting the minimum value
    norm_fname = wdir + f'/in/{scantype}_NORM.nii.gz'
    os.makedirs(os.path.split(norm_fname)[0], exist_ok=True)
    im_norm = im - im.min()
    ants.image_write(im_norm, norm_fname)

    # Since the image is already skull stripped, use the normalized image directly
    strip_fname = norm_fname  # No skull stripping performed, use the normalized file

    # Check if the normalized file was created
    if not os.path.isfile(strip_fname):
        raise RuntimeError(f"Normalized file {strip_fname} not found")

    # Read the stripped image
    im_strip = ants.image_read(strip_fname)
    im_mask = im_strip > 0
    mask_fname = wdir + f'/in/{scantype}_STRIPPED_mask.nii.gz'
    ants.image_write(im_mask, mask_fname)

    outputs = {'transforms': {}, 'images': {}}

    # Perform affine registration
    transform_fname = wdir + f'/reg/{scantype}_to_mni.mat'
    os.makedirs(os.path.split(transform_fname)[0], exist_ok=True)
    im_transform = ants.registration(fixed=atlas, moving=im_norm, mask=im_strip,
                                     type_of_transform='Affine', aff_iterations=(1000, 1000, 1000, 50),
                                     aff_shrink_factors=(8, 4, 2, 1))

    # Save the forward transformation matrix
    shutil.copy(im_transform['fwdtransforms'][0], transform_fname)
    outputs['transforms']['T1c_to_Atlas'] = transform_fname
    outputs['transforms']['Atlas_to_T1c'] = transform_fname

    # Save the brain mask in MNI space
    outputs['images']['T1c_BrainMask'] = mask_fname
    outputs['images']['Atlas_BrainMask'] = apply_registration(
        mask_fname, atlas_path, im_transform['fwdtransforms'], wdir, "brainmask_in_mni-space", interp='nearestNeighbor')

    # Save the T1c image in MNI space
    outputs['images']['Atlas_T1c'] = apply_registration(fname, atlas_path, im_transform['fwdtransforms'], wdir,
                                                        "T1c_in_mni-space", brainMaskMNI=outputs['images']['Atlas_BrainMask'], interp='linear', do_n4=True, normalize=True)

    # Save the atlas image in T1c space
    outputs['images']['T1c_Atlas'] = apply_registration('/EM_Map_mask_edit_20120723.nii', fname, im_transform['fwdtransforms'],
                                                        wdir, "Atlas_in_T1c-space", brainMaskMNI=None, interp='nearestNeighbor', whichtoinvert=[True])

    return outputs



# Function to perform deformable registration of T1c images to MNI atlas
def do_registration_to_mni_deformable(fname, seg, brainMask, affine_transform, wdir, atlas_path='./common/mni_1mm.nii.gz'):
    """
    Load selected T1c scan from path. Normalize, skull strip, and register (SyN).
    Save interim NIfTI files and output registration.
    """
    im = ants.image_read(fname)
    im_seg = ants.image_read(seg)
    im_brainMask = ants.image_read(brainMask)
    atlas = ants.image_read(atlas_path)
    scantype = fname.split('/')[-1].split('.')[0]

    # Normalize the image by subtracting the minimum value
    norm_fname = wdir + f'/in/{scantype}_NORM.nii.gz'
    os.makedirs(os.path.split(norm_fname)[0], exist_ok=True)
    im_norm = im - im.min()
    ants.image_write(im_norm, norm_fname)

    # Create a mask for segmentation
    mask_seg_fname = wdir + f'/in/{scantype}_STRIPPED_mask_seg.nii.gz'
    im_strip = im_norm * im_brainMask
    im_mask_seg = im_brainMask - im_seg
    ants.image_write(im_mask_seg, mask_seg_fname)

    # Perform deformable registration using SyN
    warp_fname = wdir + f'/transforms_T1c_to_Atlas_warp.nii.gz'
    inv_fname = wdir + f'/transforms_Atlas_to_T1c_warp.nii.gz'
    os.makedirs(os.path.split(inv_fname)[0], exist_ok=True)
    im_transform = ants.registration(fixed=atlas, moving=im_norm, mask=im_mask_seg, initial_transform=affine_transform,
                                     type_of_transform='SyNOnly', syn_metric='MI')

    # Save the forward and inverse transformations
    shutil.copy(im_transform['fwdtransforms'][0], warp_fname)
    shutil.copy(im_transform['invtransforms'][1], inv_fname)

    # Ensure that the warp and inverse files are created
    if not os.path.isfile(warp_fname) or not os.path.isfile(inv_fname):
        raise RuntimeError(f"Deformable registration failed, warp or inverse files not found")

    return warp_fname, inv_fname

# Function to perform affine registration of other modalities to T1c
def do_registration_to_T1c(fname, T1c_path, wdir):
    """
    Perform affine registration of other modalities to T1c (mask is the skullstripped mask excluding tumor segmentation).
    """
    im = ants.image_read(fname)
    scantype = fname.split('/')[-1].split('.')[0]
    atlas = ants.image_read(T1c_path)

    # Perform affine registration
    transform_fname = wdir + f'/reg/{scantype}_to_T1c.mat'
    os.makedirs(os.path.split(transform_fname)[0], exist_ok=True)
    im_transform = ants.registration(
        fixed=atlas, moving=im, type_of_transform='Affine')

    # Save the transformation matrix
    shutil.copy(im_transform['fwdtransforms'][0], transform_fname)
    fnames = {'transforms': {'transformlist': im_transform,
                             'transformmat': transform_fname}}

    # Check if the transformation matrix was created
    if not os.path.isfile(transform_fname):
        raise RuntimeError(f"Affine registration failed, transformation matrix not found")

    return fnames

# Function to apply transformations to an image
def apply_registration(moving, fixed, fwdtransforms, wdir, name, brainMaskMNI=None, interp='linear', whichtoinvert=[], do_n4=False, normalize=False):
    """
    Apply transformations to an image.
    """
    if not(type(fwdtransforms) == list):
        fwdtransforms = [fwdtransforms]

    # Read the moving image
    moving = ants.image_read(moving)

    # Apply N4 bias field correction if needed
    if do_n4:
        moving = ants.n4_bias_field_correction(moving)

    # Read the fixed image
    fixed = ants.image_read(fixed)

    # Apply transformations
    reg_fname = wdir + f'/reg/{name}.nii.gz'
    if whichtoinvert:
        im_reg = ants.apply_transforms(fixed=fixed, moving=moving,
                                       transformlist=fwdtransforms,
                                       interpolator=interp,
                                       whichtoinvert=whichtoinvert)
    else:
        im_reg = ants.apply_transforms(fixed=fixed, moving=moving,
                                       transformlist=fwdtransforms,
                                       interpolator=interp)

    # Apply brain mask if provided
    if brainMaskMNI:
        im_brainMaskMNI = ants.image_read(brainMaskMNI)
        im_reg = im_reg * im_brainMaskMNI

        # Normalize the image if needed
        if normalize:
            mean = im_reg.numpy()[im_brainMaskMNI.numpy() > 0.5].mean()
            std = im_reg.numpy()[im_brainMaskMNI.numpy() > 0.5].std()
            im_reg = (im_reg - mean) / std

    # Save the registered image
    ants.image_write(im_reg, reg_fname)

    # Check if the registered image was created
    if not os.path.isfile(reg_fname):
        raise RuntimeError(f"Applying registration failed, registered image {reg_fname} not found")

    return reg_fname

# Main function to perform registration of multiple modalities
def _do_reg(*, T1c='', T1w='', T2w='', Flr='', upload_dir=''):
    """
    Perform registration of multiple modalities.
    """
    secondaryScans = {'T1w': T1w, 'T2w': T2w, 'FLR': Flr}

    # Check if T1c file exists
    if not(os.path.isfile(T1c)):
        raise ValueError('T1c not found')
    else:
        # Perform registration of T1c to Atlas
        outputs = do_registration_to_mni_affine(
            T1c, '/uploads/' + upload_dir, atlas_path='/MNI152_T1_1mm.nii.gz')

    outputs['images'].update({'T1c': T1c, 'T1w': T1w, 'T2w': T2w, 'FLR': Flr})

    # Perform registration of secondary modalities to T1c
    regs = {}
    for name, scan in secondaryScans.items():
        if not(os.path.isfile(scan)):
            print(f'Not found: {scan}')
        else:
            fnames = do_registration_to_T1c(scan, T1c, '/uploads/' + upload_dir)
            regs[name] = fnames['transforms']['transformmat']

    # Apply combined transform from secondary-->T1c-->Atlas
    for name, reg in regs.items():
        outputs['transforms'][name + '_to_T1c'] = reg
        outputs['transforms']['T1c_to_' + name] = reg
        outputs['images']['Atlas_' + name] = apply_registration(secondaryScans[name], '/MNI152_T1_1mm.nii.gz', [reg, outputs['transforms']['T1c_to_Atlas']],
                                                                '/uploads/' + upload_dir, f"{name}_in_mni-space", brainMaskMNI=outputs['images']['Atlas_BrainMask'], interp='linear', do_n4=True, normalize=True)

    # Move files from /tmp/ to /wdir/out/
    for img_trans in outputs.keys():
        for ftype, fname in outputs[img_trans].items():
            ext = '.nii' if img_trans == 'images' else '.mat'
            fname_new = f"/{img_trans}_{ftype}" + ext
            os.makedirs('/wdir/out/' + upload_dir, exist_ok=True)
            if fname.endswith('.nii.gz'):
                sitk.WriteImage(sitk.ReadImage(fname), '/wdir/out/' + upload_dir + fname_new)
            else:
                shutil.copy(fname, '/wdir/out/' + upload_dir + fname_new)
            outputs[img_trans][ftype] = '/wdir/out/' + upload_dir + fname_new

    outFile = '/wdir/out/' + upload_dir + '/outputs.json'
    json.dump(outputs, open(outFile, 'w'))

    # Check if the output file was created
    if not os.path.isfile(outFile):
        raise RuntimeError(f"Output file {outFile} not found")

    return outFile

# High-definition registration function
def _do_reg_hd(*, inputs={}, upload_dir=''):
    """
    Perform high-definition registration.
    """
    atlas = '/MNI152_T1_1mm.nii.gz'

    # Apply registration to T1c segmentation
    inputs['images']['T1c_segmentation'] = apply_registration(inputs['images']['Atlas_segmentation'], inputs['images']['T1c'], inputs['transforms']['T1c_to_Atlas'],
                                                              '/uploads/' + upload_dir, f"segmentation_in_T1c-space", brainMaskMNI=None, interp='nearestNeighbor', do_n4=False, normalize=False, whichtoinvert=[True])

    # Perform deformable registration
    warp_fname, inv_fname = do_registration_to_mni_deformable(inputs['images']['T1c'], inputs['images']['T1c_segmentation'], inputs['images']['T1c_BrainMask'],
                                                              inputs['transforms']['T1c_to_Atlas'], upload_dir, atlas_path='/MNI152_T1_1mm.nii.gz')
    inputs['transforms']['T1c_to_Atlas_WARP'] = warp_fname
    inputs['transforms']['Atlas_to_T1c_WARP'] = inv_fname
    t1c_to_atlas_transformlist = [inputs['transforms']['T1c_to_Atlas_WARP'], inputs['transforms']['T1c_to_Atlas']]

    # Apply registration to high-definition images
    inputs['images']['AtlasHD_T1c'] = apply_registration(inputs['images']['T1c'], atlas, t1c_to_atlas_transformlist,
                                                         '/uploads/' + upload_dir, f"T1c_in_AtlasHD-space", brainMaskMNI=inputs['images']['Atlas_BrainMask'], interp='linear', do_n4=False, normalize=False, whichtoinvert=[False, False])
    inputs['images']['AtlasHD_segmentation'] = apply_registration(inputs['images']['T1c_segmentation'], atlas, t1c_to_atlas_transformlist,
                                                                  '/uploads/' + upload_dir, f"segmentation_in_AtlasHD-space", brainMaskMNI=inputs['images']['Atlas_BrainMask'], interp='nearestNeighbor', do_n4=False, normalize=False)

    # Apply registration to secondary modalities
    for mod in ['T1w', 'T2w', 'FLR']:
        inputs['images'][f'AtlasHD_{mod}'] = apply_registration(inputs['images'][mod], atlas, [inputs['transforms'][f'{mod}_to_T1c']] + t1c_to_atlas_transformlist,
                                                                '/uploads/' + upload_dir, f"{mod}_in_AtlasHD-space", brainMaskMNI=inputs['images']['Atlas_BrainMask'], interp='linear', do_n4=False, normalize=False)

    # Move files from /tmp/ to /wdir/out/
    outputs = inputs
    for img_trans in outputs.keys():
        for ftype, fname in outputs[img_trans].items():
            if img_trans == 'images':
                ext = '.nii'
            elif fname.endswith('.mat'):
                ext = '.mat'
            elif fname.endswith('.nii.gz'):
                ext = '.nii.gz'
            elif fname.endswith('.nii'):
                ext = '.nii'
            fname_new = f"/{img_trans}_{ftype}" + ext
            print([fname, fname_new])
            os.makedirs('/wdir/out/' + upload_dir, exist_ok=True)
            if (img_trans == 'images') and fname.endswith('.nii.gz'):
                sitk.WriteImage(sitk.ReadImage(fname), '/wdir/out/' + upload_dir + fname_new)
                if not(os.path.abspath(fname) == os.path.abspath('/wdir/out/' + upload_dir + fname_new)):
                    os.remove(fname)
            else:
                if not(os.path.abspath(fname) == os.path.abspath('/wdir/out/' + upload_dir + fname_new)):
                    shutil.copy(fname, '/wdir/out/' + upload_dir + fname_new)
                    os.remove(fname)
            outputs[img_trans][ftype] = '/wdir/out/' + upload_dir + fname_new

    outFile = '/wdir/out/' + upload_dir + '/' + 'transform_outputs.json'
    json.dump(outputs, open(outFile, 'w'))

    # Check if the output file was created
    if not os.path.isfile(outFile):
        raise RuntimeError(f"Output file {outFile} not found")

    return outFile