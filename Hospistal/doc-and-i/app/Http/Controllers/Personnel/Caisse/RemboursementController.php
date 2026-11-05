<?php

namespace App\Http\Controllers\Personnel\Caisse;

use App\Enums\StatutRemboursement;
use App\Models\Facture;
use App\Models\Remboursement;
use App\Services\Paiement\RemboursementService;
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
        ];

        $relations = ['paiement.facture', 'paiement.patient', 'demandeur'];

        return Inertia::render('Personnel/Caisse/Remboursements', [
            'aValider' => Remboursement::with($relations)->where('statut', StatutRemboursement::AValider->value)
                ->orderBy('created_at')->limit(200)->get()->map($presenter)->all(),
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

            return $envoye->statut === StatutRemboursement::Echoue
                ? 'Remboursement validé, mais refusé par l\'opérateur : '.$envoye->motif_echec
                : 'Remboursement validé et envoyé à l\'opérateur.';
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
