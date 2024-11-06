<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use App\Folder;

class BrainMap extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function toArray($request)
    {

        if ($this->folder_id)
        {
            $folder_name = Folder::find($this->folder_id)->first()->name;
        }
        else {
            $folder_name = '';
        }

        $gsi_rads_xlsx_url = str_replace("public", "storage", $this->gsi_rads_xlsx_url);
        $gsi_rads_xlsx_url = str_replace("localhost/localhost", "localhost", $gsi_rads_xlsx_url); // Extra check voor dubbele localhost

        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'brainMapName' => $this->name,
            'age' => $this->age,
            'GBM' => $this->GBM,
            'brainMapNotes' => $this->brain_map_notes,
            'brainMapClassification' => $this->brain_map_classification,
            'glioma' => $this->glioma,
            'patientAmount' => $this->patient_amount,
            'sharedBrainMap' => $this->shared_brain_map,
            'isResearch' => $this->is_research,
            'folderId' => $this->folder_id,
            'folderName' => $folder_name,
            'expectedResidualVolume' => $this->expected_residual_volume,
            'expectedResectabilityIndex' => $this->expected_resectability_index,
            'mriDate' => $this->mri_date,
            'lowResBrainMap' => $this->low_res_brain_map,
            'highResBrainMap' => $this->high_res_brain_map,
            'gsiRads' => $this->gsi_rads,
            'gsiRadsXLSXURL' => $gsi_rads_xlsx_url,
            'T1cSliceFileURL' => $this->T1c_slice_file_url,
            'T1wSliceFileURL' => $this->T1w_slice_file_url,
            'T2wSliceFileURL' => $this->T2w_slice_file_url,
            'FLAIRSliceFileURL' => $this->FLAIR_slice_file_url,
            'uploadDate' => $this->created_at
        ];
    }
}
