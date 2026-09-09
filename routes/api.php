<?php

use App\Http\Controllers\CloudSyncApiController;
use App\Http\Middleware\AuthenticateStudioSync;
use Illuminate\Support\Facades\Route;

Route::prefix('sync/v1')->middleware([AuthenticateStudioSync::class, 'throttle:600,1'])->group(function () {
    Route::get('health', [CloudSyncApiController::class, 'health']);
    Route::put('galleries/{uuid}', [CloudSyncApiController::class, 'upsert'])->whereUuid('uuid');
    Route::post('galleries/{uuid}/photos', [CloudSyncApiController::class, 'photo'])->whereUuid('uuid');
    Route::post('galleries/{uuid}/publish', [CloudSyncApiController::class, 'publish'])->whereUuid('uuid');
    Route::get('galleries/{uuid}/selections', [CloudSyncApiController::class, 'selections'])->whereUuid('uuid');
    Route::delete('galleries/{uuid}', [CloudSyncApiController::class, 'remove'])->whereUuid('uuid');
});
