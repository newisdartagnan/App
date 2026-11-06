<?php

namespace App\Http\Controllers\Personnel\Medecin;

use App\Services\Personnel\DisponibilitesMedecinService;
use App\Services\Teleconsultation\AccesConsultation;
use App\Services\Teleconsultation\AccesRefuse;
use App\Services\Teleconsultation\SalleAppel;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * TLC-01 : salle de consultation, côté médecin. Minimum de ce lot : l'appel
 * vidéo. Le dossier, la messagerie et la fin de consultation viendront dans
 * l'emplacement prévu de la page (lot suivant).
 */
class ConsultationController extends ControleurMedecin
{
    public function __invoke(Request $request, string $rendezVous, AccesConsultation $acces, SalleAppel $salle): Response|RedirectResponse
    {
        // Cherché parmi les rendez-vous du médecin connecté : celui d'un confrère répond 404.
        $rdv = $this->medecin($request)->rendezVous()->whereKey($rendezVous)->with('patient:id,nom,prenom')->firstOrFail();

        try {
            [$consultation, $role] = $acces->entrer($request->user(), $rdv);
        } catch (AccesRefuse $refus) {
            return redirect()->route('personnel.medecin.agenda')->withErrors(['consultation' => $refus->getMessage()]);
        }

        $debut = $rdv->debut->setTimezone(DisponibilitesMedecinService::FUSEAU);

        return Inertia::render('Personnel/Medecin/Consultation', [
            'rendezVous' => [
                'id' => $rdv->id,
                'heure' => $debut->format('H:i').' – '.$rdv->fin->setTimezone(DisponibilitesMedecinService::FUSEAU)->format('H:i'),
                'patient' => trim($rdv->patient->prenom.' '.mb_strtoupper((string) $rdv->patient->nom)),
            ],
            'appel' => $salle->proprietes($consultation, $request->user(), $role, route('personnel.medecin.consultation.signaux', $rdv->id)),
        ]);
    }
}
