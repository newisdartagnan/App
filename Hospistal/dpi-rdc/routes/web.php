<?php
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\ConsultationController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\PatientController;
use App\Http\Controllers\PharmacieController;
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
    Route::get('/patients/nouveau', [PatientController::class, 'create'])->name('patients.create');
    Route::get('/patients/{patient}', [PatientController::class, 'show'])->name('patients.show');

    // Consultations
    Route::get('/patients/{patient}/consultation', [ConsultationController::class, 'create'])->name('consultations.create');
    Route::get('/consultations', [ConsultationController::class, 'index'])->name('consultations.index');
    Route::get('/consultations/{consultation}', [ConsultationController::class, 'show'])->name('consultations.show');

    // Pharmacie
    Route::get('/pharmacie', [PharmacieController::class, 'dashboard'])->name('pharmacie.dashboard');
    Route::get('/pharmacie/stock', [PharmacieController::class, 'stock'])->name('pharmacie.stock');
    Route::get('/pharmacie/prescriptions', [PharmacieController::class, 'prescriptions'])->name('pharmacie.prescriptions');
    Route::get('/pharmacie/prescriptions/{prescription}', [PharmacieController::class, 'showPrescription'])->name('pharmacie.prescription');
    Route::get('/pharmacie/medicaments', [PharmacieController::class, 'medicaments'])->name('pharmacie.medicaments');
});