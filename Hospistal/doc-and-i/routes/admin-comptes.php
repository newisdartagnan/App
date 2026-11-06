<?php

// Routes de l'espace administrateur, incluses par routes/admin.php (préfixe /personnel/admin, noms personnel.admin.).
// Comptes du personnel, médecins, journal (ADM-01, ADM-02, CPT-05, CPT-06) ; développeur B.

use App\Http\Controllers\Personnel\Admin\JournalAdminController;
use App\Http\Controllers\Personnel\Admin\MedecinAdminController;
use App\Http\Controllers\Personnel\Admin\PersonnelAdminController;
use Illuminate\Support\Facades\Route;

Route::group([], function () {
    Route::get('/personnel', [PersonnelAdminController::class, 'index'])->name('personnel');
    Route::get('/personnel/nouveau', [PersonnelAdminController::class, 'create'])->name('personnel.nouveau');
    Route::post('/personnel', [PersonnelAdminController::class, 'store'])->name('personnel.creer');
    Route::get('/personnel/{user}', [PersonnelAdminController::class, 'show'])->whereUuid('user')->name('personnel.fiche');
    Route::post('/personnel/{user}/lien-activation', [PersonnelAdminController::class, 'emettreLien'])->whereUuid('user')->name('personnel.lien');
    Route::post('/personnel/{user}/desactivation', [PersonnelAdminController::class, 'desactiver'])->whereUuid('user')->name('personnel.desactiver');
    Route::post('/personnel/{user}/reactivation', [PersonnelAdminController::class, 'reactiver'])->whereUuid('user')->name('personnel.reactiver');

    Route::post('/personnel/{user}/ordre', [MedecinAdminController::class, 'verifierOrdre'])->whereUuid('user')->name('personnel.ordre');
    Route::put('/personnel/{user}/medecin', [MedecinAdminController::class, 'modifier'])->whereUuid('user')->name('personnel.medecin');

    Route::get('/journal', JournalAdminController::class)->name('journal');
});
