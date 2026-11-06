<?php

/*
| Comptes du personnel (CPT-05, CPT-06) et espace médecin.
| Inclus depuis routes/web.php, HORS du groupe patient. Préfixer les adresses
| par /personnel et les noms de routes par « personnel. ».
*/

use App\Http\Controllers\Personnel\AccueilPersonnelController;
use App\Http\Controllers\Personnel\ActivationController;
use App\Http\Controllers\Personnel\Caisse\AccueilCaisseController;
use App\Http\Controllers\Personnel\Caisse\FactureController;
use App\Http\Controllers\Personnel\Caisse\JournalController;
use App\Http\Controllers\Personnel\Caisse\RapprochementController;
use App\Http\Controllers\Personnel\Caisse\RemboursementController;
use App\Http\Controllers\Personnel\Caisse\ReversementController;
use App\Http\Controllers\Personnel\Caisse\TauxController;
use App\Http\Controllers\Personnel\ConnexionPersonnelController;
use App\Http\Controllers\Personnel\Medecin\AbsenceController;
use App\Http\Controllers\Personnel\Medecin\AccueilMedecinController;
use App\Http\Controllers\Personnel\Medecin\AgendaController;
use App\Http\Controllers\Personnel\Medecin\AnnulationRendezVousController;
use App\Http\Controllers\Personnel\Medecin\PlageController;
use App\Http\Controllers\Personnel\Medecin\ReversementsMedecinController;
use App\Services\Paiement\SimulationPaiement;
use Illuminate\Support\Facades\Route;

Route::prefix('personnel')->name('personnel.')->group(function () {

    // CPT-06 : courriel + mot de passe, puis second facteur.
    Route::middleware('guest')->group(function () {
        Route::get('/connexion', [ConnexionPersonnelController::class, 'create'])->name('connexion');
        Route::post('/connexion', [ConnexionPersonnelController::class, 'store'])
            ->middleware('throttle:personnel-connexion')->name('connexion.verifier');
        Route::get('/connexion/code', [ConnexionPersonnelController::class, 'code'])->name('connexion.code');
        Route::post('/connexion/code', [ConnexionPersonnelController::class, 'verifierCode'])
            ->middleware('throttle:personnel-code')->name('connexion.code.verifier');
    });

    Route::post('/deconnexion', [ConnexionPersonnelController::class, 'destroy'])->name('deconnexion');

    // CPT-05 : activation par le lien signé, à usage unique.
    Route::middleware('throttle:personnel-activation')->prefix('activation')->name('activation')->group(function () {
        Route::get('/mot-de-passe', [ActivationController::class, 'motDePasse'])->name('.mot-de-passe');
        Route::post('/mot-de-passe', [ActivationController::class, 'enregistrerMotDePasse'])->name('.mot-de-passe.enregistrer');
        Route::get('/double-facteur', [ActivationController::class, 'doubleFacteur'])->name('.double-facteur');
        Route::post('/double-facteur', [ActivationController::class, 'confirmerDoubleFacteur'])->name('.double-facteur.confirmer');
        Route::get('/{jeton}', [ActivationController::class, 'ouvrir'])
            ->where('jeton', '[A-Za-z0-9]{64}')->middleware('signed')->name('');
    });

    // Session complète du personnel : mot de passe ET second facteur dans cette session.
    Route::middleware('personnel')->group(function () {
        Route::get('/', AccueilPersonnelController::class)->name('accueil');
        Route::get('/codes-de-recuperation', [ActivationController::class, 'codesRecuperation'])->name('codes-recuperation');

        // Espace administrateur (ADM-01, ADM-02) : rôle admin_fonctionnel.
        require __DIR__.'/admin.php';

        // Espace médecin (RDV-04, côté médecin). Chaque contrôleur ne lit que
        // les données du médecin connecté.
        Route::middleware('role:medecin')->prefix('medecin')->name('medecin.')->group(function () {
            Route::get('/', AccueilMedecinController::class)->name('accueil');

            Route::get('/disponibilites', [PlageController::class, 'index'])->name('plages');
            Route::post('/disponibilites', [PlageController::class, 'store'])->name('plages.ajouter');
            Route::delete('/disponibilites/{plage}', [PlageController::class, 'destroy'])->whereUuid('plage')->name('plages.supprimer');

            Route::get('/absences', [AbsenceController::class, 'index'])->name('absences');
            Route::post('/absences', [AbsenceController::class, 'store'])->name('absences.ajouter');
            Route::delete('/absences/{absence}', [AbsenceController::class, 'destroy'])->whereUuid('absence')->name('absences.supprimer');

            Route::get('/agenda', AgendaController::class)->name('agenda');
            // PAY-09 : ses reversements, en lecture.
            Route::get('/reversements', ReversementsMedecinController::class)->name('reversements');
            Route::post('/rendez-vous/{rendezVous}/annulation', AnnulationRendezVousController::class)
                ->whereUuid('rendezVous')->middleware('throttle:30,1,annulation-medecin')->name('rendez-vous.annuler');
        });

        // Espace caisse (PAY-01, PAY-05 à PAY-09). Quatre yeux et plafonds
        // vérifiés par les services, jamais par l'écran seul.
        Route::middleware(['role:caisse', 'throttle:120,1,caisse'])->prefix('caisse')->name('caisse.')->group(function () {
            Route::get('/', AccueilCaisseController::class)->name('accueil');

            Route::get('/taux', [TauxController::class, 'index'])->name('taux');
            Route::post('/taux', [TauxController::class, 'store'])->name('taux.enregistrer');
            Route::post('/taux/{taux}/confirmation', [TauxController::class, 'confirmer'])->whereUuid('taux')->name('taux.confirmer');

            Route::get('/remboursements', [RemboursementController::class, 'index'])->name('remboursements');
            Route::post('/remboursements/geste', [RemboursementController::class, 'geste'])->name('remboursements.geste');
            Route::post('/remboursements/{remboursement}/validation', [RemboursementController::class, 'valider'])
                ->whereUuid('remboursement')->name('remboursements.valider');
            Route::post('/remboursements/{remboursement}/refus', [RemboursementController::class, 'refuser'])
                ->whereUuid('remboursement')->name('remboursements.refuser');
            // Simulation locale seulement : absente hors local et tests (et revérifiée par le contrôleur).
            if (SimulationPaiement::disponible()) {
                Route::post('/remboursements/{remboursement}/simulation', [RemboursementController::class, 'simuler'])
                    ->whereUuid('remboursement')->middleware('throttle:20,1')->name('remboursements.simuler');
            }

            Route::get('/journal', JournalController::class)->name('journal');

            Route::get('/reversements', [ReversementController::class, 'index'])->name('reversements');
            Route::post('/reversements', [ReversementController::class, 'store'])->name('reversements.preparer');
            Route::post('/reversements/{reversement}/paiement', [ReversementController::class, 'payer'])
                ->whereUuid('reversement')->name('reversements.payer');

            Route::get('/rapprochement', [RapprochementController::class, 'index'])->name('rapprochement');
            Route::post('/rapprochement/{ecart}/traitement', [RapprochementController::class, 'traiter'])
                ->whereUuid('ecart')->name('rapprochement.traiter');

            Route::get('/factures', [FactureController::class, 'index'])->name('factures');
            Route::get('/factures/{facture}/pdf', [FactureController::class, 'pdf'])->whereUuid('facture')->name('factures.pdf');
        });
    });
});
