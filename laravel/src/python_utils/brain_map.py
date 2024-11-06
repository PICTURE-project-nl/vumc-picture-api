#!/usr/bin/env python
# -*- coding: utf-8 -*-

import sys
import os
import click
import SimpleITK as sitk
import numpy as np
import shutil


@click.command()
@click.argument('image', required=True)
def update_brain_map(image):

    dataset_dir = '/var/www/laravel/vumc-picture-api/storage/app/public/dataset/'

    if (image.endswith("sum_tumors_map.nii")):

        research_probability_map = dataset_dir + 'sum_tumors_map.nii.gz'

        if not os.path.exists(research_probability_map):
            sys.exit(1)

        research_map_img = sitk.ReadImage(research_probability_map)
        input_file_path = os.path.dirname(image) + "/"

    if (image.endswith("probability_map.nii")):

        research_sum_tumors_map = dataset_dir + 'probability_map.nii.gz'

        if not os.path.exists(research_sum_tumors_map):
            sys.exit(1)

        research_map_img = sitk.ReadImage(research_sum_tumors_map)
        input_file_path = os.path.dirname(image) + "/"

    binary_input_mask = input_file_path + "transforms_binary_map.nii.gz"

    if not os.path.isfile(binary_input_mask):
        binary_input_mask = input_file_path + "images_Atlas_segmentation.nii"

    if not os.path.isfile(binary_input_mask):
        binary_input_mask = input_file_path + "images_AtlasHD_segmentation.nii"

    binary_input_mask_img = sitk.ReadImage(binary_input_mask)

    binary_input_mask_array = sitk.GetArrayFromImage(binary_input_mask_img)
    research_map_array = sitk.GetArrayFromImage(research_map_img)

    updated_array = np.where(binary_input_mask_array == 0, -1, research_map_array)

    updated_image = sitk.GetImageFromArray(updated_array)
    updated_image.SetOrigin(binary_input_mask_img.GetOrigin())
    updated_image.SetSpacing(binary_input_mask_img.GetSpacing())
    updated_image.SetDirection(binary_input_mask_img.GetDirection())

    sitk.WriteImage(updated_image, image)


if __name__ == '__main__':
    update_brain_map()
