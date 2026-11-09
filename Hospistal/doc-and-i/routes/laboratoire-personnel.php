<?php

/*
| Laboratoire, côté personnel non médecin. Inclus depuis routes/personnel.php
| dans la session complète du personnel.
|
| - /personnel/laboratoire (rôle laboratoire, PRE-05, PRE-06) : bons payés de
|   SON laboratoire, réception du patient (code de retrait), dépôt des
|   résultats. Compte ou laboratoire désactivé : 403.
| - /personnel/coordination (rôle coordinateur, PRE-08, RG-10) : escalades ;
|   validation à la place du médecin pour un bon en retard de 48 h.
*/

use App\Http\Controllers\Laboratoire\CoordinationController;
use App\Http\Controllers\Laboratoire\PortailLaboratoireController;
use App\Http\Controllers\Laboratoire\ResultatsSoignantController;
use Illuminate\Support\Facades\Route;

Route::middleware(['role:laboratoire', 'throttle:120,1,portail-laboratoire'])->prefix('laboratoire')->name('laboratoire.')->group(function () {
    Route::get('/', [PortailLaboratoireController::class, 'index'])->name('accueil');
    Route::get('/bons/{bon}', [PortailLaboratoireController::class, 'bon'])->whereUuid('bon')->name('bon');
    Route::post('/bons/{bon}/reception', [PortailLaboratoireController::class, 'recevoir'])
        ->whereUuid('bon')->middleware('throttle:20,1,lab-reception')->name('recevoir');
    Route::post('/bons/{bon}/resultats', [PortailLaboratoireController::class, 'deposer'])
        ->whereUuid('bon')->middleware('throttle:30,1,lab-depot')->name('deposer');
});

Route::middleware(['role:coordinateur', 'throttle:120,1,coordination'])->prefix('coordination')->name('coordination.')->group(function () {
    Route::get('/', [CoordinationController::class, 'index'])->name('accueil');
    Route::post('/escalades/{escalade}/traitee', [CoordinationController::class, 'traiter'])->whereUuid('escalade')->name('escalades.traiter');
    Route::get('/bons/{bon}', [ResultatsSoignantController::class, 'detail'])->whereUuid('bon')->name('bon');
    Route::post('/resultats/{resultat}/validation', [ResultatsSoignantController::class, 'valider'])->whereUuid('resultat')->name('resultats.valider');
    Route::get('/resultats/{resultat}/pdf', [ResultatsSoignantController::class, 'pdf'])->whereUuid('resultat')->name('resultats.pdf');
});
