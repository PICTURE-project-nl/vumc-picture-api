<?php

namespace App\Console\Commands;

use App\Notifications\BrainMapExpirationWarning;
use App\Notifications\BrainMapExpired;
use Illuminate\Console\Command;
use App\BrainMap;
use App\User;
use App\Upload;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;


class CorrectBrainMapURLs extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'brain_maps:correct';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Correct the URLs for processed brain_maps';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {

      $uploads = Upload::whereNotNull('auto_segmentation')->where('process_state', 'finalized')->get();

      foreach($uploads as $upload) {
          $auto_segmentation = $upload->auto_segmentation;
          $probability_map_url = $auto_segmentation['probabilityMapFileURL'];
          $sum_tumors_map_url = $auto_segmentation['nrOfPatientsFileURL'];

          $auto_segmentation['probabilityMapFileURL'] = str_replace('images_AtlasHD_segmentation.nii', 'probability_map.nii', $probability_map_url);
          $auto_segmentation['nrOfPatientsFileURL'] = str_replace('images_AtlasHD_segmentation.nii', 'sum_tumors_map.nii', $sum_tumors_map_url);

          $upload->auto_segmentation = $auto_segmentation;
          $upload->save();

          $brain_map_id = $upload->brain_map_id;
          $brain_map = BrainMap::where('id', $brain_map_id)->first();

          if($brain_map) {
              $high_res_brain_map = $brain_map->high_res_brain_map;
              $low_res_brain_map = $brain_map->low_res_brain_map;

              $high_res_brain_map['probabilityMapFileURL'] = str_replace('images_AtlasHD_segmentation.nii', 'probability_map.nii', $probability_map_url);
              $high_res_brain_map['probabilityMapFileURL'] = str_replace('.gz', '', $high_res_brain_map['probabilityMapFileURL']);

              $high_res_brain_map['nrOfPatientsFileURL'] = str_replace('images_AtlasHD_segmentation.nii', 'sum_tumors_map.nii', $sum_tumors_map_url);
              $high_res_brain_map['nrOfPatientsFileURL'] = str_replace('.gz', '', $high_res_brain_map['nrOfPatientsFileURL']);

              $low_res_brain_map['probabilityMapFileURL'] = str_replace('images_AtlasHD_segmentation.nii', 'probability_map.nii', $probability_map_url);
              $low_res_brain_map['probabilityMapFileURL'] = str_replace('.gz', '', $low_res_brain_map['probabilityMapFileURL']);

              $low_res_brain_map['nrOfPatientsFileURL'] = str_replace('images_AtlasHD_segmentation.nii', 'sum_tumors_map.nii', $sum_tumors_map_url);
              $low_res_brain_map['nrOfPatientsFileURL'] = str_replace('.gz', '', $low_res_brain_map['nrOfPatientsFileURL']);

              $brain_map->high_res_brain_map = $high_res_brain_map;
              $brain_map->low_res_brain_map = $low_res_brain_map;

              $brain_map->save();

          }


      }
    }
}
