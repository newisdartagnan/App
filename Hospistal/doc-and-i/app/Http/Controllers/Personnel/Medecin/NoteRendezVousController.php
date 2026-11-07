<?php

namespace App\Http\Controllers\Personnel\Medecin;

use App\Services\Dossier\AccesDossier;
use App\Services\Personnel\DisponibilitesMedecinService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * DOS-02, PRE-01 : page « Note » d'un rendez-vous, hors de la salle (depuis
 * l'agenda, consultation terminée) : la note, ses avenants, les ordonnances.
 * Les composants revérifient chaque lecture et écriture sur le serveur.
 */
class NoteRendezVousController extends ControleurMedecin
{
    public function __invoke(Request $request, string $rendezVous, AccesDossier $acces): Response
    {
        // Cherché parmi les rendez-vous du médecin connecté : celui d'un confrère répond 404.
        $rdv = $this->medecin($request)->rendezVous()->whereKey($rendezVous)->with('patient:id,nom,prenom')->firstOrFail();
        abort_unless($acces->medecinDuRendezVous($request->user(), $rdv), 404);

        $debut = $rdv->debut->setTimezone(DisponibilitesMedecinService::FUSEAU);

        return Inertia::render('Personnel/Medecin/Note', [
            'rendezVous' => [
                'id' => $rdv->id,
                'quand' => $debut->locale('fr')->translatedFormat('l j F Y').', '.$debut->format('H:i'),
                'patient' => trim($rdv->patient->prenom.' '.mb_strtoupper((string) $rdv->patient->nom)),
                'statut' => $rdv->statut->libelle(),
            ],
        ]);
    }
}
