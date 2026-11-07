<?php

/*
| Dossier médical lu et complété par un médecin traitant (DOS-01, DOS-03,
| DOS-04). Inclus depuis routes/personnel.php, dans la session complète du
| personnel et le rôle medecin : adresses /personnel/medecin/…, noms
| personnel.medecin.… Le contrôleur vérifie la relation de soin avec CE
| patient à chaque requête (404 sinon) et note chaque lecture au journal.
*/

use App\Http\Controllers\Personnel\Medecin\DossierMedecinController;
use Illuminate\Support\Facades\Route;

Route::middleware('throttle:60,1,dossier-medecin')->group(function () {
    Route::get('/rendez-vous/{rendezVous}/dossier', [DossierMedecinController::class, 'depuisRendezVous'])
        ->whereUuid('rendezVous')->name('rendez-vous.dossier');

    Route::prefix('/patients/{patient}')->whereUuid('patient')->name('dossier')->group(function () {
        Route::get('/dossier', [DossierMedecinController::class, 'voir'])->name('');
        Route::post('/dossier/antecedents', [DossierMedecinController::class, 'ajouterAntecedent'])->name('.antecedents');
        Route::post('/dossier/antecedents/{antecedent}/retrait', [DossierMedecinController::class, 'retirerAntecedent'])
            ->whereUuid('antecedent')->name('.antecedents.retirer');
        Route::post('/dossier/constantes', [DossierMedecinController::class, 'ajouterConstante'])->name('.constantes');
        Route::post('/dossier/groupe-sanguin', [DossierMedecinController::class, 'groupeSanguin'])->name('.groupe-sanguin');
        Route::post('/dossier/rectifications/{demande}/reponse', [DossierMedecinController::class, 'repondreRectification'])
            ->whereUuid('demande')->name('.rectifications.repondre');
    });
});
