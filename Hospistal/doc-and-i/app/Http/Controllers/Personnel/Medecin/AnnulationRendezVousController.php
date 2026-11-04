<?php

namespace App\Http\Controllers\Personnel\Medecin;

use App\Services\RendezVous\ReservationRefusee;
use App\Services\RendezVous\ReservationService;
use App\Sms\EnvoyeurSms;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * RG-05 : le médecin annule un rendez-vous confirmé. Le patient est remboursé
 * intégralement (taux 100, exécuté par le lot paiement), sa demande redevient
 * ouverte pour qu'il choisisse un autre créneau, et il est prévenu par SMS.
 */
class AnnulationRendezVousController extends ControleurMedecin
{
    public function __invoke(Request $request, string $rendezVous, ReservationService $reservations, EnvoyeurSms $sms): RedirectResponse
    {
        // Le rendez-vous est cherché parmi ceux du médecin connecté : celui
        // d'un confrère répond 404, comme un identifiant inconnu.
        $rdv = $this->medecin($request)->rendezVous()->whereKey($rendezVous)->firstOrFail();

        $donnees = $request->validate(['motif' => ['required', 'string', 'max:255']], [
            'motif.required' => 'Indiquez le motif de l\'annulation : le patient en sera informé par l\'équipe.',
        ]);

        try {
            $rdv = $reservations->annulerParMedecin($rdv, $donnees['motif']);
        } catch (ReservationRefusee $refus) {
            throw ValidationException::withMessages(['motif' => $refus->getMessage()]);
        }

        // Ni motif ni spécialité dans le SMS (NOT-02) : seulement l'heure et la marche à suivre.
        $debut = $rdv->debut->setTimezone('Africa/Kinshasa');

        try {
            $sms->envoyer($rdv->patient->telephone, sprintf(
                'MEDIHOME : votre consultation du %s a %s est annulee par le medecin. Vous serez rembourse ; ouvrez l\'application pour choisir un autre creneau.',
                $debut->format('d/m'),
                $debut->format('H:i')
            ));
        } catch (Throwable $erreur) {
            // L'annulation est déjà enregistrée : une panne du fournisseur SMS ne
            // doit pas afficher une erreur au médecin, qui réessaierait en vain.
            // Le patient voit l'annulation dans l'application. Journal sans
            // numéro ni donnée médicale.
            Log::warning('SMS d\'annulation par le médecin non envoyé', [
                'rendez_vous' => $rdv->id,
                'erreur' => $erreur::class,
            ]);

            return redirect()->route('personnel.medecin.agenda')
                ->with('succes', 'Rendez-vous annulé, remboursement intégral prévu. Le SMS au patient n\'a pas pu partir : il verra l\'annulation dans l\'application.');
        }

        return redirect()->route('personnel.medecin.agenda')
            ->with('succes', 'Rendez-vous annulé. Le patient est prévenu par SMS et sera remboursé intégralement.');
    }
}
