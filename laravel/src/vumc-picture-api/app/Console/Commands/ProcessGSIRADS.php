<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\BrainMap;
use App\Upload;
use App\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;
use App\Jobs\GSIRADSAnalyze;


class ProcessGSIRADS extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'gsi_rads:process';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Process GSI RADS for brain maps that do not have this';

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

        $brain_maps_unprocessed = BrainMap::where('gsi_rads', null)->get();

        foreach($brain_maps_unprocessed as $brain_map)
        {

            $upload = Upload::where('brain_map_id', $brain_map->id)->first();

            if ($upload) {

                $segmentation = $upload->auto_segmentation;

                if ($segmentation !== null && array_key_exists('status', $segmentation)) {
                    if ($segmentation['status'] == 'transformed'){
                        $user = User::find($brain_map->user_id);

                        $registration_files = $segmentation;

                        $required_files = [
                            "registeredAtlasT1cFileURL",
                            "segmentationFileURL",
                            "registeredCompositeFileURL",
                            "registeredInverseCompositeFileURL"
                        ];

                        $required_files_present = true;

                        foreach($required_files as $required_file) {
                            if (!array_key_exists($required_file, $registration_files)) {
                                print_r("Missing required file " . $required_file . " for brainmap id " . $brain_map->id . "\n");
                                $required_files_present = false;
                            }
                        }

                        if ($required_files_present === true) {
                            dispatch((new GSIRADSAnalyze($brain_map, $upload, $user))->onQueue('low'));
                        }

                    }
                }
            }


        }

    }
}
