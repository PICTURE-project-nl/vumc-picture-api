<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use App\BrainMap;
use App\Folder;

class Upload extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function toArray($request)
    {

        if (!$this->brain_map_id)
        {
            return [
                'uploadId' => $this->id,
                'anonymizedNiftiFileURL' => $this->anonymized_nifti_file_url,
                'niftiMetadata' => $this->nifti_metadata,
                'processState' => $this->process_state,
                'autoSegmentation' => $this->auto_segmentation
            ];
        }
        else {
            $brain_map = BrainMap::find($this->brain_map_id);

            if ($brain_map->folder_id){
                $folder = Folder::find(BrainMap::find($this->brain_map_id)->first()->folder_id)->first();
                $folder_name = $folder->name;
            } else
            {
                $folder_name = null;
            }

            return [
                'uploadId' => $this->id,
                'brainMapId' => $this->brain_map_id,
                'age' => $brain_map->age,
                'GBM' => $brain_map->GBM,
                'brainMapName' => $brain_map->name,
                'brainMapNotes' => $brain_map->brain_map_notes,
                'brainMapClassification' => $brain_map->brain_map_classification,
                'glioma' => $brain_map->glioma,
                'patientAmount' => $brain_map->patient_amount,
                'sharedBrainMap' => $brain_map->shared_brain_map,
                'isResearch' => $brain_map->is_research,
                'folderId' => $brain_map->folder_id,
                'folderName' => $folder_name,
                'mriDate' => $brain_map->mri_date,
                'anonymizedNiftiFileURL' => $this->anonymized_nifti_file_url,
                'niftiMetadata' => $this->nifti_metadata,
                'processState' => $this->process_state,
                'autoSegmentation' => $this->auto_segmentation
            ];
        }
    }
}
