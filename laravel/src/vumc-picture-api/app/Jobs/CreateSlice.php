<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;


class CreateSlice implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private $file_path_nifti;
    private $slice_dir;
    private $file_name_jpg;
    private $brain_map_id;

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
    public $timeout = 30;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct($file_path_nifti, $slice_dir, $file_name_jpg, $brain_map_id)
    {
        $this->file_path_nifti = $file_path_nifti;
        $this->slice_dir = $slice_dir;
        $this->file_name_jpg = $file_name_jpg;
        $this->brain_map_id = $brain_map_id;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $process = new Process(array('med2image', '-d',  $this->slice_dir, '-o', $this->file_name_jpg, '-s', 'm', '-i', $this->file_path_nifti));
        $process->run();

        if (!$process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }

        $slice_files = array_diff(scandir($this->slice_dir), array('..', '.', '__MACOSX'));

        $slice_file = '';

        foreach($slice_files as $k=>$v) {

            $slice_file_name_options = [
                explode("-frame", $v)[0],
                explode("-slice", $v)[0],
            ];

            $slice_file_basepath = basename($this->file_name_jpg, '.jpg');

            foreach($slice_file_name_options as $slice_file_name)
            {
                if($slice_file_name == $slice_file_basepath) {
                    $slice_file = $v;
                    break 2;
                }
            }
        }

        if (!$slice_file)
        {
        }
        else {
            Storage::move("public/nifti/$this->brain_map_id/slices/$slice_file", "public/nifti/$this->brain_map_id/slices/$this->file_name_jpg");
        }
    }
}
