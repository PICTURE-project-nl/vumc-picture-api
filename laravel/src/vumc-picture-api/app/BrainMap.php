<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Emadadly\LaravelUuid\Uuids;

class BrainMap extends Model
{

    use Uuids;

    /**
     * Indicates if the IDs are auto-incrementing.
     *
     * @var bool
     */
    public $incrementing = false;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'user_id', 'name', 'brain_map_notes', 'brain_map_classification', 'glioma',
        'brain_map_notes', 'shared_brain_map', 'expected_residual_volume', 'expected_resectability_index', 'mri_date',
        'age', 'GBM', 'visited_at', 'notified', 'gsi_rads', 'gsi_rads_xlsx_url',
    ];

    /**
     * The attributes that should be hidden for arrays.
     *
     * @var array
     */
    protected $hidden = [
        'updated_at',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'low_res_brain_map' => 'array',
        'high_res_brain_map' => 'array',
        'gsi_rads' => 'array',
    ];
}
