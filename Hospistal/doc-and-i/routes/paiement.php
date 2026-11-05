<?php

/*
| Paiement côté patient (PAY-02 à PAY-05). Inclus depuis routes/web.php dans
| le groupe auth + role:patient + profil.complet + consentement. Chaque
| contrôleur vérifie en plus que le rendez-vous, le paiement ou la facture
| appartient à CE patient (404 sinon). Le navigateur ne confirme jamais un
| paiement : seul le rappel signé de l'opérateur le fait (routes/paiement-rappel.php).
*/

use App\Http\Controllers\Paiement\MesPaiementsController;
use App\Http\Controllers\Paiement\PaiementPatientController;
use App\Services\Paiement\SimulationPaiement;
use Illuminate\Support\Facades\Route;

Route::name('paiement.')->group(function () {
    Route::get('/mes-rendez-vous/{rendezVous}/paiement', [PaiementPatientController::class, 'payer'])
        ->whereUuid('rendezVous')->name('payer');
    Route::post('/mes-rendez-vous/{rendezVous}/paiement', [PaiementPatientController::class, 'initier'])
        ->whereUuid('rendezVous')->middleware('throttle:10,1')->name('initier');

    Route::get('/paiements/{paiement}', [PaiementPatientController::class, 'suivi'])
        ->whereUuid('paiement')->name('suivi');

    // Simulation locale seulement : absente en production (et revérifiée par le contrôleur).
    if (SimulationPaiement::disponible()) {
        Route::post('/paiements/{paiement}/simulation', [PaiementPatientController::class, 'simuler'])
            ->whereUuid('paiement')->middleware('throttle:20,1')->name('simulation');
    }

    Route::get('/mes-paiements', [MesPaiementsController::class, 'index'])->name('index');
    Route::get('/factures/{facture}', [MesPaiementsController::class, 'telecharger'])
        ->whereUuid('facture')->name('facture');
});
