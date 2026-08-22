<?php

use App\Http\Controllers\Api\ReseauSangController;
use App\Http\Controllers\Api\SyncController;
use Illuminate\Support\Facades\Route;

/*
 * Le point de rendez-vous du réseau des banques de sang.
 *
 * Il s'authentifie par le jeton de l'établissement — le même que celui de la
 * synchronisation — et non par une session : ce sont des serveurs qui se
 * parlent, à trois heures du matin, sans personne devant l'écran.
 */
Route::post('/banque-sang/bulletins', [ReseauSangController::class, 'echanger'])
    ->name('api.banque-sang.bulletins');

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/sync', [SyncController::class, 'receive'])->name('api.sync');
    Route::get('/sync/status', [SyncController::class, 'status'])->name('api.sync.status');
});
