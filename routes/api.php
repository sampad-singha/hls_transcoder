<?php

use App\Http\Controllers\Api\V1\TranscodingController;
use Illuminate\Support\Facades\Route;


Route::prefix('v1')->middleware('internal.jwt')->group(function () {
    Route::post('transcode', [TranscodingController::class, 'triggerTranscoding']);
    Route::get('transcode/progress/{videoId}', [TranscodingController::class, 'getProgress']);
});
