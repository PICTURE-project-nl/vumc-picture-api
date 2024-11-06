<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Storage;
use GuzzleHttp\Client;
use Madzipper;
use Illuminate\Support\Facades\URL;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Illuminate\Support\Facades\Log;


class GSIRADSAnalyze implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private $brain_map;
    private $upload;
    private $user;

    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public $tries = 1;

    /**
     * The number of seconds the job can run before timing out.
     *
     * @var int
     */
    public $timeout = 3600;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct($brain_map, $upload, $user)
    {
        $this->brain_map = $brain_map;
        $this->upload = $upload;
        $this->user = $user;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $ROOT_URL = getenv('SERVER_HOSTNAME');
        $nifti_dir = '/var/www/laravel/vumc-picture-api/storage/app/public/nifti/' . $this->brain_map->id . '/';
        $high_res_nifti_dir = '/var/www/laravel/vumc-picture-api/storage/app/public/h/' . $this->brain_map->id . '/';

        // Check if registration files are present
        $registration_files = $this->upload->auto_segmentation;

        if (!array_key_exists("registeredAtlasT1cFileURL", $registration_files)) {
            Log::error("GSI-RADS processing failed for brain_map " . $this->brain_map->id . " missing " . "registeredAtlasT1cFileURL");
            $this->fail();
        }

        if (!array_key_exists("segmentationFileURL", $registration_files)) {
            Log::error("GSI-RADS processing failed for brain_map " . $this->brain_map->id . " missing " . "segmentationFileURL");
            $this->fail();
        }

        if (!array_key_exists("registeredCompositeFileURL", $registration_files)) {
            Log::error("GSI-RADS processing failed for brain_map " . $this->brain_map->id . " missing " . "registeredCompositeFileURL");
            $this->fail();
        }

        if (!array_key_exists("registeredInverseCompositeFileURL", $registration_files)) {
            Log::error("GSI-RADS processing failed for brain_map " . $this->brain_map->id . " missing " . "registeredInverseCompositeFileURL");
            $this->fail();
        }

        // Set input files
        $T1c = basename(parse_url($registration_files['registeredAtlasT1cFileURL'], PHP_URL_PATH));
        $segmentation = basename(parse_url($registration_files['segmentationFileURL'], PHP_URL_PATH));
        $composite_transform = basename(parse_url($registration_files['registeredCompositeFileURL'], PHP_URL_PATH));
        $inverse_composite_transform = basename(parse_url($registration_files['registeredInverseCompositeFileURL'], PHP_URL_PATH));

        Log::debug($high_res_nifti_dir . $T1c);
        Log::debug($high_res_nifti_dir . $segmentation);
        Log::debug($high_res_nifti_dir . $composite_transform);
        Log::debug($high_res_nifti_dir . $inverse_composite_transform);

        $input_files = [
            [
                'name' => 'T1C',
                'contents' => fopen($high_res_nifti_dir . $T1c, 'rb'),
            ],
            [
                'name' => 'Segmentation',
                'contents' => fopen($high_res_nifti_dir . $segmentation, 'rb'),
            ],
            [
                'name' => 'Composite',
                'contents' => fopen($high_res_nifti_dir . $composite_transform, 'rb'),
            ],
            [
                'name' => 'InverseComposite',
                'contents' => fopen($high_res_nifti_dir . $inverse_composite_transform, 'rb'),
            ],
        ];

        $gsi_rads_processed = false;

	      Log::debug($input_files);

        $client = new Client();
        $gsi_rads_base_url = 'http://gsi-rads:5000';
        $response = $client->post($gsi_rads_base_url . '/process', ['multipart' => $input_files]);

        $res_status_code = $response->getStatusCode();
        $process_initialization_status_code = $res_status_code;

        if ($res_status_code == 202) {
            $res_body = $response->getBody();
            $res_array = json_decode($res_body, TRUE);

            $status_location = $res_array['location'];
            $state = '';

            while ($state != 'SUCCESS') {
                $response = $client->get($gsi_rads_base_url . $status_location);
                $res_status_code = $response->getStatusCode();
                $processing_status_code = $res_status_code;

                if ($res_status_code == 200) {
                    $res_body = $response->getBody();
                    $res_array = json_decode($res_body, TRUE);

                    $state = $res_array['state'];

                    if ($state == 'FAILED') {

                        Log::error("GSI-RADS processing failed for brain_map " . $this->brain_map->id);
                        break;
                    }

                    if ($state == 'SUCCESS') {
                        $gsi_rads_processed = true;
                        $result = $res_array['result'];
                        break;
                    }

                } else {
                    Log::error("GSI-RADS API failed with status code " . $processing_status_code .  " during processing for brain_map " . $this->brain_map->id);

                }
                sleep(5);
            }

        } else {
            Log::error("GSI-RADS API failed with status code " . $process_initialization_status_code .  " during process initialization for brain_map " . $this->brain_map->id);
        }

        if ($gsi_rads_processed == true) {
            $upload_dir = $result['upload_dir'];

            $gsi_rads_xlsx_fname = 'gsi_rads.xlsx';
            $gsi_rads_xlsx_resource = fopen($high_res_nifti_dir . $gsi_rads_xlsx_fname, 'w');
            $client->request('GET', $gsi_rads_base_url . '/downloads/' . $upload_dir . '/output.xlsx', ['sink' => $gsi_rads_xlsx_resource]);

            $this->brain_map->gsi_rads_xlsx_url = asset('/storage/h/' . $this->brain_map->id . '/' . $gsi_rads_xlsx_fname);

            $this->brain_map->save();

            $response = $client->get($gsi_rads_base_url . '/downloads/' . $upload_dir . '/output.json');

            $res_status_code = $response->getStatusCode();

            if ($res_status_code == 200) {

                $res_body = $response->getBody();
                $res_array = json_decode($res_body, TRUE);

                $this->brain_map->gsi_rads = $res_array;
                $this->brain_map->save();

            } else {
                $registered_state = false;

                Log::error("GSI-RADS API failed with status code " . $res_status_code .  " during downloading for brain_map " . $this->brain_map->id);

            }

            $client->delete($gsi_rads_base_url . '/remove/' . $upload_dir);
        }

    }
}
