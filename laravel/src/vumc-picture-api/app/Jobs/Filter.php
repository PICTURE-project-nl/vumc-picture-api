<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Storage;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\URL;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;


class Filter implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private $process_array;

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
    public $timeout = 120;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct($proces_array)
    {
        $this->process_array = $proces_array;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {

        $process = new Process($this->process_array);
        $process->setTimeout(90);
        $process->start();

        while ($process->isRunning())
        {
            usleep(1000000);
        }

        if (!$process->isSuccessful()) {
          $brain_map_id = $this->process_array[3];
          $filter_base_path = '/var/www/laravel/vumc-picture-api/storage/app/public/h/' . $brain_map_id . '-filter/';

          $filtered_output_file = $filter_base_path . '/filtered_output.json';

          $error_msg = ["error" => "filtering failed, please try again"];
          $json_error_msg = json_encode($error_msg);

          file_put_contents($json_error_msg, $filtered_output_file);
        }
    }
}
