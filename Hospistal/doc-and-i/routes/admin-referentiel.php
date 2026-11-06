<?php

/*
| Espace administrateur, partie référentiel (ADM-01) — développeur A.
| Inclus par routes/admin.php : préfixe /personnel/admin, noms
| personnel.admin., session complète du personnel ET rôle admin_fonctionnel.
| Le tableau de bord (ADM-02) est la route d'accueil, dans routes/admin.php.
| Aucune suppression : spécialités, types et partenaires se désactivent.
*/

use App\Http\Controllers\Personnel\Admin\PartenairesController;
use App\Http\Controllers\Personnel\Admin\TarifsController;
use Illuminate\Support\Facades\Route;

Route::prefix('tarifs')->name('tarifs')->group(function () {
    Route::get('/', [TarifsController::class, 'index'])->name('');
    Route::post('/specialites/{specialite}/etat', [TarifsController::class, 'etatSpecialite'])
        ->whereUuid('specialite')->name('.specialite.etat');
    Route::post('/specialites/{specialite}/types', [TarifsController::class, 'creerType'])
        ->whereUuid('specialite')->name('.types.creer');
    Route::put('/types/{type}', [TarifsController::class, 'modifierType'])
        ->whereUuid('type')->name('.types.modifier');
});

Route::prefix('partenaires')->name('partenaires')->group(function () {
    Route::get('/', [PartenairesController::class, 'index'])->name('');
    Route::post('/', [PartenairesController::class, 'creer'])->name('.creer');
    Route::put('/{partenaire}', [PartenairesController::class, 'modifier'])->whereUuid('partenaire')->name('.modifier');
    Route::post('/{partenaire}/etat', [PartenairesController::class, 'etat'])->whereUuid('partenaire')->name('.etat');
});
