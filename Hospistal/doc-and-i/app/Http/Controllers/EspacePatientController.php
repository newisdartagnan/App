<?php

namespace App\Http\Controllers;

use App\Enums\StatutRendezVous;
use App\Models\RendezVous;
use App\Services\RendezVous\Affichage;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class EspacePatientController extends Controller
{
    public function __invoke(Request $request): Response
    {
        // Affichage seulement : la tuile « Prochain rendez-vous » (jour, heure, médecin ; jamais le motif).
        $prochain = RendezVous::with('medecin.user:id,prenom,nom')
            ->where('patient_id', $request->user()->id)
            ->where('statut', StatutRendezVous::Confirme->value)
            ->where('fin', '>=', now())
            ->orderBy('debut')
            ->first();

        return Inertia::render('Espace/Accueil', [
            'prenom' => $request->user()->prenom,
            'prochainRendezVous' => $prochain ? [
                'id' => $prochain->id,
                'jour' => Affichage::jour($prochain->debut),
                'heure' => Affichage::heure($prochain->debut),
                'medecin' => Affichage::medecin($prochain->medecin),
            ] : null,
        ]);
    }
}
