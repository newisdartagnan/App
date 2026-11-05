<?php

use App\Http\Controllers\Auth\ConnexionController;
use App\Http\Controllers\ConsentementController;
use App\Http\Controllers\EspacePatientController;
use App\Http\Controllers\ProfilController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', fn () => Inertia::render('Accueil'))->name('accueil');

Route::middleware('guest')->group(function () {
    Route::get('/connexion', [ConnexionController::class, 'create'])->name('connexion');
    Route::post('/connexion', [ConnexionController::class, 'demander'])
        ->middleware('throttle:connexion-demande')->name('connexion.demander');
    Route::get('/connexion/code', [ConnexionController::class, 'code'])->name('connexion.code');
    Route::post('/connexion/code/renvoi', [ConnexionController::class, 'renvoyer'])
        ->middleware('throttle:connexion-renvoi')->name('connexion.renvoyer');
    Route::post('/connexion/code', [ConnexionController::class, 'verifier'])
        ->middleware('throttle:connexion-verification')->name('connexion.verifier');
});

Route::middleware('auth')->post('/deconnexion', [ConnexionController::class, 'destroy'])->name('deconnexion');

// Espace patient : le personnel a son propre espace (routes/personnel.php).
Route::middleware(['auth', 'role:patient'])->group(function () {
    Route::get('/profil', [ProfilController::class, 'edit'])->name('profil.modifier');
    Route::put('/profil', [ProfilController::class, 'update'])->name('profil.enregistrer');

    Route::middleware('profil.complet')->group(function () {
        Route::get('/consentement', [ConsentementController::class, 'show'])->name('consentement');
        Route::post('/consentement', [ConsentementController::class, 'store'])->name('consentement.accepter');
        Route::post('/consentement/retrait', [ConsentementController::class, 'destroy'])->name('consentement.retirer');

        Route::middleware('consentement')->group(function () {
            Route::get('/espace', EspacePatientController::class)->name('espace');

            // Demande de consultation et rendez-vous du patient (lot RDV).
            require __DIR__.'/rendezvous.php';

            // Paiement, reçus et factures du patient (lot paiement).
            require __DIR__.'/paiement.php';
        });
    });
});

// Rappel serveur de l'agrégateur de paiement : hors connexion, signé.
require __DIR__.'/paiement-rappel.php';

// Comptes du personnel et espace médecin (lot RDV).
require __DIR__.'/personnel.php';
