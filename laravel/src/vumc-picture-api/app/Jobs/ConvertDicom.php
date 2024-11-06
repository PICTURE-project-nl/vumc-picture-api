<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Storage;
use Madzipper;
use App\Jobs\CreateSlice;
use Illuminate\Support\Facades\URL;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;


class ConvertDicom implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private $upload;
    private $brain_map;

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
    public $timeout = 900;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct($upload, $brain_map)
    {
        $this->upload = $upload;
        $this->brain_map = $brain_map;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $storage_dir = '/var/www/laravel/vumc-picture-api/storage/app/';
        $ROOT_URL =  getenv('SERVER_HOSTNAME');

        $process = new Process(['python3', '/python_utils/virusscan_file.py', $storage_dir . 'dicom-unprocessed/' . $this->upload->id . '.zip']);
        try {
            $process->mustRun(); // This will throw an exception if the process fails

            $output = trim($process->getOutput()); // Get the output and trim any whitespace

            // Check if the output is 'true'
            echo $output;
            if ($output === 'true') {
                echo "Virus scan did not find a virus.";
            } else {
                echo "VIRUS SCAN FOUND A VIRUS!!!.";
                throw new ProcessFailedException($process);
                die;
            }

        } catch (ProcessFailedException $exception) {
            // Handle the error here
            echo "Virus scan script did not run successfully: " . $exception->getMessage();
            die;
        }


        Madzipper::make($storage_dir . 'dicom-unprocessed/' . $this->upload->id . '.zip')->extractTo($storage_dir . 'dicom-unprocessed/' . $this->upload->id);

        $scan_dirs = array_diff(scandir($storage_dir . 'dicom-unprocessed/' . $this->upload->id), array('..', '.', '__MACOSX'));
        $scan_dir = '';

        if (!$scan_dir)
        {
            # TODO throw an exception
        }

        $dicom_in_dir = $storage_dir . 'dicom-unprocessed/' . $this->upload->id . '/' . $scan_dir;
        $niix_out_dir = $storage_dir . 'public/nifti/' . $this->brain_map->id;
        mkdir($niix_out_dir, 0755, false);

        $nii_files = array_merge(glob($dicom_in_dir . '/**/*.nii.gz'), glob($dicom_in_dir . '/**/*.nii'),glob($dicom_in_dir . '/*.nii.gz'), glob($dicom_in_dir . '/*.nii'));
        foreach ($nii_files as $nii_file)
        {
            $file_extension = pathinfo($nii_file)['extension'];
            if ($file_extension == 'gz')
            {
                $process = new Process(array(
                    "gzip", '-d', $nii_file));
                $process->run();
                $nii_file = strtr($nii_file, array('.gz' => ''));
            }

            $sanitized_nifti_file = strtr(basename($nii_file), array('(' => '', ')' => ''));


            $process = new Process(array(
                    "mv", $nii_file, $niix_out_dir . '/' . $sanitized_nifti_file));
            $process->run();
        }

        $process = new Process(array('dcm2niix', '-f', '%p_%z', '-i', 'y', '-b', 'n', '-o', $niix_out_dir, $dicom_in_dir));
        $process->run();

        if ((!$process->isSuccessful()) && empty($nii_files)){
            throw new ProcessFailedException($process);
        }

        Storage::delete('dicom-unprocessed/' . $this->upload->id . '.zip');
        Storage::deleteDirectory('dicom-unprocessed/' . $this->upload->id);

        $nifti_files = array_diff(scandir($niix_out_dir), array('..', '.', '__MACOSX'));
        mkdir($niix_out_dir . '/slices', 0755, false);

        $nifti_metadata = [];
        $Id = 0;

        foreach ($nifti_files as $nifti_file)
        {
            $sanitized_nifti_file = strtr($nifti_file, array('(' => '', ')' => ''));

            if ($sanitized_nifti_file != $nifti_file)
            {
                $process = new Process(array(
                    "mv", $niix_out_dir . '/' . $nifti_file, $niix_out_dir . '/' . $sanitized_nifti_file));
                $process->run();

                if (!$process->isSuccessful()) {
                    throw new ProcessFailedException($process);
                }

                $nifti_file = $sanitized_nifti_file;
            }

            $file_extension = pathinfo($nifti_file)['extension'];

            if ($file_extension != 'nii')
            {
                Storage::delete('public/nifti/' . $this->upload->id . '/' . $nifti_file);
                continue;
            }

            $Id = $Id + 1;
            $file_path_nifti = $niix_out_dir . '/' . $nifti_file;
            $nifti_file_base_name = basename($nifti_file, '.nii');

            $file_name_jpg = $nifti_file_base_name . '.jpg';
            $slice_dir = $niix_out_dir . '/slices/';

            $file_size = filesize($file_path_nifti);
            $file_size = round($file_size / 1024, 2);

            dispatch((new CreateSlice($file_path_nifti, $slice_dir, $file_name_jpg, $this->brain_map->id))->onQueue('high'));

            $nifti_file_data = [
                'fileId' => $Id,
                'fileName' => $nifti_file,
                'fileSize' => $file_size,
                'niftiFileURL' => 'http://' . $ROOT_URL . '/storage/nifti/' . $this->brain_map->id . '/' . $nifti_file,
                'sliceFileURL' => 'http://' . $ROOT_URL . '/storage/nifti/' . $this->brain_map->id . '/slices/' . $file_name_jpg
            ];

            array_push($nifti_metadata, $nifti_file_data);

        }

        $this->upload->nifti_metadata = $nifti_metadata;
        $this->upload->save();

        Madzipper::make($niix_out_dir . '.zip')->add($niix_out_dir)->close();
        $this->upload->anonymized_nifti_file_url = $ROOT_URL . '/storage/nifti/' . $this->brain_map->id . '.zip';
        $this->upload->process_state = 'dicom-uploaded';
        $this->upload->save();
    }
}
