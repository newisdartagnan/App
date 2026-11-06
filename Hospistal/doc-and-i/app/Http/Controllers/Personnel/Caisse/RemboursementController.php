<?php

namespace App\Http\Controllers\Personnel\Caisse;

use App\Enums\StatutRemboursement;
use App\Models\Facture;
use App\Models\Remboursement;
use App\Services\Paiement\RemboursementService;
use App\Services\Paiement\SimulationPaiement;
use App\Support\Montant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** PAY-07 : remboursements à valider, refus motivés, gestes commerciaux. */
class RemboursementController extends ControleurCaisse
{
    public function __construct(private readonly RemboursementService $remboursements) {}

    public function index(Request $request): Response
    {
        $moi = $request->user()->id;
        $numero = mb_strtoupper(trim((string) $request->query('numero', '')));
        $simulation = SimulationPaiement::disponible();

        $presenter = fn (Remboursement $r) => [
            'id' => $r->id,
            'facture' => $r->paiement->facture?->numero,
            'patient' => self::nom($r->paiement->patient),
            'montant' => Montant::afficher((string) $r->montant, $r->devise),
            'moyen' => $r->paiement->moyen->libelle(),
            'motif' => $r->motif->libelle(),
            'justification' => $r->justification,
            'demandePar' => $r->demande_par ? self::nom($r->demandeur) : 'Système (règle d\'annulation)',
            'demandeLe' => self::quand($r->created_at),
            'statut' => $r->statut->libelle(),
            'motifRefus' => $r->motif_refus,
            // Quatre yeux : le demandeur ne voit pas de bouton « Valider » sur sa demande.
            'peutValider' => $r->statut === StatutRemboursement::AValider && $r->demande_par !== $moi,
            // Développement seulement : déclencher le rappel signé de l'opérateur.
            'simulable' => $simulation && $r->statut === StatutRemboursement::EnCours && $r->reference_fournisseur !== null,
        ];

        $relations = ['paiement.facture', 'paiement.patient', 'demandeur'];

        return Inertia::render('Personnel/Caisse/Remboursements', [
            'aValider' => Remboursement::with($relations)->where('statut', StatutRemboursement::AValider->value)
                ->orderBy('created_at')->limit(200)->get()->map($presenter)->all(),
            // À suivre : bloqués (relances épuisées, autre prestataire) et refusés par
            // l'opérateur, remboursements de règle (RG-03, RG-04/05) compris.
            'aSuivre' => Remboursement::with($relations)
                ->where(fn ($q) => $q->where('statut', StatutRemboursement::Echoue->value)
                    ->orWhere(fn ($q) => $q->where('statut', StatutRemboursement::Valide->value)->whereNotNull('blocage')))
                ->orderByDesc('updated_at')->limit(100)->get()
                ->map(fn (Remboursement $r) => $presenter($r) + [
                    'raison' => $r->blocage ?? 'Refusé par l\'opérateur : '.$r->motif_echec,
                    'automatique' => $r->automatique,
                ])->all(),
            'recents' => Remboursement::with($relations)->where('statut', '!=', StatutRemboursement::AValider->value)
                ->orderByDesc('updated_at')->limit(30)->get()->map($presenter)->all(),
            'numero' => $numero,
            'paiementTrouve' => $numero === '' ? null : $this->paiementParFacture($numero),
        ]);
    }

    public function valider(Request $request, string $remboursement): RedirectResponse
    {
        $r = Remboursement::findOrFail($remboursement);

        return $this->action(function () use ($r, $request) {
            $valide = $this->remboursements->valider($r, $request->user());
            // Envoi immédiat au prestataire, hors transaction ; sinon la tâche
            // planifiée de la minute suivante s'en charge.
            $envoye = $this->remboursements->executer($valide);

            // Seul un refus explicite est un refus ; une erreur technique n'en est pas un.
            return match (true) {
                $envoye->statut === StatutRemboursement::Echoue => 'Remboursement validé, mais refusé par l\'opérateur : '.$envoye->motif_echec,
                $envoye->blocage !== null => 'Remboursement validé, à traiter à la main : '.$envoye->blocage,
                $envoye->statut === StatutRemboursement::Valide => 'Remboursement validé ; l\'opérateur n\'a pas répondu, nouvel essai automatique dans quelques minutes.',
                default => 'Remboursement validé et envoyé à l\'opérateur.',
            };
        }, 'remboursement', 'Remboursement validé.');
    }

    public function refuser(Request $request, string $remboursement): RedirectResponse
    {
        $r = Remboursement::findOrFail($remboursement);
        $request->validate(['motif' => ['required', 'string', 'max:255']]);

        return $this->action(
            fn () => $this->remboursements->refuser($r, $request->user(), $request->input('motif')),
            'motif', 'Remboursement refusé : le montant redevient remboursable.',
        );
    }

    /**
     * Développement seulement (pilote « simulation », local ou tests) : le
     * rappel signé que l'opérateur enverrait pour ce remboursement. Route
     * absente ailleurs, et revérifiée ici.
     */
    public function simuler(Request $request, string $remboursement, SimulationPaiement $simulation): RedirectResponse
    {
        abort_unless(SimulationPaiement::disponible(), 404);
        $r = Remboursement::findOrFail($remboursement);
        $donnees = $request->validate(['resultat' => ['required', 'in:reussi,echoue']]);

        if ($r->statut !== StatutRemboursement::EnCours || $r->reference_fournisseur === null) {
            return back()->withErrors(['remboursement' => 'Ce remboursement n\'attend pas de réponse de l\'opérateur.']);
        }

        $simulation->declencherRemboursement($r, $donnees['resultat'] === 'reussi');

        return back()->with('succes', $donnees['resultat'] === 'reussi'
            ? 'Simulation : remboursement confirmé par l\'opérateur.'
            : 'Simulation : remboursement refusé par l\'opérateur.');
    }

    /** Geste commercial sur un paiement retrouvé par le numéro de sa facture. */
    public function geste(Request $request): RedirectResponse
    {
        $valides = $request->validate([
            'numero' => ['required', 'string', 'max:30'],
            'montant' => ['required', 'string', 'regex:/^\d{1,10}([.,]\d{1,2})?$/'],
            'justification' => ['required', 'string', 'max:255'],
        ], ['montant.regex' => 'Montant illisible (exemple : 5 ou 5,50).']);

        $facture = Facture::where('numero', mb_strtoupper(trim($valides['numero'])))->where('type', 'facture')->first();
        if ($facture === null) {
            return back()->withErrors(['numero' => 'Aucune facture ne porte ce numéro.']);
        }

        return $this->action(
            fn () => $this->remboursements->gesteCommercial($facture->paiement, str_replace(',', '.', $valides['montant']), $request->user(), $valides['justification']),
            'montant', 'Geste commercial demandé : un autre agent de caisse doit le valider.', 'personnel.caisse.remboursements',
        );
    }

    private function paiementParFacture(string $numero): ?array
    {
        $facture = Facture::with('paiement.patient')->where('numero', $numero)->where('type', 'facture')->first();
        if ($facture === null) {
            return null;
        }

        $p = $facture->paiement;

        return [
            'facture' => $facture->numero,
            'patient' => self::nom($p->patient),
            'paye' => Montant::afficher((string) $p->montant, $p->devise),
            'resteRemboursable' => Montant::afficher(Montant::depuisCentimes($p->resteRemboursableEnCentimes()), $p->devise),
            'devise' => $p->devise,
            'moyen' => $p->moyen->libelle(),
        ];
    }
}
