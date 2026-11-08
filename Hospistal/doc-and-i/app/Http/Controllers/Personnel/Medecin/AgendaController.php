<?php

namespace App\Http\Controllers\Personnel\Medecin;

use App\Services\Personnel\AgendaMedecinService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Agenda du médecin connecté : ses rendez-vous à venir, et ses consultations terminées récentes (note). */
class AgendaController extends ControleurMedecin
{
    public function __invoke(Request $request, AgendaMedecinService $agenda): Response
    {
        return Inertia::render('Personnel/Medecin/Agenda', [
            'rendezVous' => $agenda->aVenir($this->medecin($request)),
            // DOS-02 : consultations terminées récentes, pour rédiger ou signer la note après coup.
            'recentes' => $agenda->terminesRecents($this->medecin($request)),
        ]);
    }
}
