<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\BrainMap;
use App\Upload;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;

use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;
use App\Jobs\BrainMapUpdate;




class UpdateBrainMaps extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'brain_maps:update';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Update brain maps with newest research dataset';

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

        $high_res_nifti_dir = '/var/www/laravel/vumc-picture-api/storage/app/public/h/';
        $uploads = Upload::whereNotNull('auto_segmentation')->where('process_state', 'finalized')->get();

        foreach($uploads as $upload) {
            $auto_segmentation = $upload->auto_segmentation;
            $upload_probability_map_url = $auto_segmentation['probabilityMapFileURL'];
            $upload_sum_tumors_map_url = $auto_segmentation['nrOfPatientsFileURL'];

            $upload_probability_map_url_array = explode('storage/h/', $upload_probability_map_url);
            $upload_probability_map_file = end($upload_probability_map_url_array);
            $upload_probability_map_file_path = $high_res_nifti_dir . $upload_probability_map_file;

            $upload_sum_tumors_map_url_array = explode('storage/h/', $upload_sum_tumors_map_url);
            $upload_sum_tumors_map_file = end($upload_sum_tumors_map_url_array);
            $upload_sum_tumors_map_file_path = $high_res_nifti_dir . $upload_sum_tumors_map_file;


            if (file_exists($upload_probability_map_file_path)) {

                if (time()-filemtime($upload_probability_map_file_path) > 7 * 24 * 3600) {
                    // file older than a week

                    $this->updateBrainMap($upload, $upload_probability_map_file_path, $upload_probability_map_url, 'probability_map');

                }
            } else {
                // file not existing yet

                $this->updateBrainMap($upload, $upload_probability_map_file_path, $upload_probability_map_url, 'probability_map');
            }

            if (file_exists($upload_sum_tumors_map_file_path)) {

                if (time()-filemtime($upload_sum_tumors_map_file_path) > 7 * 24 * 3600) {
                    // file older than a week

                    $this->updateBrainMap($upload, $upload_sum_tumors_map_file_path, $upload_sum_tumors_map_url, 'sum_tumors_map');
                }
            } else {
                // file not existing yet

                $this->updateBrainMap($upload, $upload_sum_tumors_map_file_path, $upload_sum_tumors_map_url, 'sum_tumors_map');
            }


        }

    }


    /**
     * Update the brain_map
     *
     * @return mixed
     */
    private function updateBrainMap($upload, $brain_map, $url, $map)
    {

          $brain_map = str_replace('.gz', '', $brain_map);

          $process_array = [
            'python3',
            '/python_utils/brain_map.py',
             $brain_map];

          BrainMapUpdate::dispatch($process_array, $upload, $url, $map)->onQueue('low');

    }


}
