<?php

/*
| Note de consultation et ordonnance, côté médecin (DOS-02, PRE-01 à
| PRE-03, RG-11). Inclus depuis routes/personnel.php, dans la session
| complète du personnel et le rôle medecin : adresses /personnel/medecin/…,
| noms personnel.medecin.… Chaque requête revérifie que le rendez-vous est
| le sien (RedactionConsultation).
*/

use App\Http\Controllers\Dossier\NoteConsultationController;
use App\Http\Controllers\Dossier\OrdonnanceMedecinController;
use App\Http\Controllers\Personnel\Medecin\NoteRendezVousController;
use Illuminate\Support\Facades\Route;

Route::prefix('/consultation/{rendezVous}')->whereUuid('rendezVous')->name('consultation.')->group(function () {
    Route::get('/note', [NoteConsultationController::class, 'etat'])->middleware('throttle:120,1,dos-note-lecture')->name('note');
    // Enregistrement automatique pendant l'appel : quelques envois par minute.
    Route::put('/note', [NoteConsultationController::class, 'enregistrer'])->middleware('throttle:60,1,dos-note-ecriture')->name('note.enregistrer');
    Route::post('/note/signature', [NoteConsultationController::class, 'signer'])->middleware('throttle:10,1,dos-note-signature')->name('note.signer');
    Route::post('/note/avenants', [NoteConsultationController::class, 'avenant'])->middleware('throttle:10,1,dos-avenant')->name('note.avenant');

    Route::get('/ordonnances', [OrdonnanceMedecinController::class, 'lister'])->middleware('throttle:120,1,pre-lecture')->name('ordonnances');
    Route::post('/ordonnances', [OrdonnanceMedecinController::class, 'emettre'])->middleware('throttle:20,1,pre-emission')->name('ordonnances.emettre');
});

Route::get('/rendez-vous/{rendezVous}/note', NoteRendezVousController::class)->whereUuid('rendezVous')->name('rendez-vous.note');
Route::get('/ordonnances/{ordonnance}/pdf', [OrdonnanceMedecinController::class, 'pdf'])
    ->whereUuid('ordonnance')->middleware('throttle:30,1,pre-pdf-medecin')->name('ordonnances.pdf');
