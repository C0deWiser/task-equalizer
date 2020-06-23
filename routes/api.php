<?php

use Illuminate\Support\Facades\Route;

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

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/drivers', 'Api\DriverController@index')->name('drivers.index');
    Route::apiResource('servers', 'Api\ServerController');
    Route::apiResource('mirrors', 'Api\MirrorController');
});
