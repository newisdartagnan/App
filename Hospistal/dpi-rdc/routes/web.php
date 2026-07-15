<?php

use App\Http\Controllers\ActeCliniqueController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\CaisseController;
use App\Http\Controllers\ConsultationController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\LaboratoireController;
use App\Http\Controllers\PatientController;
use App\Http\Controllers\PharmacieController;
use App\Http\Controllers\PrescriptionController;
use App\Http\Controllers\VisitController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return auth()->check() ? redirect()->route('dashboard') : redirect()->route('login');
});

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'show'])->name('login');
    Route::post('/login', [LoginController::class, 'login']);
});

Route::post('/logout', [LoginController::class, 'logout'])->middleware('auth')->name('logout');

Route::middleware(['auth'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // Patients
    Route::get('/patients', [PatientController::class, 'index'])->name('patients.index');
    Route::get('/patients-recherche', [PatientController::class, 'recherche'])->name('patients.recherche');
    Route::get('/patients/nouveau', [PatientController::class, 'create'])->name('patients.create');
    Route::post('/patients', [PatientController::class, 'store'])->name('patients.store');
    Route::get('/patients/{patient}', [PatientController::class, 'show'])->name('patients.show');
    Route::post('/patients/{patient}/envoyer-caisse', [PatientController::class, 'envoyerCaisse'])->name('patients.envoyer-caisse');

    // Consultations — workflow caisse-first : le médecin consulte une visite payée
    Route::get('/patients/{patient}/consultation', [ConsultationController::class, 'create'])->name('consultations.create');
    Route::get('/visites/{visit}/consulter', [ConsultationController::class, 'consulter'])->name('visites.consulter');
    Route::get('/consultations', [ConsultationController::class, 'index'])->name('consultations.index');
    Route::get('/consultations/{consultation}', [ConsultationController::class, 'show'])->name('consultations.show');
    Route::post('/consultations/{consultation}/facturer', [ConsultationController::class, 'facturer'])->name('consultations.facturer');

    // Visites / hospitalisation
    Route::get('/visites', [VisitController::class, 'index'])->name('visites.index');
    Route::get('/visites/{visit}', [VisitController::class, 'show'])->name('visites.show');
    Route::post('/visites/{visit}/hospitaliser', [VisitController::class, 'hospitaliser'])->name('visites.hospitaliser');
    Route::post('/visites/{visit}/facturer-sejour', [VisitController::class, 'facturerSejour'])->name('visites.facturer-sejour');
    Route::post('/visites/{visit}/sortir', [VisitController::class, 'sortir'])->name('visites.sortir');

    // Laboratoire
    Route::get('/labo', [LaboratoireController::class, 'index'])->name('labo.index');
    Route::get('/labo/prescrire', [LaboratoireController::class, 'create'])->name('labo.create');
    Route::post('/labo', [LaboratoireController::class, 'store'])->name('labo.store');
    Route::get('/labo/{examen}', [LaboratoireController::class, 'show'])->name('labo.show');
    Route::get('/labo/{examen}/bon', [LaboratoireController::class, 'bon'])->name('labo.bon');
    Route::get('/labo/{examen}/bulletin', [LaboratoireController::class, 'bulletin'])->name('labo.bulletin');
    Route::post('/labo/{examen}/resultats', [LaboratoireController::class, 'saisirResultats'])->name('labo.resultats');
    Route::post('/labo/{examen}/valider', [LaboratoireController::class, 'valider'])->name('labo.valider');

    // Imagerie (même contrôleur, domaine imagerie)
    Route::get('/imagerie', fn () => app(LaboratoireController::class)->index(request()->merge(['domaine' => 'imagerie'])))->name('imagerie.index');
    Route::get('/imagerie/prescrire', fn () => app(LaboratoireController::class)->create(request()->merge(['domaine' => 'imagerie'])))->name('imagerie.create');

    // Bloc opératoire
    Route::get('/bloc', fn () => app(ActeCliniqueController::class)->index(request()->merge(['domaine' => 'chirurgie'])))->name('bloc.index');
    Route::get('/bloc/nouveau', fn () => app(ActeCliniqueController::class)->create(request()->merge(['domaine' => 'chirurgie'])))->name('bloc.create');
    Route::post('/bloc', [ActeCliniqueController::class, 'store'])->name('bloc.store');

    // Maternité
    Route::get('/maternite', fn () => app(ActeCliniqueController::class)->index(request()->merge(['domaine' => 'maternite'])))->name('maternite.index');
    Route::get('/maternite/nouveau', fn () => app(ActeCliniqueController::class)->create(request()->merge(['domaine' => 'maternite'])))->name('maternite.create');
    Route::post('/maternite', [ActeCliniqueController::class, 'store'])->name('maternite.store');

    Route::post('/actes/{acte}/realiser', [ActeCliniqueController::class, 'realiser'])->name('actes.realiser');
    Route::post('/actes/{acte}/facturer', [ActeCliniqueController::class, 'facturer'])->name('actes.facturer');

    // Prescriptions
    Route::get('/consultations/{consultation}/prescrire', [PrescriptionController::class, 'create'])->name('prescriptions.create');

    // Pharmacie
    Route::get('/pharmacie', [PharmacieController::class, 'dashboard'])->name('pharmacie.dashboard');
    Route::get('/pharmacie/stock', [PharmacieController::class, 'stock'])->name('pharmacie.stock');
    Route::get('/pharmacie/prescriptions', [PharmacieController::class, 'prescriptions'])->name('pharmacie.prescriptions');
    Route::get('/pharmacie/prescriptions/{prescription}', [PharmacieController::class, 'showPrescription'])->name('pharmacie.prescription');
    Route::get('/pharmacie/medicaments', [PharmacieController::class, 'medicaments'])->name('pharmacie.medicaments');

    // Caisse
    Route::get('/caisse', [CaisseController::class, 'index'])->name('caisse.index');
    Route::get('/caisse/{facture}', [CaisseController::class, 'show'])->name('caisse.show');
    Route::get('/caisse/{facture}/imprimer', [CaisseController::class, 'imprimer'])->name('caisse.imprimer');
    Route::post('/caisse/facturer/{prescription}', [CaisseController::class, 'facturer'])->name('caisse.facturer');
    Route::post('/caisse/prescription/{prescription}', [CaisseController::class, 'creerDepuisPrescription'])->name('caisse.prescription');
});
