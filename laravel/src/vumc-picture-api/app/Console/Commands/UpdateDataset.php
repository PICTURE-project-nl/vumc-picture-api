<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\User;
use Illuminate\Support\Facades\URL;
use App\BrainMap;
use App\Jobs\Filter;
use App\Jobs\Dataset;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use GuzzleHttp\Client;

use Helper;


class UpdateDataset extends Command
{

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'dataset:update';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Download research dataset';

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
    */

     public function handle()
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

           //$this->dispatch((new Dataset($process_array))->onQueue('low'));
           Dataset::dispatch($process_array)->onQueue('low');

           while (!file_exists($dataset_dir . 'probability_map.nii.gz'))
           {
               usleep(1000000);
           }

           print_r("Dataset downloaded");

         } else {
           print_r("Failed downloading dataset");
         }

     }

}
