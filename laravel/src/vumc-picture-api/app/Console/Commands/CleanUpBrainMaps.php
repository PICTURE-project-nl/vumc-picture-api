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


class CleanUpBrainMaps extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'brain_maps:clean';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clean up old brain maps';

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

        $brain_maps_expiration = BrainMap::whereDate('visited_at', '<=', Carbon::now()->subDays(7))->where
        ('notified', false)->get();

        foreach($brain_maps_expiration as $brain_map)
        {
            $user = User::find($brain_map->user_id);
            $user->notify(new BrainMapExpirationWarning($brain_map));
            $user->notified = true;
            $user->save();
        }

        $brain_maps_expired = BrainMap::whereDate('visited_at', '<=', Carbon::now()->subDays(14))->where
        ('notified', true)->get();

        foreach($brain_maps_expired as $brain_map)
        {
            $user = User::find($brain_map->user_id);
            $user->notify(new BrainMapExpired($brain_map));
            $upload = Upload::where('brain_map_id', $brain_map->id);

            if($upload)
            {
                Storage::delete('dicom-unprocessed/' . $upload->id);
                $upload->delete();
            }

            Storage::deleteDirectory('public/nifti/' . $brain_map->id);
            Storage::delete('public/nifti/' . $brain_map->id . '.zip');
            Storage::deleteDirectory('public/l/' . $brain_map->id);
            Storage::deleteDirectory('public/h/' . $brain_map->id);
            Storage::delete('public/l/' . $brain_map->id . '.zip');
            Storage::delete('public/h/' . $brain_map->id . '.zip');
            $brain_map->delete();
        }
    }
}
