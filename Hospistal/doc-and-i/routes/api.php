<?php

/*
| API (préfixe /api, groupe « api » : sans session ni cookie).
|
| PRE-06 : dépôt de résultats par le logiciel d'un laboratoire partenaire,
| authentifié par un jeton Bearer émis par l'administration (empreinte
| seulement, révocable). Documentation : docs/api-laboratoire.md.
*/

use App\Http\Controllers\Laboratoire\ApiResultatsController;
use App\Http\Middleware\JetonLaboratoireRequis;
use Illuminate\Support\Facades\Route;

Route::prefix('laboratoire/v1')->name('api.laboratoire.')->middleware(JetonLaboratoireRequis::class)->group(function () {
    Route::post('/resultats', [ApiResultatsController::class, 'deposer'])->name('resultats');
});
