<?php
namespace App\Http\Controllers\API;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\User;
use Illuminate\Support\Facades\URL;
use App\BrainMap;
use App\Http\Resources\BrainMap as BrainMapResource;
use App\Http\Resources\BrainMapList as BrainMapListResource;
use App\Upload;
use App\Http\Resources\Upload as UploadResource;
use App\Folder;
use App\Jobs\ConvertDicom;
use App\Jobs\Register;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Validator;
use Carbon\Carbon;
use Helper;


class BrainMapController extends Controller
{

     /**
     * Operation getUploadState
     *
     * @return \Illuminate\Http\Response
     */

    public function getUploadState(Request $request)
    {
      $user = Auth::user();
      $upload = Upload::where('user_id', $user->id)->
      whereDate('updated_at', '>=', Carbon::now()->subHours(6))->where
      ('process_state', '!=', 'finalized')->orderBy('created_at', 'DESC')->first();

      if ($upload)
      {
          return new UploadResource($upload);
      }

      return response()->json([], 200);
    }

    /**
    * Operation uploadBrainMapFile
    *
    * @param [resource] file
    * @return \Illuminate\Http\Response
    */

   public function uploadBrainMapFile(Request $request)
   {

     $validator = Validator::make($request->all(), [
         'file' => 'required|max:500000|mimes:zip',
     ]);

     if ($validator->fails())
     {
         return response()->json(['error'=>$validator->errors()], 400);
     }

     $user = Auth::user();
     $upload = Upload::create([
         'user_id'=> $user->id,
         'process_state' => 'dicom-upload'
     ]);

     $visited_at = Carbon::now()->format('Y-m-d H:i:s');

     $brain_map = BrainMap::create([
         'user_id'=> $user->id,
         'slug' => 'null',
         'visited_at' => $visited_at,
         'notified' => false
     ]);

     $folder = Folder::firstOrCreate(['name' => 'My brain maps']);
     $brain_map->folder_id = $folder->id;

     $brain_map->save();

     $upload->brain_map_id = $brain_map->id;
     $upload->save();

     Storage::disk('local')->putFileAs('dicom-unprocessed/',  $request->file,  $upload->id . '.zip');
     $this->dispatch((new ConvertDicom($upload, $brain_map))->onQueue('low'));

     return New UploadResource($upload);
   }

   /**
   * Operation deleteUpload
   *
   * @param [string] uploadId
   * @return \Illuminate\Http\Response
   */

   public function deleteUpload(Request $request, $uploadId)
   {
     $validator = Validator::make(['uploadId' => $uploadId], [
         'uploadId' => 'required|size:36',
     ]);

     if ($validator->fails())
     {
         return response()->json(['error'=>$validator->errors()], 400);
     }

     $user = Auth::user();
     $upload = Upload::find($request->uploadId);

     if (!$upload)
     {
         return response()->json(['error'=>'requested object could not be found'], 404);
     }

     if ($user->id != $upload->user_id)
     {
        return response()->json(['error'=>'not authorized to modify this object'], 401);
     }

     Storage::deleteDirectory('dicom-unprocessed/' . $upload->id);
     Storage::delete('dicom-unprocessed/' . $upload->id . '.zip');

     $brain_map = BrainMap::find($upload->brain_map_id);
     if ($brain_map)
     {
         Storage::deleteDirectory('public/nifti/' . $brain_map->id);
         Storage::delete('public/nifti/' . $brain_map->id . '.zip');
         Storage::deleteDirectory('public/l/' . $brain_map->id);
         Storage::deleteDirectory('public/h/' . $brain_map->id);
         Storage::delete('public/l/' . $brain_map->id . '.zip');
         Storage::delete('public/h/' . $brain_map->id . '.zip');
         $upload->brain_map_id = 'null';
         $brain_map->delete();
     }

     $upload->delete();
     return response()->json(['uploadId'=>$request->uploadId], 200);
   }


    /**
     * Operation segmentBrainMapFile
     *
     * @param [boolean] applyAutoSegmentation
     * @return \Illuminate\Http\Response
     */

    public function segmentBrainMapFile(Request $request, $uploadId)
    {

        $request['uploadId'] = $uploadId;

        if ($request->has('applyAutoSegmentation')) {
            if ($request['applyAutoSegmentation'] == 'true') {
                $request['applyAutoSegmentation'] = true;
            }
            elseif ($request['applyAutoSegmentation'] == 'false') {
                $request['applyAutoSegmentation'] = false;
            }
        }

        $validator = Validator::make($request->all(), [
            'selectedT1cFileId' => 'sometimes|required|integer',
            'selectedT1wFileId' => 'sometimes|required|integer',
            'selectedT2wFileId' => 'sometimes|required|integer',
            'selectedFLAIRFileId' => 'sometimes|required|integer',
            'applyAutoSegmentation' => 'required_with_all:selectedT1cFileId,selectedT1wFileId,selectedT2wFileId,selectedFLAIRFileId|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 400);
        }

        $user = Auth::user();
        $upload = Upload::find($request->uploadId);

        if (!$upload)
        {
            return response()->json(['error'=>'requested object could not be found'], 404);
        }

        if ($user->id != $upload->user_id)
        {
            return response()->json(['error'=>'not authorized to modify this object'], 401);
        }

        $auto_segmentation_possible = false;

        if ($request->has('selectedT1cFileId')) {
            $selected_t1c_file_id = $request['selectedT1cFileId'];
            $auto_segmentation_possible = true;
        } else {
            $auto_segmentation_possible = false;
        }

        if ($request->has('selectedT1wFileId')) {
            $selected_t1w_file_id = $request['selectedT1wFileId'];
            if ($auto_segmentation_possible == true)
            {
                $auto_segmentation_possible = true;
            }
        } else {
            $auto_segmentation_possible = false;
        }

        if ($request->has('selectedT2wFileId')) {
            $selected_t2w_file_id = $request['selectedT2wFileId'];
            if ($auto_segmentation_possible == true)
            {
                $auto_segmentation_possible = true;
            }
        } else {
            $auto_segmentation_possible = false;
        }

        if ($request->has('selectedFLAIRFileId')) {
            $selected_flair_file_id = $request['selectedFLAIRFileId'];
            if ($auto_segmentation_possible == true)
            {
                $auto_segmentation_possible = true;
            }
        } else {
            $auto_segmentation_possible = false;
        }


        if ($request->has('applyAutoSegmentation'))
        {
            if ($request['applyAutoSegmentation'] == true)
            {
                if ($auto_segmentation_possible == true)
                {
                    $brain_map = BrainMap::find($upload->brain_map_id);

                    $visited_at = Carbon::now()->format('Y-m-d H:i:s');
                    $brain_map->visited_at = $visited_at;
                    $brain_map->notified = false;
                    $brain_map->save();

                    if (!$brain_map)
                    {
                        return response()->json(['error'=>'corresponding brain map could not be found'], 404);
                    }

                    $nifti_metadata = $upload->nifti_metadata;
                    $nifti_files = [];

                    foreach ($nifti_metadata as $nifti_item)
                    {
                        if ($nifti_item['fileId'] == $selected_t1c_file_id)
                        {
                            $nifti_files['selected_t1c_file'] = $nifti_item['fileName'];
                            $brain_map->T1c_slice_file_url = $nifti_item['sliceFileURL'];
                        }
                        if ($nifti_item['fileId'] == $selected_t1w_file_id)
                        {
                            $nifti_files['selected_t1w_file'] = $nifti_item['fileName'];
                            $brain_map->T1w_slice_file_url = $nifti_item['sliceFileURL'];
                        }
                        if ($nifti_item['fileId'] == $selected_t2w_file_id)
                        {
                            $nifti_files['selected_t2w_file'] = $nifti_item['fileName'];
                            $brain_map->T2w_slice_file_url = $nifti_item['sliceFileURL'];
                        }
                        if ($nifti_item['fileId'] == $selected_flair_file_id)
                        {
                            $nifti_files['selected_flair_file'] = $nifti_item['fileName'];
                            $brain_map->FLAIR_slice_file_url = $nifti_item['sliceFileURL'];
                        }
                        $brain_map->save();
                    }

                    $segmentize = true;
                    $this->dispatch((new Register($upload, $brain_map, $nifti_files, $segmentize, $user))->onQueue('low'));
                }
                else {
                    return response()->json(['error'=>'auto segmentation requires four classification file ids'], 400);
                }
            }
        }

        return new UploadResource($upload);
    }

   /**
   * Operation uploadSegmentedBrainMapFile
   *
   * @param [resource] file
   * @return \Illuminate\Http\Response
   */

   public function uploadSegmentedBrainMapFile(Request $request)
   {
     $validator = Validator::make($request->all(), [
         'file' => 'required|max:100000|mimes:dicom',
     ]);

     if ($validator->fails())
     {
         return response()->json(['error'=>$validator->errors()], 400);
     }

     $user = Auth::user();
     $upload = Upload::where('user_id', $user->id)->
     whereDate('updated_at', '>=', Carbon::now()->subHours(6))->where
     ('process_state', '!=', 'finalized')->orderBy('created_at', 'DESC')->first();

     if (!$upload)
     {
       $upload = Upload::create([
           'user_id'=> $user->id,
           'process_state' => 'segmented-upload'
       ]);

       $upload->user_id = $user->id;
       $brain_map = BrainMap::create(['user_id'=> $user->id]);
       $folder = Folder::firstOrCreate(['name' => 'My brain maps']);
       $brain_map->folder_id = $folder->id;
       $brain_map->slug = 'null';
       $brain_map->save();
       $upload->brain_map_id = $brain_map->id;
       $upload->save();
     } else
     {
       $upload->process_state = 'segmented-upload';

       $brain_map = BrainMap::find($upload->brain_map_id);

       if (!$brain_map)
       {
           $brain_map = BrainMap::create(['user_id'=> $user->id]);
           $brain_map->slug = 'null';
           $brain_map->save();
           $upload->brain_map_id = $brain_map->id;
       }
       $upload->save();
     }

     Storage::disk('local')->putFileAs('nifti-segmented/',  $request->file,  $upload->id . '.zip');

     $visited_at = Carbon::now()->format('Y-m-d H:i:s');
     $brain_map->visited_at = $visited_at;
     $brain_map->notified = false;
     $brain_map->save();

     return response()->json(['uploadId'=>$request->uploadId], 200);
   }

   /**
   * Operation updateSegmentedUploadBrainMapFileInfo
   *
   * @param [string] uploadId
   * @param [integer] age
   * @param [string] GBM
   * @param [string] brainMapClassification
   * @param [boolean] sharedBrainMap
   * @param [boolean] isResearch
   * @param [string] brainMapName
   * @param [string] folderName
   * @param [string] brainMapNotes
   * @param [string] mriDate

   * @return \Illuminate\Http\Response
   */

   public function updateSegmentedUploadBrainMapFileInfo(Request $request, $uploadId)
   {
     $request['uploadId'] = $uploadId;

     if ($request->has('sharedBrainMap'))
     {
       if ($request['sharedBrainMap'] == 'true')
       {
         $request['sharedBrainMap'] = true;
       } elseif ($request['sharedBrainMap'] == 'false')
       {
         $request['sharedBrainMap'] = false;
       }
     }

     $user = Auth::user();

     if ($request->has('isResearch'))
     {
         if ($request['isResearch'] == 'true')
         {
             if($user->super_user == false)
             {
                 return response()->json(['error' => 'must be superuser to create research brain map'], 400);
             } else
             {
                 $request['isResearch'] = true;
             }
         } elseif ($request['isResearch'] == 'false')
         {
             $request['isResearch'] = false;
         }
     }

     $validator = Validator::make($request->all(), [
         'uploadId' => 'required|size:36',
         'age' => 'sometimes|required|integer|between:0,150',
         'GBM' => 'sometimes|required|max:60',
         'brainMapClassification' => 'sometimes|required|max:60',
         'sharedBrainMap' => 'sometimes|required|boolean',
         'isResearch' => 'sometimes|required|boolean',
         'brainMapName' => 'sometimes|required|max:60',
         'folderName' => 'sometimes|required|max:255',
         'brainMapNotes' => 'sometimes|required|max:255',
         'mriDate' => 'sometimes|required|date',
     ]);

     if ($validator->fails())
     {
         return response()->json(['error'=>$validator->errors()], 400);
     }

     $upload = Upload::find($request->uploadId);

     if (!$upload)
     {
         return response()->json(['error'=>'requested object could not be found'], 404);
     }

     if ($user->id != $upload->user_id)
     {
        return response()->json(['error'=>'not authorized to modify this object'], 401);
     }

     $brain_map = BrainMap::where('id', $upload->brain_map_id)->first();

     if ($request->has('age'))
     {
         $brain_map->age = $request->age;
     }
     if ($request->has('GBM'))
     {
         $brain_map->GBM = $request->GBM;
     }
     if ($request->has('brainMapClassification'))
     {
         $brain_map->brain_map_classification = $request->brainMapClassification;
     }
     if ($request->has('sharedBrainMap'))
     {
         $brain_map->shared_brain_map = $request->sharedBrainMap;
     }
     if ($request->has('isResearch'))
     {
         $brain_map->is_research = $request->isResearch;
     }
     if ($request->has('brainMapName'))
     {
         $brain_map->name = $request->brainMapName;

         if ($brain_map->is_research == true)
         {
             $brain_map->slug = Helper::slugify($brain_map->name);
         }
     }
     if ($request->has('folderName'))
     {
         $folder = Folder::firstOrCreate(['name' => $request->folderName]);
         $brain_map->folder_id = $folder->id;
     }

     if ($request->has('brainMapNotes'))
     {
         $brain_map->brain_map_notes = $request->brainMapNotes;
     }
     if ($request->has('mriDate'))
     {
         $brain_map->mri_date = $request->mriDate;
     }

     $visited_at = Carbon::now()->format('Y-m-d H:i:s');
     $brain_map->visited_at = $visited_at;
     $brain_map->notified = false;
     $brain_map->save();

     if ($upload->process_state == 'segmented-upload')
     {
         $upload->process_state = 'finalized';
     }

     $upload->save();

     return new UploadResource($upload);

   }

   /**
   * Operation deleteSegmentedUpload
   *
   * @param [string] uploadId
   * @return \Illuminate\Http\Response
   */

   public function deleteSegmentedUpload(Request $request, $uploadId)
   {
     $validator = Validator::make(['uploadId' => $uploadId], [
         'uploadId' => 'required|size:36',
     ]);

     if ($validator->fails())
     {
         return response()->json(['error'=>$validator->errors()], 400);
     }

     $user = Auth::user();
     $upload = Upload::find($request->uploadId);

     if (!$upload)
     {
         return response()->json(['error'=>'requested object could not be found'], 404);
     }

     if ($user->id != $upload->user_id)
     {
        return response()->json(['error'=>'not authorized to modify this object'], 401);
     }

     Storage::delete('dicom-unprocessed/' . $upload->id);
     $brain_map = BrainMap::find($upload->brain_map_id);
     if ($brain_map)
     {
       Storage::deleteDirectory('public/nifti/' . $brain_map->id);
       Storage::delete('public/nifti/' . $brain_map->id . '.zip');
       Storage::deleteDirectory('public/l/' . $brain_map->id);
       Storage::deleteDirectory('public/h/' . $brain_map->id);
       Storage::delete('public/l/' . $brain_map->id . '.zip');
       Storage::delete('public/h/' . $brain_map->id . '.zip');
       $upload->brain_map_id = 'null';
       $brain_map->delete();
     }

     $upload->delete();
     return response()->json(['uploadId'=>$request->uploadId], 200);
   }

   /**
   * Operation getBrainMapList
   *
   * @return \Illuminate\Http\Response
   */

   public function getBrainMapList(Request $request)
   {

       if(!Auth::guard('api')->check())
       {
           $brain_maps = BrainMap::where('is_research', true)->where('folder_id', '!=', 'null')->get();
       } else
       {
           $user = Auth::guard('api')->User();

           $brain_map_ids_user = BrainMap::where('user_id', $user->id)
               ->where('folder_id', '!=', 'null')->pluck('id')->toArray();
           $brain_map_ids_shared = BrainMap::where('user_id', '!=', $user->id)
               ->where('shared_brain_map', true)->where('folder_id', '!=', 'null')->pluck('id')->toArray();
           $brain_map_ids_research = BrainMap::where('user_id', '!=', $user->id)
               ->where('shared_brain_map', false)->where('is_research', true)
               ->where('folder_id', '!=', 'null')->pluck('id')->toArray();

           $brain_map_ids = array_merge($brain_map_ids_user, $brain_map_ids_shared, $brain_map_ids_research);
           $brain_maps = BrainMap::whereIn('id', $brain_map_ids)->get();

       }

       return new BrainMapListResource($brain_maps);
   }

   /**
   * Operation getBrainMapMetadata
   *
   * @param [string] param
   * @return \Illuminate\Http\Response
   */

   public function getBrainMapMetadata(Request $request, $param)
   {

     $validator = Validator::make(['param' => $param,], [
         'param' => 'required|string',
     ]);

     if ($validator->fails())
     {
         return response()->json(['error'=>$validator->errors()], 400);
     }

     if ($param == 'null')
     {
         return response()->json(['error'=>'requested object could not be found'], 404);
     } else
     {
         $brain_map = BrainMap::where('id', $param)->orWhere('slug', $param)->first();
     }

     if (!$brain_map)
     {
         return response()->json(['error'=>'requested object could not be found'], 404);
     }

     if (!Auth::guard('api')->check())
     {
         if ($brain_map->is_research == false)
         {
             return response()->json(['error'=>'not authorized to view this object'], 401);
         } else
         {
             return new BrainMapResource($brain_map);
         }
     }

     $user = Auth::guard('api')->User();

     if ($user->id != $brain_map->user_id)
     {
        if ($brain_map->shared_brain_map != true)
        {
          return response()->json(['error'=>'not authorized to view this object'], 401);
        }
     }

     $visited_at = Carbon::now()->format('Y-m-d H:i:s');
     $brain_map->visited_at = $visited_at;
     $brain_map->notified = false;
     $brain_map->save();

     return new BrainMapResource($brain_map);
   }

   /**
   * Operation setBrainMapMetadata
   *
   * @param [string] param
   * @param [integer] age
   * @param [string] GBM
   * @param [string] brainMapClassification
   * @param [boolean] sharedBrainMap
   * @param [boolean] isResearch
   * @param [string] brainMapName
   * @param [string] folderName
   * @param [string] brainMapNotes
   * @param [string] mriDate
   * @return \Illuminate\Http\Response
   */

   public function setBrainMapMetadata(Request $request, $param)
   {
     $request['param'] = $param;

     if ($request->has('sharedBrainMap'))
     {
       if ($request['sharedBrainMap'] == 'true')
       {
         $request['sharedBrainMap'] = true;
       } elseif ($request['sharedBrainMap'] == 'false')
       {
         $request['sharedBrainMap'] = false;
       }
     }

     $user = Auth::user();

     if ($request->has('isResearch'))
     {
         if ($request['isResearch'] == 'true')
         {
             if($user->super_user == false)
             {
                 return response()->json(['error' => 'must be superuser to set research brain map'], 400);
             } else
             {
                 $request['isResearch'] = true;
             }
         } elseif ($request['isResearch'] == 'false')
         {
             $request['isResearch'] = false;
         }
     }

     $validator = Validator::make($request->all(), [
         'param' => 'required|string',
         'age' => 'sometimes|required|integer|between:0,150',
         'GBM' => 'sometimes|required|max:60',
         'brainMapClassification' => 'sometimes|required|max:60',
         'sharedBrainMap' => 'sometimes|required|boolean',
         'isResearch' => 'sometimes|required|boolean',
         'brainMapName' => 'sometimes|required|max:60',
         'folderName' => 'sometimes|required|max:255',
         'brainMapNotes' => 'sometimes|required|max:255',
         'mriDate' => 'sometimes|required|date',
     ]);

     if ($validator->fails())
     {
         return response()->json(['error'=>$validator->errors()], 400);
     }

     if ($param == 'null')
     {
         return response()->json(['error'=>'requested object could not be found'], 404);
     } else
     {
         $brain_map = BrainMap::where('id', $param)->orWhere('slug', $param)->firstOrFail();
     }

     if (!$brain_map)
     {
         return response()->json(['error'=>'requested object could not be found'], 404);
     }

     if (!$user->id == $brain_map->user_id)
     {
        return response()->json(['error'=>'not authorized to modify this object'], 401);
     }

     if ($request->has('age'))
     {
         $brain_map->age = $request->age;
     }
     if ($request->has('GBM'))
     {
         $brain_map->GBM = $request->GBM;
     }
     if ($request->has('brainMapClassification'))
     {
         $brain_map->brain_map_classification = $request->brainMapClassification;
     }
     if ($request->has('sharedBrainMap'))
     {
         $brain_map->shared_brain_map = $request->sharedBrainMap;
     }
     if ($request->has('isResearch'))
     {
         $brain_map->is_research = $request->isResearch;
     }
     if ($request->has('brainMapName'))
     {
         $brain_map->name = $request->brainMapName;

         if ($brain_map->is_research == true)
         {
             $brain_map->slug = Helper::slugify($brain_map->name);
         }

     }
     if ($request->has('folderName'))
     {
         $folder = Folder::firstOrCreate(['name' => $request->folderName]);
         $brain_map->folder_id = $folder->id;
     }

     if ($request->has('brainMapNotes'))
     {
         $brain_map->brain_map_notes = $request->brainMapNotes;
     }
     if ($request->has('mriDate'))
     {
         $brain_map->mri_date = $request->mriDate;
     }

     $visited_at = Carbon::now()->format('Y-m-d H:i:s');
     $brain_map->visited_at = $visited_at;
     $brain_map->notified = false;
     $brain_map->save();

     return new BrainMapResource($brain_map);
   }

   /**
   * Operation deleteBrainMap
   *
   * @param [string] param
   * @return \Illuminate\Http\Response
   */

   public function deleteBrainMap(Request $request, $param)
   {
     $validator = Validator::make(['param' => $param,], [
         'param' => 'required|string',
     ]);

     if ($validator->fails())
     {
         return response()->json(['error'=>$validator->errors()], 400);
     }

     $user = Auth::user();

     if ($param == 'null')
     {
         return response()->json(['error'=>'requested object could not be found'], 404);
     } else
     {
         $brain_map = BrainMap::where('id', $param)->orWhere('slug', $param)->first();
     }

     if (!$brain_map)
     {
         return response()->json(['error'=>'requested object could not be found'], 404);
     }

     if ($user->id != $brain_map->user_id)
     {
        return response()->json(['error'=>'not authorized to view this object'], 401);
     }

     $upload = Upload::where('brain_map_id', $brain_map->id)->first();

     if ($upload)
     {
       Storage::deleteDirectory('dicom-unprocessed/' . $upload->id);
       $upload->delete();
     }

     Storage::deleteDirectory('public/nifti/' . $brain_map->id);
     Storage::delete('public/nifti/' . $brain_map->id . '.zip');
     Storage::deleteDirectory('public/l/' . $brain_map->id);
     Storage::deleteDirectory('public/h/' . $brain_map->id);
     Storage::delete('public/l/' . $brain_map->id . '.zip');
     Storage::delete('public/h/' . $brain_map->id . '.zip');



     $brain_map->delete();

     return new BrainMapResource($brain_map);
   }
}
