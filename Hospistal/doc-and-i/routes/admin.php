<?php

/*
| Espace administrateur (ADM-01, ADM-02, CPT-05). Inclus depuis
| routes/personnel.php, dans la session complète du personnel, réservé au
| rôle admin_fonctionnel. Adresses /personnel/admin/…, noms personnel.admin.…
|
| Chaque action d'écriture écrit une ligne au journal d'administration
| (App\Services\Administration\JournalAdministration), dans sa transaction.
*/

use App\Http\Controllers\Personnel\Admin\AccueilAdminController;
use Illuminate\Support\Facades\Route;

Route::middleware(['role:admin_fonctionnel', 'throttle:administration'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', AccueilAdminController::class)->name('accueil');

    // Tableau de bord, spécialités et tarifs, partenaires (développeur A).
    require __DIR__.'/admin-referentiel.php';

    // Comptes du personnel, médecins, journal d'administration (développeur B).
    require __DIR__.'/admin-comptes.php';
});
