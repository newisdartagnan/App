<?php

namespace App\Http\Controllers\Personnel\Medecin;

use App\Services\Personnel\AgendaMedecinService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AccueilMedecinController extends ControleurMedecin
{
    public function __invoke(Request $request, AgendaMedecinService $agenda): Response
    {
        $medecin = $this->medecin($request)->load('specialite', 'user');

        return Inertia::render('Personnel/Medecin/Accueil', [
            'medecin' => [
                'nom' => trim('Dr '.$medecin->user->prenom.' '.mb_strtoupper((string) $medecin->user->nom)),
                'specialite' => $medecin->specialite->nom,
                'numeroOrdre' => $medecin->numero_ordre,
                // CPT-05 : tant que l'Ordre n'est pas vérifié, aucun patient ne le voit.
                'verifie' => $medecin->ordre_verifie_le !== null,
                'actif' => $medecin->actif,
            ],
            'resume' => [
                'rendezVousAVenir' => $agenda->nombreAVenir($medecin),
                'plages' => $medecin->plages()->count(),
                'absencesAVenir' => $medecin->absences()->where('fin', '>', now())->count(),
            ],
        ]);
    }
}
