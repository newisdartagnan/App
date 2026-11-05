<?php

namespace App\Http\Controllers\Paiement;

use App\Enums\MoyenPaiement;
use App\Enums\StatutPaiement;
use App\Enums\StatutRendezVous;
use App\Http\Controllers\Controller;
use App\Models\Paiement;
use App\Models\RendezVous;
use App\Services\Paiement\PaiementRefuse;
use App\Services\Paiement\PaiementService;
use App\Services\Paiement\SimulationPaiement;
use App\Services\Paiement\TarificationService;
use App\Services\RendezVous\Affichage;
use App\Services\RendezVous\ReservationService;
use App\Support\Montant;
use App\Support\Telephone;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as ReponseHttp;

/**
 * PAY-02, PAY-03 : le patient paie son rendez-vous en attente, puis suit la
 * confirmation. La page relit le statut ; seul le rappel signé de l'opérateur
 * le change (PAY-04).
 */
class PaiementPatientController extends Controller
{
    public function payer(Request $request, RendezVous $rendezVous, ReservationService $reservations, TarificationService $tarifs): Response|RedirectResponse
    {
        abort_unless($rendezVous->patient_id === $request->user()->id, 404);
        $reservations->expirerAttentesEchues(null, $rendezVous->patient_id);
        $rdv = $rendezVous->fresh(['medecin.user', 'typeConsultation']);

        if ($enCours = $this->tentativeEnCours($rdv)) {
            return redirect()->route('paiement.suivi', $enCours);
        }

        if ($rdv->statut !== StatutRendezVous::EnAttentePaiement) {
            return redirect()->route('rdv.voir', $rdv)->withErrors(['paiement' => $rdv->statut === StatutRendezVous::Confirme
                ? 'Ce rendez-vous est déjà payé.'
                : 'Ce rendez-vous n\'attend plus de paiement.']);
        }

        $devises = [];
        foreach (config('doc-and-i.paiement.devises') as $devise) {
            try {
                $tarif = $tarifs->pourRendezVous($rdv, $devise);
                $devises[] = ['code' => $devise, 'montant' => Montant::afficher($tarif->montant, $devise)];
            } catch (PaiementRefuse) {
                // Pas de taux du jour : cette devise n'est pas proposée.
            }
        }

        $telephone = $request->user()->telephone;

        return Inertia::render('Paiement/Payer', [
            'rendezVous' => Affichage::resume($rdv),
            'devises' => $devises,
            'moyens' => array_map(fn (MoyenPaiement $m) => [
                'valeur' => $m->value,
                'libelle' => $m->libelle(),
                'mobile_money' => $m->estMobileMoney(),
            ], MoyenPaiement::cases()),
            // Le numéro du compte, au format que le patient connaît (0812345678).
            'telephone' => $telephone ? '0'.substr($telephone, 4) : '',
            'secondesRestantes' => max(0, $rdv->expire_le->getTimestamp() - CarbonImmutable::now()->getTimestamp()),
            // RG-02 : clé d'idempotence donnée par le serveur ; un double envoi la réutilise.
            'cle' => (string) Str::uuid(),
        ]);
    }

    public function initier(Request $request, RendezVous $rendezVous, PaiementService $paiements): ReponseHttp
    {
        abort_unless($rendezVous->patient_id === $request->user()->id, 404);

        $donnees = $request->validate([
            'moyen' => ['required', Rule::enum(MoyenPaiement::class)],
            'devise' => ['required', Rule::in(config('doc-and-i.paiement.devises'))],
            'telephone' => ['nullable', 'string', 'max:30'],
            'cle' => ['required', 'string', 'max:64'],
        ], [
            'moyen.required' => 'Choisissez comment payer.',
            'moyen.enum' => 'Choisissez comment payer.',
            'devise.required' => 'Choisissez la monnaie.',
            'devise.in' => 'Choisissez la monnaie.',
        ]);

        try {
            $tentative = $paiements->initier(
                $rendezVous,
                $request->user(),
                MoyenPaiement::from($donnees['moyen']),
                $donnees['devise'],
                $donnees['telephone'] ?? null,
                $donnees['cle'],
            );
        } catch (PaiementRefuse $refus) {
            return back()->withErrors(['paiement' => $refus->getMessage()]);
        }

        // Carte : authentification 3-D Secure sur la page du prestataire.
        if ($url = $tentative->initiation?->urlRedirection) {
            return Inertia::location($url);
        }

        return redirect()->route('paiement.suivi', $tentative->paiement);
    }

    public function suivi(Request $request, Paiement $paiement): Response
    {
        abort_unless($paiement->patient_id === $request->user()->id, 404);
        $paiement->load(['rendezVous.medecin.user', 'rendezVous.typeConsultation', 'facture', 'remboursements']);
        $rdv = $paiement->rendezVous;

        $peutReessayer = $rdv
            && in_array($paiement->statut, [StatutPaiement::Echoue, StatutPaiement::Expire], true)
            && $rdv->statut === StatutRendezVous::EnAttentePaiement
            && $rdv->expire_le > CarbonImmutable::now()
            && ! $this->tentativeEnCours($rdv);

        return Inertia::render('Paiement/Suivi', [
            'paiement' => [
                'id' => $paiement->id,
                'statut' => $paiement->statut->value,
                'statut_libelle' => $paiement->statut->libelle(),
                'en_cours' => $paiement->statut->estEnCours(),
                'montant' => Montant::afficher((string) $paiement->montant, $paiement->devise),
                'moyen' => $paiement->moyen->libelle(),
                'mobile_money' => $paiement->moyen->estMobileMoney(),
                'telephone' => $paiement->telephone_payeur ? Telephone::masquer($paiement->telephone_payeur) : null,
                'motif_echec' => $paiement->motif_echec,
                'facture' => $paiement->facture ? ['id' => $paiement->facture->id, 'numero' => $paiement->facture->numero] : null,
                'remboursements' => $paiement->remboursements->map(fn ($r) => [
                    'montant' => Montant::afficher((string) $r->montant, $r->devise),
                    'statut' => $r->statut->libelle(),
                    'motif' => $r->motif->libelle(),
                ])->values(),
            ],
            'rendezVous' => $rdv ? Affichage::resume($rdv) : null,
            'peutReessayer' => $peutReessayer,
            'simulation' => SimulationPaiement::disponible() && $paiement->statut->estEnCours(),
        ]);
    }

    /** Développement seulement : déclenche le rappel signé de la simulation. */
    public function simuler(Request $request, Paiement $paiement, SimulationPaiement $simulation): RedirectResponse
    {
        abort_unless(SimulationPaiement::disponible(), 404);
        abort_unless($paiement->patient_id === $request->user()->id, 404);

        $donnees = $request->validate(['resultat' => ['required', Rule::in(['reussi', 'echoue'])]]);

        if (! $paiement->statut->estEnCours()) {
            return back()->withErrors(['paiement' => 'Ce paiement n\'est plus en attente.']);
        }

        $simulation->declencher($paiement, $donnees['resultat'] === 'reussi');

        return redirect()->route('paiement.suivi', $paiement);
    }

    private function tentativeEnCours(RendezVous $rdv): ?Paiement
    {
        return Paiement::where('rendez_vous_id', $rdv->id)->whereIn('statut', StatutPaiement::enCours())->first();
    }
}
