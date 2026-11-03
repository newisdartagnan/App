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

Route::middleware('auth')->group(function () {
    Route::post('/deconnexion', [ConnexionController::class, 'destroy'])->name('deconnexion');

    Route::get('/profil', [ProfilController::class, 'edit'])->name('profil.modifier');
    Route::put('/profil', [ProfilController::class, 'update'])->name('profil.enregistrer');

    Route::middleware('profil.complet')->group(function () {
        Route::get('/consentement', [ConsentementController::class, 'show'])->name('consentement');
        Route::post('/consentement', [ConsentementController::class, 'store'])->name('consentement.accepter');
        Route::post('/consentement/retrait', [ConsentementController::class, 'destroy'])->name('consentement.retirer');

        Route::middleware('consentement')->group(function () {
            Route::get('/espace', EspacePatientController::class)->name('espace');
        });
    });
});
