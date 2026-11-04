<?php

namespace App\Http\Controllers\RendezVous;

use App\Enums\StatutRendezVous;
use App\Http\Controllers\Controller;
use App\Models\DemandeConsultation;
use App\Models\Medecin;
use App\Models\RendezVous;
use App\Models\TypeConsultation;
use App\Services\RendezVous\Affichage;
use App\Services\RendezVous\CreneauxService;
use App\Services\RendezVous\ReservationRefusee;
use App\Services\RendezVous\ReservationService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** RDV-03 à RDV-05 : créneaux proposés et réservation avec blocage. */
class ReservationController extends Controller
{
    use ControleDuPatient;

    public const PREMIER_DISPONIBLE = 'premier';

    public function creneaux(Request $request, DemandeConsultation $demande, CreneauxService $creneaux): Response|RedirectResponse
    {
        $this->assurerProprietaire($request, $demande);

        if ($redirection = $this->redirectionSiNonReservable($demande)) {
            return $redirection;
        }

        $choix = $request->validate([
            'type' => ['required', 'uuid'],
            'medecin' => ['required', 'string'],
        ]);
        $versChoix = fn (string $message) => redirect()->route('rdv.demande.choix', $demande)->withErrors(['choix' => $message]);

        $type = TypeConsultation::where('actif', true)->find($choix['type']);
        if (! $type) {
            return $versChoix('Ce type de consultation n\'est pas proposé.');
        }

        if ($choix['medecin'] === self::PREMIER_DISPONIBLE) {
            $medecin = null;
            if ($type->prix === null) {
                return $versChoix('Le prix de cette consultation n\'est pas encore fixé : elle ne peut pas encore être réservée.');
            }
            $liste = $creneaux->premiersDisponibles($type);
        } else {
            $medecin = str($choix['medecin'])->isUuid() ? Medecin::with('user')->find($choix['medecin']) : null;
            try {
                $medecin ?? throw new ReservationRefusee('Ce médecin ne reçoit pas de rendez-vous en ce moment.');
                $creneaux->verifierOffre($medecin, $type);
            } catch (ReservationRefusee $e) {
                return $versChoix($e->getMessage());
            }
            $liste = array_map(fn ($debut) => ['debut' => $debut, 'medecin' => $medecin], $creneaux->creneaux($medecin, $type));
        }

        $attente = RendezVous::where('patient_id', $request->user()->id)
            ->where('statut', StatutRendezVous::EnAttentePaiement->value)
            ->where('expire_le', '>', CarbonImmutable::now())
            ->first();

        return Inertia::render('RendezVous/Creneaux', [
            'demande' => ['id' => $demande->id],
            'type' => [
                'id' => $type->id,
                'nom' => $type->nom,
                'duree_minutes' => $type->duree_minutes,
                'prix' => Affichage::montant($type->prix, $type->devise),
            ],
            'medecin' => $medecin ? ['id' => $medecin->id, 'nom' => Affichage::medecin($medecin)] : null,
            'jours' => Affichage::parJour($liste),
            'delaiPaiementMinutes' => (int) config('doc-and-i.rendez_vous.delai_paiement_minutes'),
            'attenteEnCours' => $attente?->id,
        ]);
    }

    public function reserver(Request $request, DemandeConsultation $demande, ReservationService $service): RedirectResponse
    {
        $this->assurerProprietaire($request, $demande);

        if ($demande->statut === 'orientee_urgence') {
            return redirect()->route('rdv.demande.urgence', $demande);
        }

        $donnees = $request->validate([
            'medecin_id' => ['required', 'uuid'],
            'type_consultation_id' => ['required', 'uuid'],
            'debut' => ['required', 'date'],
        ], [
            '*.required' => 'Choisissez un créneau.',
            '*.uuid' => 'Choisissez un créneau.',
            'debut.date' => 'Choisissez un créneau.',
        ]);

        $medecin = Medecin::find($donnees['medecin_id']);
        $type = TypeConsultation::find($donnees['type_consultation_id']);

        try {
            if (! $medecin || ! $type) {
                throw new ReservationRefusee('Ce créneau n\'est plus disponible. Choisissez-en un autre.');
            }
            $rdv = $service->reserver($demande, $medecin, $type, CarbonImmutable::parse($donnees['debut']));
        } catch (ReservationRefusee $e) {
            return back()->withErrors(['creneau' => $e->getMessage()]);
        }

        return redirect()->route('rdv.voir', $rdv)
            ->with('succes', 'Créneau réservé pour vous pendant '.config('doc-and-i.rendez_vous.delai_paiement_minutes').' minutes.');
    }
}
