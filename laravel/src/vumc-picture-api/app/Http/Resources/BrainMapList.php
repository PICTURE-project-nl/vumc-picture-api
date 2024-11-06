<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use App\BrainMap;
use App\Folder;
use App\Http\Resources\BrainMap as BrainMapResource;
use App\Http\Resources\Folder as FolderResource;

class BrainMapList extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function toArray($request)
    {
        return [
            'brainMaps' => BrainMapResource::collection($this),
            'folders' => FolderResource::collection(Folder::findMany($this->pluck('folder_id')->toArray()))
        ];
    }
}
