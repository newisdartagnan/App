<?php

/*
| Parcours de rendez-vous du patient (RDV-01 à RDV-07).
| Inclus depuis routes/web.php, à l'intérieur du groupe
| auth + role:patient + profil.complet + consentement : chaque route ici
| est déjà réservée à un patient connecté, au profil complet et consentant.
| Préfixer les noms de routes par « rdv. ».
|
| Chaque contrôleur vérifie en plus que la demande, la pièce jointe ou le
| rendez-vous appartient à CE patient (404 sinon). Les identifiants sont des
| UUID : un identifiant mal formé donne 404, jamais une erreur de base.
*/

use App\Http\Controllers\RendezVous\DemandeController;
use App\Http\Controllers\RendezVous\MesRendezVousController;
use App\Http\Controllers\RendezVous\PieceJointeController;
use App\Http\Controllers\RendezVous\ReservationController;
use Illuminate\Support\Facades\Route;

Route::name('rdv.')->group(function () {
    // RDV-01, RDV-02 : décrire le besoin, répondre au tri.
    Route::get('/consultation/demande', [DemandeController::class, 'creer'])->name('demande.creer');
    Route::post('/consultation/demande', [DemandeController::class, 'enregistrer'])
        ->middleware('throttle:10,1')->name('demande.enregistrer');

    Route::prefix('/consultation/demandes/{demande}')->whereUuid('demande')->name('demande.')->group(function () {
        Route::get('/urgence', [DemandeController::class, 'urgence'])->name('urgence');
        // RDV-03, RDV-04, RDV-05 : choisir, voir les créneaux, réserver.
        Route::get('/choix', [DemandeController::class, 'choix'])->name('choix');
        Route::get('/creneaux', [ReservationController::class, 'creneaux'])->name('creneaux');
        Route::post('/reservation', [ReservationController::class, 'reserver'])
            ->middleware('throttle:20,1')->name('reserver');
    });

    Route::get('/consultation/pieces-jointes/{piece}', PieceJointeController::class)
        ->whereUuid('piece')->name('piece-jointe');

    // RDV-07 : mes rendez-vous, annulation, report.
    Route::get('/mes-rendez-vous', [MesRendezVousController::class, 'index'])->name('index');
    Route::prefix('/mes-rendez-vous/{rendezVous}')->whereUuid('rendezVous')->group(function () {
        Route::get('/', [MesRendezVousController::class, 'voir'])->name('voir');
        Route::post('/annulation', [MesRendezVousController::class, 'annuler'])->name('annuler');
        Route::get('/report', [MesRendezVousController::class, 'report'])->name('report');
        Route::post('/report', [MesRendezVousController::class, 'reporter'])
            ->middleware('throttle:20,1')->name('reporter');
    });
});

// Téléconsultation : salle et signalisation de l'appel vidéo (TLC-01).
require __DIR__.'/teleconsultation.php';
