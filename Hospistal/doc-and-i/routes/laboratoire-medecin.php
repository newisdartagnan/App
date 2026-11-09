<?php

/*
| Laboratoire, côté médecin (PRE-04, PRE-07, PRE-08). Inclus depuis
| routes/personnel.php dans le groupe du rôle medecin : adresses
| /personnel/medecin/…, noms personnel.medecin.… Chaque service revérifie
| que le rendez-vous ou le bon est celui du médecin connecté.
*/

use App\Http\Controllers\Laboratoire\BonExamenMedecinController;
use App\Http\Controllers\Laboratoire\ResultatsSoignantController;
use Illuminate\Support\Facades\Route;

// Composant BonExamen de la salle et de la page « Note » (JSON, jamais en cache).
Route::prefix('/consultation/{rendezVous}')->whereUuid('rendezVous')->name('consultation.')->group(function () {
    Route::get('/examens', [BonExamenMedecinController::class, 'lister'])->middleware('throttle:120,1,lab-bons-lecture')->name('examens');
    Route::post('/examens', [BonExamenMedecinController::class, 'prescrire'])->middleware('throttle:20,1,lab-prescription')->name('examens.prescrire');
});

// Résultats à valider (PRE-07) et portail d'alertes (PRE-08).
Route::get('/resultats', [ResultatsSoignantController::class, 'aValider'])->name('resultats');
Route::get('/resultats/bons/{bon}', [ResultatsSoignantController::class, 'detail'])->whereUuid('bon')->name('resultats.bon');
Route::post('/resultats/{resultat}/validation', [ResultatsSoignantController::class, 'valider'])
    ->whereUuid('resultat')->middleware('throttle:60,1,lab-validation')->name('resultats.valider');
Route::get('/resultats/{resultat}/pdf', [ResultatsSoignantController::class, 'pdf'])
    ->whereUuid('resultat')->middleware('throttle:60,1,lab-pdf-medecin')->name('resultats.pdf');

Route::get('/alertes', [ResultatsSoignantController::class, 'alertes'])->name('alertes');
Route::post('/alertes/{alerte}/{etat}', [ResultatsSoignantController::class, 'marquerAlerte'])
    ->whereUuid('alerte')->whereIn('etat', ['lue', 'traitee'])->name('alertes.marquer');
