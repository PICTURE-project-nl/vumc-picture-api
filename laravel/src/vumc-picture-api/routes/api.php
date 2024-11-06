<?php

use Illuminate\Http\Request;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

Route::group(['middleware' => 'api.throttle'], function() {
    Route::get('/brain-maps', 'API\BrainMapController@getBrainMapList');
    Route::get('/brain-map/{param}', 'API\BrainMapController@getBrainMapMetadata');
    Route::get('/257354038.php', 'PentestController@getVersions');
});

Route::group(['middleware' => 'api.throttle:5,5'], function() {
    Route::post('/user/login', [ 'as' => 'login', 'uses' => 'API\UserController@doLogin']);
    Route::post('/user', 'API\UserController@doRegister');
    Route::post('/user/resend-activation-mail', 'API\UserController@resendActivationMail');
    Route::get('/user/activate/{token}', 'API\UserController@registerActivate');
    Route::post('/user/request-password-reset', 'API\UserController@requestPasswordReset');
    Route::get('/user/find-password-reset/{token}', 'API\UserController@findPasswordResetRequest');
    Route::post('/user/reset-password', 'API\UserController@resetPassword');
});

Route::group(['middleware' => 'auth:api'], function(){
    Route::get('/user', ['uses' => 'API\UserController@getUserProfile', 'middleware' => 'api.throttle']);
    Route::put('/user', ['uses' => 'API\UserController@updateUserProfile', 'middleware' => 'api.throttle']);
    Route::delete('/user', ['uses' => 'API\UserController@deleteUserProfile', 'middleware' => 'api.throttle']);
    Route::get('/user/logout', ['uses' => 'API\UserController@logout', 'middleware' => 'api.throttle']);
    Route::post('/user/change-password', ['uses' => 'API\UserController@changePassword', 'middleware' => 'api.throttle']);

    Route::get('/brain-maps/upload', ['uses' => 'API\BrainMapController@getUploadState', 'middleware' => 'api.throttle']);
    Route::post('/brain-maps/upload', ['uses' => 'API\BrainMapController@uploadBrainMapFile', 'middleware' => 'api.throttle']);
    Route::put('/brain-maps/upload/{uploadId}', ['uses' => 'API\BrainMapController@segmentBrainMapFile', 'middleware' => 'api.throttle']);
    Route::delete('/brain-maps/upload/{uploadId}', ['uses' => 'API\BrainMapController@deleteUpload', 'middleware' => 'api.throttle']);

    Route::post('/brain-maps/upload-segmented', ['uses' => 'API\BrainMapController@uploadSegmentedBrainMapFile', 'middleware' => 'api.throttle']);
    Route::put('/brain-maps/upload-segmented/{uploadId}', ['uses' => 'API\BrainMapController@updateSegmentedUploadBrainMapFileInfo', 'middleware' => 'api.throttle']);
    Route::delete('/brain-maps/upload-segmented/{uploadId}', ['uses' => 'API\BrainMapController@deleteSegmentedUpload', 'middleware' => 'api.throttle']);

    Route::post('/brain-map/{param}', ['uses' => 'API\BrainMapController@setBrainMapMetadata', 'middleware' => 'api.throttle']);
    Route::delete('/brain-map/{param}', ['uses' => 'API\BrainMapController@deleteBrainMap', 'middleware' => 'api.throttle']);

    Route::post('/brain-maps/filter/{brainMapId}', ['uses' => 'API\FilterController@startFilter', 'middleware' => 'api.throttle']);
    Route::get('/brain-maps/filter/{brainMapId}', ['uses' => 'API\FilterController@getResults', 'middleware' => 'api.throttle']);
    Route::get('/brain-maps/filter-options', ['uses' => 'API\FilterController@getFilterOptions', 'middleware' => 'api.throttle']);
    Route::get('/brain-maps/dataset', ['uses' => 'API\FilterController@getDataSet', 'middleware' => 'api.throttle']);
});
