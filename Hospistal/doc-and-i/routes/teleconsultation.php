<?php

/*
| Téléconsultation, côté patient (TLC-01, TLC-02, TLC-04). Inclus depuis
| routes/rendezvous.php, dans le groupe auth + role:patient + profil.complet
| + consentement. La salle, la signalisation et la présence revérifient la
| relation de soin, la fenêtre horaire et l'état de la salle à chaque requête.
| Chaque route a sa limite de débit nommée (AppServiceProvider).
*/

use App\Http\Controllers\Teleconsultation\PresenceController;
use App\Http\Controllers\Teleconsultation\SallePatientController;
use App\Http\Controllers\Teleconsultation\SignauxController;
use Illuminate\Support\Facades\Route;

Route::prefix('/consultation/{rendezVous}')->whereUuid('rendezVous')->name('consultation')->group(function () {
    // L'entrée écrit sous verrou (arrivée, reconnexion) : débit borné.
    Route::get('/', SallePatientController::class)->middleware('throttle:salle-consultation')->name('');
    Route::get('/signaux', [SignauxController::class, 'index'])
        ->middleware('throttle:signaux-consultation-lecture')->name('.signaux');
    Route::post('/signaux', [SignauxController::class, 'store'])
        ->middleware('throttle:signaux-consultation')->name('.signaux.envoyer');
    Route::post('/presence', PresenceController::class)
        ->middleware('throttle:presence-consultation')->name('.presence');
});
