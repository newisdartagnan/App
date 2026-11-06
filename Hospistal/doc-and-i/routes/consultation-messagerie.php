<?php

/*
| Consultation, côté patient : messagerie (TLC-03) et fin de consultation
| (RG-06). Inclus dans le groupe patient (auth + role:patient + profil
| complet + consentement). Points d'accès JSON appelés par les composants
| de la salle ; chaque requête revérifie que le rendez-vous est le sien.
| Adresses /consultation/{rendezVous}/…, noms consultation.…
*/

use App\Http\Controllers\Teleconsultation\FinConsultationController;
use App\Http\Controllers\Teleconsultation\MessagerieController;
use Illuminate\Support\Facades\Route;

Route::prefix('/consultation/{rendezVous}')->whereUuid('rendezVous')->name('consultation.')->group(function () {
    Route::get('/messages', [MessagerieController::class, 'lister'])
        ->middleware('throttle:120,1,tlc-lecture-patient')->name('messages');
    Route::post('/messages', [MessagerieController::class, 'envoyer'])
        ->middleware('throttle:30,1,tlc-envoi-patient')->name('messages.envoyer');
    Route::get('/messages/{message}/photo', [MessagerieController::class, 'photo'])
        ->whereUuid('message')->middleware('throttle:120,1,tlc-photo-patient')->name('messages.photo');

    Route::get('/fin', [FinConsultationController::class, 'etat'])->middleware('throttle:120,1,tlc-fin-patient')->name('fin');
    Route::post('/medecin-absent', [FinConsultationController::class, 'medecinAbsent'])
        ->middleware('throttle:10,1,tlc-absence-patient')->name('medecin-absent');
});
