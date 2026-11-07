<?php

/*
| Ordonnances (PRE-01, PRE-03).
| - Côté patient : inclus depuis routes/web.php dans le groupe auth +
|   role:patient + profil.complet + consentement ; seulement les siennes.
| - Vérification publique (QR code du pharmacien) : voir routes/web.php,
|   hors connexion.
*/

use App\Http\Controllers\Dossier\MesOrdonnancesController;
use Illuminate\Support\Facades\Route;

Route::name('ordonnances.')->group(function () {
    Route::get('/mes-ordonnances', [MesOrdonnancesController::class, 'index'])->name('index');
    Route::get('/mes-ordonnances/{ordonnance}/pdf', [MesOrdonnancesController::class, 'pdf'])
        ->whereUuid('ordonnance')->middleware('throttle:30,1,pre-pdf-patient')->name('pdf');
});
