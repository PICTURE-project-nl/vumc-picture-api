#!/usr/bin/env python
# -*- coding: utf-8 -*-

import sys
import os
import click
import SimpleITK as sitk
import base64
import shutil
import time
import json


@click.command()
def get_dataset():

    dataset_dir = '/var/www/laravel/vumc-picture-api/storage/app/public/dataset/'
    input_file = dataset_dir + 'response.json'

    json_file = open(input_file)
    response_dict = json.load(json_file)
    dataset = response_dict['image_data']
    json_file.close()

    temp_mha_dir = '/tmp/mha/dataset/'

    if not os.path.exists(temp_mha_dir):
        os.makedirs(temp_mha_dir)

    # Save probability_map to dataset_dir
    base64_encoded_probability_map = dataset['probability_map']
    probability_map_content = base64.b64decode(base64_encoded_probability_map)

    with open(temp_mha_dir + 'probability_map.mha', 'wb') as w:
        w.write(probability_map_content)

    probability_map_img = sitk.ReadImage(temp_mha_dir + 'probability_map.mha')
    sitk.WriteImage(probability_map_img, dataset_dir + 'probability_map.nii.gz')

    # Save sum_tumpors_map to brain_map_dir
    base64_encoded_sum_tumors_map = dataset['sum_tumors_map']
    sum_tumors_map_content = base64.b64decode(base64_encoded_sum_tumors_map)

    with open(temp_mha_dir + 'sum_tumors_map.mha', 'wb') as w:
        w.write(sum_tumors_map_content)

    sum_tumors_map_img = sitk.ReadImage(temp_mha_dir + 'sum_tumors_map.mha')
    sitk.WriteImage(sum_tumors_map_img, dataset_dir + 'sum_tumors_map.nii.gz')

    shutil.rmtree(temp_mha_dir)


if __name__ == '__main__':
    get_dataset()
