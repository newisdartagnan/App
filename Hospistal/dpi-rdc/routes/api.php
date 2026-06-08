<?php

use App\Http\Controllers\Api\SyncController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/sync', [SyncController::class, 'receive'])->name('api.sync');
    Route::get('/sync/status', [SyncController::class, 'status'])->name('api.sync.status');
});
