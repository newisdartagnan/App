<?php

/*
| Téléconsultation, côté médecin (TLC-01, TLC-02, TLC-04). Inclus depuis
| routes/personnel.php, dans la session complète du personnel et le rôle
| medecin : adresses /personnel/medecin/consultation/…, noms
| personnel.medecin.consultation… Chaque route a sa limite de débit nommée.
*/

use App\Http\Controllers\Personnel\Medecin\ConsultationController;
use App\Http\Controllers\Teleconsultation\PresenceController;
use App\Http\Controllers\Teleconsultation\SignauxController;
use Illuminate\Support\Facades\Route;

Route::prefix('/consultation/{rendezVous}')->whereUuid('rendezVous')->name('consultation')->group(function () {
    // L'entrée écrit sous verrou (arrivée, reconnexion) : débit borné.
    Route::get('/', ConsultationController::class)->middleware('throttle:salle-consultation')->name('');
    Route::get('/signaux', [SignauxController::class, 'index'])
        ->middleware('throttle:signaux-consultation-lecture')->name('.signaux');
    Route::post('/signaux', [SignauxController::class, 'store'])
        ->middleware('throttle:signaux-consultation')->name('.signaux.envoyer');
    Route::post('/presence', PresenceController::class)
        ->middleware('throttle:presence-consultation')->name('.presence');
});
