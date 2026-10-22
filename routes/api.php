<?php

use App\Http\Controllers\Api\SyncController;
use App\Http\Middleware\AuthenticateDevice;
use Illuminate\Support\Facades\Route;

/*
| Local sync agent API. Authenticated with a revocable device token; devices
| can register and upload videos but cannot read anything else.
*/
Route::prefix('sync')->middleware([AuthenticateDevice::class, 'throttle:sync'])->group(function () {
    Route::post('heartbeat', [SyncController::class, 'heartbeat']);
    Route::post('videos/lookup', [SyncController::class, 'lookup']);
    Route::post('videos', [SyncController::class, 'register']);
    Route::get('videos/{video}', [SyncController::class, 'showVideo'])->whereNumber('video');
    Route::get('uploads/{session}', [SyncController::class, 'showUpload'])->whereUuid('session');
    Route::put('uploads/{session}/chunks/{index}', [SyncController::class, 'chunk'])->whereUuid('session')->whereNumber('index');
    Route::post('uploads/{session}/complete', [SyncController::class, 'complete'])->whereUuid('session');
    Route::post('uploads/{session}/abort', [SyncController::class, 'abort'])->whereUuid('session');
});
