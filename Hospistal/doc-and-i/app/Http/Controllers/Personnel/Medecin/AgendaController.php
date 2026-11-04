<?php

namespace App\Http\Controllers\Personnel\Medecin;

use App\Services\Personnel\AgendaMedecinService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Agenda du médecin connecté : ses rendez-vous à venir, en lecture seule. */
class AgendaController extends ControleurMedecin
{
    public function __invoke(Request $request, AgendaMedecinService $agenda): Response
    {
        return Inertia::render('Personnel/Medecin/Agenda', [
            'rendezVous' => $agenda->aVenir($this->medecin($request)),
        ]);
    }
}
