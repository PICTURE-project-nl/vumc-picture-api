#!/usr/bin/env python
# -*- coding: utf-8 -*-

import sys
import os
import click
import SimpleITK as sitk
import numpy as np
import base64
import requests
import shutil
import time
import json


@click.command()
@click.argument('image', required=True)
@click.argument('brain_map_id', required=True)
@click.argument('filter_criteria', required=False, default={})
def get_filter_results(image, brain_map_id, filter_criteria):

    img = sitk.ReadImage(image)

    temp_mha_dir = '/tmp/mha/' + str(brain_map_id) + '/'

    if not os.path.exists(temp_mha_dir):
        os.makedirs(temp_mha_dir)

    sitk.WriteImage(img, temp_mha_dir + 'img.mha')
    img_mha = open(temp_mha_dir + 'img.mha', 'rb')
    img_content = base64.encodestring(img_mha.read()).decode('utf-8')
    img_mha.close()

    filter_criteria_file_name = None

    if isinstance(filter_criteria, str):
        if os.path.exists(filter_criteria):
            filter_criteria_file_name = filter_criteria
            filter_criteria_file = open(filter_criteria_file_name, 'r')
            filter_criteria = json.load(filter_criteria_file)
            filter_criteria_file.close()

    request_data = {
        'input_image': img_content,
        'filter_criteria': filter_criteria
    }

    res = requests.post('http://filter:5000/filter', json=request_data)
    res.raise_for_status()

    res_dict = res.json()
    status_location = res_dict['location']

    processed_status = False

    while not processed_status:
        status_res = requests.get('http://filter:5000' + status_location)
        status_res.raise_for_status()

        status_res_dict = status_res.json()

        if status_res_dict:

            if status_res_dict['state'] == 'FAILURE':

                sys.exit(1)

            elif status_res_dict['state'] == 'PENDING':

               time.sleep(1)

            elif status_res_dict['state'] == 'SUCCESS':
                processed_status = True

                img_data = status_res_dict['result']['image_data']
                brain_map_dir = '/var/www/laravel/vumc-picture-api/storage/app/public/h/' + str(brain_map_id) + '/'
                filter_dir = '/var/www/laravel/vumc-picture-api/storage/app/public/h/' + str(brain_map_id) + '-filter/'

                # Save probability_map to brain_map_dir
                base64_encoded_probability_map = img_data['probability_map']
                probability_map_content = base64.b64decode(base64_encoded_probability_map)

                with open(temp_mha_dir + 'filtered_probability_map.mha', 'wb') as w:
                    w.write(probability_map_content)

                probability_map_img = sitk.ReadImage(temp_mha_dir + 'filtered_probability_map.mha')
                probability_map_array = sitk.GetArrayFromImage(probability_map_img)

                binary_input_mask_image = sitk.ReadImage(brain_map_dir + "images_AtlasHD_segmentation.nii")
                binary_input_mask_array = sitk.GetArrayFromImage(binary_input_mask_image)

                probability_map_array = np.where(binary_input_mask_array == 0, -1, probability_map_array)

                probability_map_img_output = sitk.GetImageFromArray(probability_map_array)
                probability_map_img_output.CopyInformation(probability_map_img)

                sitk.WriteImage(probability_map_img_output, filter_dir + 'filtered_probability_map.nii')

                # Save sum_tumpors_map to brain_map_dir
                base64_encoded_sum_tumors_map = img_data['sum_tumors_map']
                sum_tumors_map_content = base64.b64decode(base64_encoded_sum_tumors_map)

                with open(temp_mha_dir + 'filtered_sum_tumors_map.mha', 'wb') as w:
                    w.write(sum_tumors_map_content)

                sum_tumors_map_img = sitk.ReadImage(temp_mha_dir + 'filtered_sum_tumors_map.mha')
                sum_tumors_map_array = sitk.GetArrayFromImage(sum_tumors_map_img)

                sum_tumors_map_img_output = sitk.GetImageFromArray(sum_tumors_map_array)
                sum_tumors_map_img_output.CopyInformation(sum_tumors_map_img)

                sitk.WriteImage(sum_tumors_map_img_output, filter_dir + 'filtered_sum_tumors_map.nii')

                # Remove MHA volumes from API JSON output and save the output as file
                result_img_data = img_data
                result_img_data.pop('probability_map')
                result_img_data.pop('sum_tumors_map')

                with open(filter_dir + 'filtered_output.json', 'w') as w:
                    json.dump(result_img_data, w, indent=4)

                if filter_criteria_file_name:
                    if os.path.exists(filter_criteria_file_name):
                        os.remove(filter_criteria_file_name)


    shutil.rmtree(temp_mha_dir)


if __name__ == '__main__':
    get_filter_results()
