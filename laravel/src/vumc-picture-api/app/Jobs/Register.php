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
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;
use App\Notifications\RegistrationSuccess;
use App\Jobs\GSIRADSAnalyze;
use App\Jobs\BrainMapUpdate;


class Register implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private $upload;
    private $brain_map;
    private $nifti_files;
    private $segmentize;
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
    public $timeout = 7200;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct($upload, $brain_map, $nifti_files, $segmentize, $user)
    {
        $this->upload = $upload;
        $this->brain_map = $brain_map;
        $this->nifti_files = $nifti_files;
        $this->segmentize = $segmentize;
        $this->user = $user;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $ROOT_URL =  getenv('SERVER_HOSTNAME');
        $nifti_files = $this->nifti_files;
        $nifti_dir = '/var/www/laravel/vumc-picture-api/storage/app/public/nifti/' . $this->brain_map->id . '/';
        $low_res_nifti_dir = '/var/www/laravel/vumc-picture-api/storage/app/public/l/' . $this->brain_map->id;
        $high_res_nifti_dir = '/var/www/laravel/vumc-picture-api/storage/app/public/h/' . $this->brain_map->id . '/';
        $segmentize = $this->segmentize;

        $T1c = $nifti_dir . $nifti_files['selected_t1c_file'];
        $T1w = $nifti_dir . $nifti_files['selected_t1w_file'];
        $T2w = $nifti_dir . $nifti_files['selected_t2w_file'];
        $Flr = $nifti_dir . $nifti_files['selected_flair_file'];

        $registered_state = false;

        $input_files = [
        [
            'name' => 'T1c',
            'contents' => fopen($T1c, 'rb'),
        ],
        [
            'name' => 'T1w',
            'contents' => fopen($T1w, 'rb'),
        ],
        [
            'name' => 'T2w',
            'contents' => fopen($T2w, 'rb'),
        ],
        [
            'name' => 'Flr',
            'contents' => fopen($Flr, 'rb'),
        ],
        ];

        $client = new Client();
        $registration_base_url = 'http://registration:5000';
        $response = $client->post($registration_base_url . '/register', ['multipart' => $input_files]);

        $res_status_code = $response->getStatusCode();

        if ($res_status_code == 202) {
            $res_body = $response->getBody();
            $res_array = json_decode($res_body, TRUE);

            $status_location = $res_array['location'];
            $state = '';

            while ($state != 'SUCCESS')
            {
                $response = $client->get($registration_base_url . $status_location);
                $res_status_code = $response->getStatusCode();

                if ($res_status_code == 200) {
                    $res_body = $response->getBody();
                    $res_array = json_decode($res_body, TRUE);

                    $state = $res_array['state'];

                    if ($state == 'FAILED')
                    {
                        $auto_segmentation = [
                            'status' => 'failed',
                            'message' => 'error during registration process'
                        ];

                        $this->upload->auto_segmentation = $auto_segmentation;
                        $this->upload->save();
                        break;
                    }

                    if ($state == 'SUCCESS')
                    {
                        $registered_state = true;
                        $result = $res_array['result'];
                        break;
                    }

                }
                else {
                    $auto_segmentation = [
                        'status' => 'failed',
                        'message' => 'error during registration process'
                    ];

                    $this->upload->auto_segmentation = $auto_segmentation;
                    $this->upload->save();
                }
                sleep(5);
            }

        }
        else {
            $auto_segmentation = [
                'status' => 'failed',
                'message' => 'error during registration process'
            ];

            $this->upload->auto_segmentation = $auto_segmentation;
            $this->upload->save();
        }


        if ($registered_state == true) {
            $reg_upload_dir = $result['upload_dir'];
            $response = $client->get($registration_base_url . '/downloads/' . $reg_upload_dir . '/outputs.json');

            $res_status_code = $response->getStatusCode();

            if ($res_status_code == 200) {

                mkdir($low_res_nifti_dir, 0755, false);
                $low_res_nifti_dir = $low_res_nifti_dir . '/';

                $res_body = $response->getBody();
                $res_array = json_decode($res_body, TRUE);

                $atlas_T1c = $res_array['images']['Atlas_T1c'];
                $atlas_T1c = substr($atlas_T1c, 10);
                $atlas_T1c_fname = basename($atlas_T1c);

                $atlas_T1c_resource = fopen($low_res_nifti_dir . $atlas_T1c_fname, 'w');
                $client->request('GET', $registration_base_url . '/downloads/' . $atlas_T1c, ['sink' => $atlas_T1c_resource]);

                $atlas_T1w = $res_array['images']['Atlas_T1w'];
                $atlas_T1w = substr($atlas_T1w, 10);
                $atlas_T1w_fname = basename($atlas_T1w);

                $atlas_T1w_resource = fopen($low_res_nifti_dir . $atlas_T1w_fname, 'w');
                $client->request('GET', $registration_base_url . '/downloads/' . $atlas_T1w, ['sink' => $atlas_T1w_resource]);

                $atlas_T2w = $res_array['images']['Atlas_T2w'];
                $atlas_T2w = substr($atlas_T2w, 10);
                $atlas_T2w_fname = basename($atlas_T2w);

                $atlas_T2w_resource = fopen($low_res_nifti_dir . $atlas_T2w_fname, 'w');
                $client->request('GET', $registration_base_url . '/downloads/' . $atlas_T2w, ['sink' => $atlas_T2w_resource]);

                $atlas_Flr = $res_array['images']['Atlas_FLR'];
                $atlas_Flr = substr($atlas_Flr, 10);
                $atlas_Flr_fname = basename($atlas_Flr);

                $atlas_Flr_resource = fopen($low_res_nifti_dir . $atlas_Flr_fname, 'w');
                $client->request('GET', $registration_base_url . '/downloads/' . $atlas_Flr, ['sink' => $atlas_Flr_resource]);

            } else {
                $registered_state = false;
                $auto_segmentation = [
                    'status' => 'failed',
                    'message' => 'error during registration process'
                ];

                $this->upload->auto_segmentation = $auto_segmentation;
                $this->upload->save();
            }



            $auto_segmentation = [
                'status' => 'registered',
                'message' => 'registered nifti files added',
                'registeredAtlasT1cFileURL' => $ROOT_URL . '/storage/l/' . $this->brain_map->id . '/' . $atlas_T1c_fname,
                'registeredAtlasT1wFileURL' => $ROOT_URL . '/storage/l/' . $this->brain_map->id . '/' . $atlas_T1w_fname,
                'registeredAtlasT2wFileURL' => $ROOT_URL . '/storage/l/' . $this->brain_map->id . '/' . $atlas_T2w_fname,
                'registeredAtlasFlairFileURL' => $ROOT_URL . '/storage/l/' . $this->brain_map->id . '/' . $atlas_Flr_fname,
            ];

            $this->upload->auto_segmentation = $auto_segmentation;
            $this->upload->save();

            $low_res_brain_map = [
                'registeredAtlasT1cFileURL' => $ROOT_URL . '/storage/l/' . $this->brain_map->id . '/' . $atlas_T1c_fname,
                'registeredAtlasT1wFileURL' => $ROOT_URL . '/storage/l/' . $this->brain_map->id . '/' . $atlas_T1w_fname,
                'registeredAtlasT2wFileURL' => $ROOT_URL . '/storage/l/' . $this->brain_map->id . '/' . $atlas_T2w_fname,
                'registeredAtlasFlairFileURL' => $ROOT_URL . '/storage/l/' . $this->brain_map->id . '/' . $atlas_Flr_fname,
            ];

            $this->brain_map->low_res_brain_map = $low_res_brain_map;
            $this->brain_map->save();
        }

        if ($registered_state == true  && $segmentize == true)
            {
            $atlas_T1c = $low_res_nifti_dir . $atlas_T1c_fname;
            $atlas_T1w = $low_res_nifti_dir . $atlas_T1w_fname;
            $atlas_T2w = $low_res_nifti_dir . $atlas_T2w_fname;
            $atlas_Flr = $low_res_nifti_dir . $atlas_Flr_fname;


            $input_files = [
            [
                'name' => 'T1c',
                'contents' => fopen($atlas_T1c, 'rb'),
            ],
            [
                'name' => 'T1w',
                'contents' => fopen($atlas_T1w, 'rb'),
            ],
            [
                'name' => 'T2w',
                'contents' => fopen($atlas_T2w, 'rb'),
            ],
            [
                'name' => 'Flr',
                'contents' => fopen($atlas_Flr, 'rb'),
            ],
            ];

            $client = new Client();
            $segmentation_base_url = 'http://segmentation:5000';
            $response = $client->post($segmentation_base_url . '/segment', ['multipart' => $input_files]);

            $res_status_code = $response->getStatusCode();

            if ($res_status_code == 202) {
                $res_body = $response->getBody();
                $res_array = json_decode($res_body, TRUE);

                $status_location = $res_array['location'];
                $state = '';

                while ($state != 'SUCCESS')
                {
                    $response = $client->get($segmentation_base_url . $status_location);
                    $res_status_code = $response->getStatusCode();

                    if ($res_status_code == 200) {
                        $res_body = $response->getBody();
                        $res_array = json_decode($res_body, TRUE);

                        $state = $res_array['state'];

                        if ($state == 'FAILED')
                        {
                            $auto_segmentation = [
                                'status' => 'failed',
                                'message' => 'error during registration process'
                            ];

                            $this->upload->auto_segmentation = $auto_segmentation;
                            $this->upload->save();
                            break;
                        }

                        if ($state == 'SUCCESS')
                        {
                            $registered_state = true;
                            $result = $res_array['result'];
                            break;
                        }

                    }
                    else {
                        $auto_segmentation = [
                            'status' => 'failed',
                            'message' => 'error during registration process'
                        ];

                        $this->upload->auto_segmentation = $auto_segmentation;
                        $this->upload->save();
                    }
                    sleep(5);
                }

            }
            else {
                $auto_segmentation = [
                    'status' => 'failed',
                    'message' => 'error during registration process'
                ];

                $this->upload->auto_segmentation = $auto_segmentation;
                $this->upload->save();
            }


            if ($registered_state == true) {
                $upload_dir = $result['upload_dir'];
                $atlas_Seg_resource = fopen($low_res_nifti_dir . '/segmentation.nii', 'w');
                $client->request('GET', $segmentation_base_url . '/downloads/' . $upload_dir . '/Atlas_segmentation.nii', ['sink' => $atlas_Seg_resource]);





                $auto_segmentation = [
                    'status' => 'registered',
                    'message' => 'registered nifti files added',
                    'registeredAtlasT1cFileURL' => $ROOT_URL . '/storage/l/' . $this->brain_map->id . '/' . $atlas_T1c_fname,
                    'registeredAtlasT1wFileURL' => $ROOT_URL . '/storage/l/' . $this->brain_map->id . '/' . $atlas_T1w_fname,
                    'registeredAtlasT2wFileURL' => $ROOT_URL . '/storage/l/' . $this->brain_map->id . '/' . $atlas_T2w_fname,
                    'registeredAtlasFlairFileURL' => $ROOT_URL . '/storage/l/' . $this->brain_map->id . '/' . $atlas_Flr_fname,
                    'segmentationFileURL' => $ROOT_URL . '/storage/l/' . $this->brain_map->id . '/segmentation.nii'
                ];

                $this->upload->auto_segmentation = $auto_segmentation;
                $this->upload->save();

            }
        }

        if ($registered_state == true)
        {
            $registered_state = false;
            $input_files = [
                [
                    'name' => 'segmentation',
                    'contents' => fopen($low_res_nifti_dir . '/segmentation.nii', 'rb'),
                ],
                ];


            $response = $client->post($registration_base_url . '/register_atlas/' . $reg_upload_dir, ['multipart' => $input_files]);

            $res_status_code = $response->getStatusCode();

            if ($res_status_code == 202) {

                $res_body = $response->getBody();
                $res_array = json_decode($res_body, TRUE);

                $status_location = $res_array['location'];
                $state = '';

                while ($state != 'SUCCESS') {
                    $response = $client->get($registration_base_url . $status_location);
                    $res_status_code = $response->getStatusCode();

                    if ($res_status_code == 200) {
                        $res_body = $response->getBody();
                        $res_array = json_decode($res_body, TRUE);

                        $state = $res_array['state'];

                        if ($state == 'FAILED') {
                            $auto_segmentation = [
                                'status' => 'failed',
                                'message' => 'error during registration process'
                            ];

                            $this->upload->auto_segmentation = $auto_segmentation;
                            $this->upload->save();
                            break;
                        }

                        if ($state == 'SUCCESS') {
                            $registered_state = true;
                            $result = $res_array['result'];
                            break;
                        }

                    } else {
                        $auto_segmentation = [
                            'status' => 'failed',
                            'message' => 'error during registration process'
                        ];

                        $this->upload->auto_segmentation = $auto_segmentation;
                        $this->upload->save();
                    }
                    sleep(5);
                }
            }
        }
        else {
            $auto_segmentation = [
                'status' => 'failed',
                'message' => 'error during registration process'
            ];

            $this->upload->auto_segmentation = $auto_segmentation;
            $this->upload->save();
        }



        if ($registered_state == true)
        {
            $upload_dir = $result['upload_dir'];
            $response = $client->get($registration_base_url . '/downloads/' . $upload_dir . '/transform_outputs.json');

            $res_status_code = $response->getStatusCode();

            if ($res_status_code == 200) {
                mkdir($high_res_nifti_dir, 0755, false);
                $res_body = $response->getBody();
                $res_array = json_decode($res_body, TRUE);


                $binary_map = $res_array['images']['AtlasHD_segmentation'];
                $binary_map = substr($binary_map, 10);
                $binary_map_fname = basename($binary_map);

                $binary_map_resource = fopen($high_res_nifti_dir . $binary_map_fname, 'w');
                $client->request('GET', $registration_base_url . '/downloads/' . $binary_map, ['sink' => $binary_map_resource]);

                $transformed_segmentation = $res_array['images']['Atlas_segmentation'];
                $transformed_segmentation = substr($transformed_segmentation, 10);
                $transformed_segmentation_fname = basename($transformed_segmentation);

                $transformed_segmentation_resource = fopen($low_res_nifti_dir . $transformed_segmentation_fname, 'w');
                $client->request('GET', $registration_base_url . '/downloads/' . $transformed_segmentation, ['sink' => $transformed_segmentation_resource]);


                $T1c_to_Atlas_composite = $res_array['transforms']['T1c_to_Atlas_WARP'];
                $T1c_to_Atlas_composite = substr($T1c_to_Atlas_composite, 10);
                $T1c_to_Atlas_composite_fname = basename($T1c_to_Atlas_composite);

                $T1c_to_Atlas_composite_resource = fopen($high_res_nifti_dir . $T1c_to_Atlas_composite_fname, 'w');
                $client->request('GET', $registration_base_url . '/downloads/' . $T1c_to_Atlas_composite, ['sink' => $T1c_to_Atlas_composite_resource]);


                $Atlas_to_T1c_composite = $res_array['transforms']['Atlas_to_T1c_WARP'];
                $Atlas_to_T1c_composite = substr($Atlas_to_T1c_composite, 10);
                $Atlas_to_T1c_composite_fname = basename($Atlas_to_T1c_composite);

                $Atlas_to_T1c_composite_resource = fopen($high_res_nifti_dir . $Atlas_to_T1c_composite_fname, 'w');
                $client->request('GET', $registration_base_url . '/downloads/' . $Atlas_to_T1c_composite, ['sink' => $Atlas_to_T1c_composite_resource]);
                // $nr_of_patients_map = $res_array['nr_of_patients_map'];
                // $nr_of_patients_map = substr($nr_of_patients_map, 10);
                // $nr_of_patients_map_fname = basename($nr_of_patients_map);

                // $nr_of_patients_map_resource = fopen($low_res_nifti_dir . $nr_of_patients_map_fname, 'w');
                // $client->request('GET', $registration_base_url . '/downloads/' . $nr_of_patients_map, ['sink' => $nr_of_patients_map_resource]);
                $atlasHD_T1c = $res_array['images']['AtlasHD_T1c'];
                $atlasHD_T1c = substr($atlasHD_T1c, 10);
                $atlasHD_T1c_fname = basename($atlasHD_T1c);

                $atlasHD_T1c_resource = fopen($high_res_nifti_dir . $atlasHD_T1c_fname, 'w');
                $client->request('GET', $registration_base_url . '/downloads/' . $atlasHD_T1c, ['sink' => $atlasHD_T1c_resource]);

                $atlasHD_T1w = $res_array['images']['AtlasHD_T1w'];
                $atlasHD_T1w = substr($atlasHD_T1w, 10);
                $atlasHD_T1w_fname = basename($atlasHD_T1w);

                $atlasHD_T1w_resource = fopen($high_res_nifti_dir . $atlasHD_T1w_fname, 'w');
                $client->request('GET', $registration_base_url . '/downloads/' . $atlasHD_T1w, ['sink' => $atlasHD_T1w_resource]);

                $atlasHD_T2w = $res_array['images']['AtlasHD_T2w'];
                $atlasHD_T2w = substr($atlasHD_T2w, 10);
                $atlasHD_T2w_fname = basename($atlasHD_T2w);

                $atlasHD_T2w_resource = fopen($high_res_nifti_dir . $atlasHD_T2w_fname, 'w');
                $client->request('GET', $registration_base_url . '/downloads/' . $atlasHD_T2w, ['sink' => $atlasHD_T2w_resource]);

                $atlasHD_Flr = $res_array['images']['AtlasHD_FLR'];
                $atlasHD_Flr = substr($atlasHD_Flr, 10);
                $atlasHD_Flr_fname = basename($atlasHD_Flr);

                $atlasHD_Flr_resource = fopen($high_res_nifti_dir . $atlasHD_Flr_fname, 'w');
                $client->request('GET', $registration_base_url . '/downloads/' . $atlasHD_Flr, ['sink' => $atlasHD_Flr_resource]);

            } else {
                $registered_state = false;
                $auto_segmentation = [
                    'status' => 'failed',
                    'message' => 'error during registration process'
                ];

                $this->upload->auto_segmentation = $auto_segmentation;
                $this->upload->save();
            }



            if ($registered_state == true)

            {

            $high_res_brain_map = [
                'registeredAtlasT1cFileURL' => $ROOT_URL . '/storage/h/' . $this->brain_map->id . '/' . $atlasHD_T1c_fname,
                'registeredatlasT1wFileURL' => $ROOT_URL . '/storage/h/' . $this->brain_map->id . '/' . $atlasHD_T1w_fname,
                'registeredatlasT2wFileURL' => $ROOT_URL . '/storage/h/' . $this->brain_map->id . '/' . $atlasHD_T2w_fname,
                'registeredatlasFlairFileURL' => $ROOT_URL . '/storage/h/' . $this->brain_map->id . '/' . $atlasHD_Flr_fname,
            ];

            $this->brain_map->high_res_brain_map = $high_res_brain_map;
            $this->brain_map->save();
                // $expected_residual_volume = $res_array['expected_residual_volume'];
                // $expected_resectability_index = $res_array['expected_resectability_index'];

            } else {
                $registered_state = false;
                $auto_segmentation = [
                    'status' => 'failed',
                    'message' => 'error during transformation process'
                ];

                $this->upload->auto_segmentation = $auto_segmentation;
                $this->upload->save();
            }

            $client->delete($registration_base_url . '/remove/' . $upload_dir);
        }



        if ($registered_state == true){

            $high_res_brain_map = [
                'registeredAtlasT1cFileURL' => $ROOT_URL . '/storage/h/' . $this->brain_map->id . '/' . $atlasHD_T1c_fname,
                'registeredAtlasT1wFileURL' => $ROOT_URL . '/storage/h/' . $this->brain_map->id . '/' . $atlasHD_T1w_fname,
                'registeredAtlasT2wFileURL' => $ROOT_URL . '/storage/h/' . $this->brain_map->id . '/' . $atlasHD_T2w_fname,
                'registeredAtlasFlairFileURL' => $ROOT_URL . '/storage/h/' . $this->brain_map->id . '/' . $atlasHD_Flr_fname,
                'registeredCompositeFileURL' => $ROOT_URL . '/storage/h/' . $this->brain_map->id . '/' . $T1c_to_Atlas_composite_fname,
                'registeredInverseCompositeFileURL' => $ROOT_URL . '/storage/h/' . $this->brain_map->id . '/' . $Atlas_to_T1c_composite_fname,


            ];

            if ($segmentize == true)
            {
                $high_res_brain_map['segmentationFileURL'] = $ROOT_URL . '/storage/l/' . $this->brain_map->id . '/segmentation.nii';
                // $high_res_brain_map['probabilityMapFileURL'] = $ROOT_URL . '/storage/l/' . $this->brain_map->id . '/' . $probability_map_fname;
                $high_res_brain_map['binaryMapFileURL'] = $ROOT_URL . '/storage/h/' . $this->brain_map->id . '/' . $binary_map_fname;
                // $high_res_brain_map['transformedAtlasFileURL'] = $ROOT_URL . '/storage/l/' . $this->brain_map->id . '/' . $transformed_atlas_fname;
                $high_res_brain_map['transformedSegmentationFileURL'] = $ROOT_URL . '/storage/h/' . $this->brain_map->id . '/' . $binary_map_fname;

                $high_res_brain_map['probabilityMapFileURL'] = $ROOT_URL . '/storage/h/' . $this->brain_map->id . '/probability_map.nii';
                $high_res_brain_map['nrOfPatientsFileURL'] =  $ROOT_URL . '/storage/h/' . $this->brain_map->id . '/sum_tumors_map.nii';

                //$high_res_brain_map['probabilityMapFileURL']  = $high_res_brain_map['transformedSegmentationFileURL'];
                //$high_res_brain_map['nrOfPatientsFileURL']  = $high_res_brain_map['transformedSegmentationFileURL'];

                // $high_res_brain_map['nrOfPatientsFileURL'] = $ROOT_URL . '/storage/l/' . $this->brain_map->id . '/' . $nr_of_patients_map_fname;
                // $this->brain_map->expected_residual_volume = $expected_residual_volume;
                // $this->brain_map->expected_resectability_index = $expected_resectability_index;

                $auto_segmentation = [
                    'status' => 'transformed',
                    'message' => 'transformed',
                    'registeredAtlasT1cFileURL' => $ROOT_URL . '/storage/h/' . $this->brain_map->id . '/' . $atlasHD_T1c_fname,
                    'registeredAtlasT1wFileURL' => $ROOT_URL . '/storage/h/' . $this->brain_map->id . '/' . $atlasHD_T1w_fname,
                    'registeredAtlasT2wFileURL' => $ROOT_URL . '/storage/h/' . $this->brain_map->id . '/' . $atlasHD_T2w_fname,
                    'registeredAtlasFlairFileURL' => $ROOT_URL . '/storage/h/' . $this->brain_map->id . '/' . $atlasHD_Flr_fname,
                    'registeredCompositeFileURL' => $ROOT_URL . '/storage/h/' . $this->brain_map->id . '/' . $T1c_to_Atlas_composite_fname,
                    'registeredInverseCompositeFileURL' => $ROOT_URL . '/storage/h/' . $this->brain_map->id . '/' . $Atlas_to_T1c_composite_fname,
                    'segmentationFileURL' => $high_res_brain_map['binaryMapFileURL'],
                    'binaryMapFileURL' => $high_res_brain_map['binaryMapFileURL'],
                    #'transformedAtlasFileURL' => $high_res_brain_map['transformedAtlasFileURL'],
                    'transformedSegmentationFileURL' => $high_res_brain_map['transformedSegmentationFileURL'],

                    'probabilityMapFileURL' => $high_res_brain_map['probabilityMapFileURL'],
                    'nrOfPatientsFileURL' => $high_res_brain_map['nrOfPatientsFileURL'],
                    #'nrOfPatientsFileURL' => $high_res_brain_map['nrOfPatientsFileURL'],
                ];
                $this->upload->auto_segmentation = $auto_segmentation;
                $this->upload->save();

                dispatch((new GSIRADSAnalyze($this->brain_map, $this->upload, $this->user))->onQueue('low'));

                $high_res_nifti_dir_base = '/var/www/laravel/vumc-picture-api/storage/app/public/h/';

                $auto_segmentation = $this->upload->auto_segmentation;
                $upload_probability_map_url = $auto_segmentation['probabilityMapFileURL'];
                $upload_sum_tumors_map_url = $auto_segmentation['nrOfPatientsFileURL'];

                $upload_probability_map_url_array = explode('storage/h/', $upload_probability_map_url);
                $upload_probability_map_file = end($upload_probability_map_url_array);
                $upload_probability_map_file_path = $high_res_nifti_dir_base . $upload_probability_map_file;

                $upload_sum_tumors_map_url_array = explode('storage/h/', $upload_sum_tumors_map_url);
                $upload_sum_tumors_map_file = end($upload_sum_tumors_map_url_array);
                $upload_sum_tumors_map_file_path = $high_res_nifti_dir_base . $upload_sum_tumors_map_file;

                $this->updateBrainMap($this->upload, $upload_probability_map_file_path, $upload_probability_map_url, 'probability_map');
                $this->updateBrainMap($this->upload, $upload_sum_tumors_map_file_path, $upload_sum_tumors_map_url, 'sum_tumors_map');

            }

            $this->brain_map->low_res_brain_map = $high_res_brain_map;
            $this->brain_map->high_res_brain_map = $high_res_brain_map;
            $this->brain_map->save();

            $low_res_nifti_dir = rtrim($low_res_nifti_dir, '/');
            Madzipper::make($low_res_nifti_dir . '.zip')->add($high_res_nifti_dir)->close();

            $notification_array = ['segmentize' => $segmentize, 'brain_map_id' => $this->brain_map->id];
            $this->user->notify(new RegistrationSuccess($notification_array));

            $this->upload->process_state = 'finalized';
            $this->upload->save();


        }
    }


    /**
     * Update the brain_maps with research data
     *
     * @return mixed
     */
    private function updateBrainMap($upload, $brain_map, $url, $map)
    {

          $process_array = [
            'python3',
            '/python_utils/brain_map.py',
             $brain_map];

          dispatch((new BrainMapUpdate($process_array, $upload, $url, $map))->onQueue('low'));

    }


}
