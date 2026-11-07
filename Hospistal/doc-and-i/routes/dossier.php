<?php

/*
| Dossier médical, côté patient (DOS-01, DOS-03, DOS-04). Inclus depuis
| routes/web.php dans le groupe auth + role:patient + profil.complet +
| consentement. Tout porte sur le patient connecté. Aucune donnée de santé
| dans les adresses : seulement des identifiants.
*/

use App\Http\Controllers\Dossier\MonDossierController;
use Illuminate\Support\Facades\Route;

Route::prefix('/mon-dossier')->name('dossier')->middleware('throttle:60,1,dossier-patient')->group(function () {
    Route::get('/', [MonDossierController::class, 'index'])->name('');
    Route::post('/antecedents', [MonDossierController::class, 'ajouterAntecedent'])->name('.antecedents');
    Route::post('/antecedents/{antecedent}/retrait', [MonDossierController::class, 'retirerAntecedent'])
        ->whereUuid('antecedent')->name('.antecedents.retirer');
    Route::post('/constantes', [MonDossierController::class, 'ajouterConstante'])->name('.constantes');
    Route::post('/groupe-sanguin', [MonDossierController::class, 'groupeSanguin'])->name('.groupe-sanguin');
    Route::post('/rectifications', [MonDossierController::class, 'demanderRectification'])->name('.rectifications');
    Route::get('/pdf', [MonDossierController::class, 'pdf'])->name('.pdf');
});
