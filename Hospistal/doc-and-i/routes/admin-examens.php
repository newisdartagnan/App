<?php

/*
| Espace administrateur, partie laboratoire (PRE-04, PRE-06) — développeur B.
| Inclus par routes/admin.php : préfixe /personnel/admin, noms
| personnel.admin., session complète du personnel ET rôle admin_fonctionnel.
| Catalogue des examens (jamais supprimés : désactivés) et jetons d'API des
| laboratoires (affichés une fois, révocables). Tout est journalisé.
*/

use App\Http\Controllers\Personnel\Admin\ExamensController;
use App\Http\Controllers\Personnel\Admin\JetonsLaboratoireController;
use Illuminate\Support\Facades\Route;

Route::prefix('examens')->name('examens')->group(function () {
    Route::get('/', [ExamensController::class, 'index'])->name('');
    Route::post('/', [ExamensController::class, 'creer'])->name('.creer');
    Route::put('/{examen}', [ExamensController::class, 'modifier'])->whereUuid('examen')->name('.modifier');
    Route::post('/{examen}/etat', [ExamensController::class, 'etat'])->whereUuid('examen')->name('.etat');
});

Route::prefix('jetons-laboratoire')->name('jetons-laboratoire')->group(function () {
    Route::get('/', [JetonsLaboratoireController::class, 'index'])->name('');
    Route::post('/', [JetonsLaboratoireController::class, 'emettre'])->middleware('throttle:10,1,jetons-laboratoire')->name('.emettre');
    Route::post('/{jeton}/revocation', [JetonsLaboratoireController::class, 'revoquer'])->whereUuid('jeton')->name('.revoquer');
});
