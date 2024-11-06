<?php
namespace App\Http\Controllers\API;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\User;
use Illuminate\Support\Facades\URL;
use App\BrainMap;
use App\Jobs\Filter;
use App\Jobs\Dataset;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use GuzzleHttp\Client;
use Validator;
use Carbon\Carbon;
use Helper;


class FilterController extends Controller
{

    /**
    * Operation getDataSet
    *
    * @return \Illuminate\Http\Response
    */

     public function getDataSet(Request $request)
     {
       $ROOT_URL =  getenv('SERVER_HOSTNAME');
       $client = new Client();
       $filter_api_url = 'http://filter:5000';
       $response = $client->get($filter_api_url . '/dataset');
       $res_status_code = $response->getStatusCode();

       if ($res_status_code == 200) {
         $res_body = $response->getBody();
         $res_array = json_decode($res_body, TRUE);

         // Save Json
         Storage::put('public/dataset/response.json', $res_body);

         $dataset_dir = '/var/www/laravel/vumc-picture-api/storage/app/public/dataset/';
         $input_file = $dataset_dir . 'response.json';

         while (!file_exists($input_file))
         {
              usleep(1000000);
         }

         // Remove old files
         if (file_exists($dataset_dir . 'probability_map.nii.gz'))
         {
             unlink($dataset_dir . 'probability_map.nii.gz');
         }

         if (file_exists($dataset_dir . 'sum_tumors_map.nii.gz'))
         {
             unlink($dataset_dir . 'sum_tumors_map.nii.gz');
         }

         // Create process array for client
         $process_array = [
             'python3',
             '/python_utils/dataset.py'
         ];

         $this->dispatch((new Dataset($process_array))->onQueue('low'));

         while (!file_exists($dataset_dir . 'probability_map.nii.gz'))
         {
             usleep(1000000);
         }

         $brain_map_url = $ROOT_URL . '/storage/dataset/';

         if (str_starts_with($brain_map_url, 'http://' . $ROOT_URL)) {

             $brain_map_url = str_replace('http://' . $ROOT_URL, 'https://' . $ROOT_URL, $brain_map_url);
         }

         unset($res_array['image_data']['probability_map']);
         unset($res_array['image_data']['sum_tumors_map']);

         $res_array['image_data']['probability_map'] = $brain_map_url . 'probability_map.nii.gz';
         $res_array['image_data']['sum_tumors_map'] = $brain_map_url . 'sum_tumors_map.nii.gz';

       } else {
         $res_array = [];
       }

       return response()->json($res_array, $res_status_code);
     }


     /**
     * Operation startFilter
     *
    * @param [string] brainMapId
     * @return \Illuminate\Http\Response
     */

    public function startFilter(Request $request, $brainMapId)
    {

      $valid_modalities = ['t1c', 't1w', 't2w', 'flair', 'binary_tumor'];

      $post_body = "";
      $filter_criteria = "";

      // Check if JSON post body is present
      if (count($request->json()->all())) {
          $post_body = $request->json()->all();

      } else {
          $error_message = [
            'error' => 'post must contain JSON body'
          ];
          return response()->json($error_message, 400);
      }

      // Check if brainMapId exists
      $brain_map = BrainMap::find($brainMapId);

      if (!$brain_map)
      {
          return response()->json(['error' => 'invalid brain_map_id'], 400);
      }

      // Check if modality is set and valid
      $modality = "";

      if(array_key_exists("modality", $post_body)) {
        if(in_array($post_body['modality'], $valid_modalities)) {
          $modality = $post_body['modality'];
        } else {
          $error_message = [
            'error' => 'invalid value for "modality", allowed values are "t1c", "t1w", "t2w", "flair" or "binary_tumor"'
          ];
          return response()->json($error_message, 400);
        }

      } else {
        $error_message = [
          'error' => 'post must contain "modality", being either "t1c", "t1w", "t2w", "flair" or "binary_tumor"'
        ];
        return response()->json($error_message, 400);
      }

      // Set modality file
      $base_path = '/var/www/laravel/vumc-picture-api/storage/app/public/h/' . $brainMapId . '/';
      $filter_base_path = '/var/www/laravel/vumc-picture-api/storage/app/public/h/' . $brainMapId . '-filter/';

      if(!is_dir($filter_base_path)) {
          Storage::disk('local')->makeDirectory('public/h/' . $brainMapId . '-filter');
      }

      $modality_file_mapping = [
        't1c' => 'images_AtlasHD_T1c.nii',
        't1w' => 'images_AtlasHD_T1w.nii',
        't2w' => 'images_AtlasHD_T2w.nii',
        'flair' => 'images_AtlasHD_FLR.nii',
        'binary_tumor' => 'images_AtlasHD_segmentation.nii'
      ];

      $modality = $base_path . $modality_file_mapping[$modality];

      // Create process array for client
      $process_array = [
          'python3',
          '/python_utils/filter.py',
          $modality,
          $brainMapId,
      ];

      // Check if filter_criteria are present and valid
      if(array_key_exists("filter_criteria", $post_body)) {
          $filter_criteria = $post_body['filter_criteria'];
      }

      if($filter_criteria != "") {

          $client = new Client();
          $filter_api_url = 'http://filter:5000';
          $response = $client->get($filter_api_url . '/filter_options');

          $res_status_code = $response->getStatusCode();

          if ($res_status_code == 200) {
            $res_body = $response->getBody();
            $res_array = json_decode($res_body, TRUE);

          } else {
            $error_message = [
              'error' => 'upstream error'
            ];
            return response()->json($error_message, $res_status_code);
          }

          $filter_options = $res_array['filter_options'];

          foreach ($filter_criteria as $key => $val) {
            if(!in_array($key, array_values($filter_options))) {
              $error_message = [
                'error' => $key . ' not a valid filter column'
              ];
              return response()->json($error_message, 400);
            }
          }

          if(file_exists($filter_base_path . 'filter_criteria.json')) {
              unlink($filter_base_path . 'filter_criteria.json');
          }

          // Save filter_criteria to JSON file
          file_put_contents(
            $filter_base_path . 'filter_criteria.json',
            json_encode($filter_criteria)
          );

          $filter_criteria = $filter_base_path . 'filter_criteria.json';
          array_push($process_array, $filter_criteria);

      }

      // Remove previous filter data
      if(file_exists($filter_base_path . 'filtered_probability_map.nii')) {
          unlink($filter_base_path . 'filtered_probability_map.nii');
      }

      if(file_exists($filter_base_path . 'filtered_sum_tumors_map.nii')) {
          unlink($filter_base_path . 'filtered_sum_tumors_map.nii');
      }

      if(file_exists($filter_base_path . 'filtered_output.json')) {
          unlink($filter_base_path . 'filtered_output.json');
      }

      // Create empty output file to signal start of filtering process
      touch($filter_base_path . 'filtered_output.json');

      // Start filter process
      $this->dispatch((new Filter($process_array))->onQueue('low'));

      return response()->json(['status' => 'OK'], 200);
    }


     /**
     * Operation getResults
     *
     * @param [string] brainMapId
     * @return \Illuminate\Http\Response
     */

    public function getResults(Request $request, $brainMapId)
    {
      // Check if brainMapId exists
      $brain_map = BrainMap::find($brainMapId);
      $ROOT_URL =  getenv('SERVER_HOSTNAME');
      if (!$brain_map)
      {
          return response()->json(['error' => 'invalid brain_map_id'], 400);
      }

      $base_path = '/var/www/laravel/vumc-picture-api/storage/app/public/h/' . $brainMapId . '/';
      $filter_base_path = '/var/www/laravel/vumc-picture-api/storage/app/public/h/' . $brainMapId . '-filter/';

      if(file_exists($filter_base_path . 'filtered_output.json')) {
        $filtered_output = file_get_contents($filter_base_path . 'filtered_output.json');

        if($filtered_output == '') {
          return response()->json(['status' => 'pending'], 200);
        } else {
            # Format result data
            $filtered_output = json_decode($filtered_output, TRUE);

            if(array_key_exists("error", $filtered_output)) {
                return response()->json($filtered_output, 400);
            }

            $res_array = [
              'status' => 'OK',
              'result' => []
            ];

            $brain_map_url = $ROOT_URL . '/storage/h/' . $brainMapId . '-filter/';

            if (str_starts_with($brain_map_url, 'http://tool.' . getenv('SERVER_HOSTNAME'))) {

                $brain_map_url = str_replace('http://tool.' . getenv('SERVER_HOSTNAME'),  getenv('SERVER_HOSTNAME'), $brain_map_url);
            }

            $res_array['result']['image_data'] = $filtered_output;
            $res_array['result']['probability_map'] = $brain_map_url . 'filtered_probability_map.nii';
            $res_array['result']['sum_tumors_map'] = $brain_map_url . 'filtered_sum_tumors_map.nii';

            return response()->json($res_array, 200);
        }

      } else {
        return response()->json(['error' => 'no filtering request found'], 400);
      }
    }


     /**
     * Operation getFilterOptions
     *
     * @return \Illuminate\Http\Response
     */

    public function getFilterOptions(Request $request)
    {

      $client = new Client();
      $filter_api_url = 'http://filter:5000';
      $response = $client->get($filter_api_url . '/filter_options');

      $res_status_code = $response->getStatusCode();

      if ($res_status_code == 200) {
        $res_body = $response->getBody();
        $res_array = json_decode($res_body, TRUE);
      } else {
        $res_array = [];
      }

      return response()->json($res_array, $res_status_code);
    }
}
