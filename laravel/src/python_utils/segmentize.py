#!/usr/bin/env python
# -*- coding: utf-8 -*-

import sys
import os
import click
import SimpleITK as sitk
import base64
import requests
import shutil
import time


@click.command()
@click.argument('t1c', required=True)
@click.argument('t1w', required=True)
@click.argument('t2w', required=True)
@click.argument('flair', required=True)
@click.argument('brain_map_id', required=True)
def get_prediction(t1c, t1w, t2w, flair, brain_map_id):

    t1c_img = sitk.ReadImage(t1c)
    t1w_img = sitk.ReadImage(t1w)
    t2w_img = sitk.ReadImage(t2w)
    flair_img = sitk.ReadImage(flair)

    temp_mha_dir = '/tmp/mha/' + str(brain_map_id) + '/'

    if not os.path.exists(temp_mha_dir):
        os.makedirs(temp_mha_dir)

    sitk.WriteImage(t1c_img, temp_mha_dir + 't1c.mha')
    t1c_mha = open(temp_mha_dir + 't1c.mha', 'rb')
    t1c_content = t1c_mha.read()
    t1c_mha.close()

    sitk.WriteImage(t1w_img, temp_mha_dir + 't1w.mha')
    t1w_mha = open(temp_mha_dir + 't1w.mha', 'rb')
    t1w_content = t1w_mha.read()
    t1w_mha.close()

    sitk.WriteImage(t2w_img, temp_mha_dir + 't2w.mha')
    t2w_mha = open(temp_mha_dir + 't2w.mha', 'rb')
    t2w_content = t2w_mha.read()
    t2w_mha.close()

    sitk.WriteImage(flair_img, temp_mha_dir + 'flair.mha')
    flair_mha = open(temp_mha_dir + 'flair.mha', 'rb')
    flair_content = flair_mha.read()
    flair_mha.close()

    img_items = {
        't1ce': t1c_content,
        't1': t1w_content,
        't2': t2w_content,
        'flair': flair_content,
        'alt_convention': False
    }

    res = requests.post('http://segmentation:9001/predict', files=img_items)
    res.raise_for_status()

    res_dict = res.json()
    request_id = res_dict[0]['request_id']
    status_item = {'request_id': request_id}

    processed_status = False

    while not processed_status:
        status_res = requests.post('http://segmentation:9001/responses', data=status_item)
        status_res.raise_for_status()

        status_res_dict = status_res.json()

        if len(status_res_dict) == 2:

            if status_res_dict[1]['label'] == 'Error!':

                sys.exit(1)

            if status_res_dict[1]['type'] == 'LabelVolume':
                processed_status = True

                base64_encoded_content = status_res_dict[1]['content']
                segmentation_content = base64.b64decode(base64_encoded_content)

                with open(temp_mha_dir + 'segmentation.mha', 'wb') as w:
                    w.write(segmentation_content)

                segmentation_img = sitk.ReadImage(temp_mha_dir + 'segmentation.mha')
                segmentation_out_dir = '/var/www/laravel/vumc-picture-api/storage/app/public/l/' + str(brain_map_id) + '/'
                sitk.WriteImage(segmentation_img, segmentation_out_dir + 'segmentation.nii')

        time.sleep(10)

    shutil.rmtree(temp_mha_dir)


if __name__ == '__main__':
    get_prediction()
