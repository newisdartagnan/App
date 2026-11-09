<?php

/*
| Examens, côté patient (PRE-04, PRE-05, PRE-07, RG-01). Inclus depuis
| routes/web.php dans le groupe auth + role:patient + profil.complet +
| consentement. Seulement les bons du patient ; résultats VALIDÉS seulement.
*/

use App\Http\Controllers\Laboratoire\MesExamensController;
use Illuminate\Support\Facades\Route;

Route::name('examens.')->group(function () {
    Route::get('/mes-examens', [MesExamensController::class, 'index'])->name('index');
    Route::post('/mes-examens/{bon}/laboratoire', [MesExamensController::class, 'laboratoire'])->whereUuid('bon')->middleware('throttle:20,1,examens-labo')->name('laboratoire');
    Route::post('/mes-examens/{bon}/paiement', [MesExamensController::class, 'payer'])->whereUuid('bon')->middleware('throttle:10,1,examens-paiement')->name('payer');
    Route::post('/mes-examens/{bon}/code', [MesExamensController::class, 'code'])->whereUuid('bon')->middleware('throttle:10,1,examens-code')->name('code');
    Route::get('/mes-examens/resultats/{resultat}/pdf', [MesExamensController::class, 'pdf'])
        ->whereUuid('resultat')->middleware('throttle:30,1,examens-pdf')->name('pdf');
});
