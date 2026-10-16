<?php

namespace App\Http\Controllers;

use App\Models\FactureConvention;
use App\Models\LigneFactureConvention;
use App\Models\RejetConvention;
use App\Models\RelanceConvention;
use App\Services\RecouvrementService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Recouvrement : le tableau de bord, et le suivi d'un bordereau après son
 * émission — dépôt, rejets, relances.
 *
 * Réservé à la facturation et à la direction. Le caissier encaisse les
 * règlements ; il ne suit pas les créances.
 */
class RecouvrementController extends Controller
{
    public function __construct(private readonly RecouvrementService $recouvrement) {}

    public function index(Request $request): View
    {
        $this->autoriser();

        $mois = $this->mois($request->query('mois'));
        $debut = $request->filled('debut') ? $this->date($request->query('debut'), $mois->copy()->startOfMonth()) : $mois->copy()->startOfMonth();
        $fin = $request->filled('fin') ? $this->date($request->query('fin'), $mois->copy()->endOfMonth()) : $mois->copy()->endOfMonth();

        if ($fin->lt($debut)) {
            [$debut, $fin] = [$fin, $debut];
        }

        return view('recouvrement.index', [
            ...$this->recouvrement->tableau($debut, $fin),
            'mois' => $mois,
            'tranches' => RecouvrementService::TRANCHES,
        ]);
    }

    /** Le dépôt chez l'assureur fait courir le délai de paiement. */
    public function depot(Request $request, FactureConvention $facture): RedirectResponse
    {
        $this->autoriser();

        $donnees = $request->validate([
            'date_depot' => 'required|date|before_or_equal:today',
            'accuse_reception' => 'nullable|string|max:100',
        ], [
            'date_depot.required' => 'Indiquez la date du dépôt.',
            'date_depot.before_or_equal' => 'Un dépôt ne se date pas dans le futur.',
        ]);

        $facture->update($donnees);
        $facture->refresh()->load('assurance');

        return back()->with('success', 'Dépôt du '.$facture->date_depot->format('d/m/Y').' enregistré : échéance de paiement le '
            .$facture->echeance()->format('d/m/Y').'.');
    }

    /** Enregistre ce que l'assureur refuse de payer, et pourquoi. */
    public function rejet(Request $request, FactureConvention $facture): RedirectResponse
    {
        $this->autoriser();

        $donnees = $request->validate([
            'ligne_id' => ['required', 'uuid', Rule::exists('lignes_facture_convention', 'id')->where('facture_convention_id', $facture->id)],
            'motif_categorie' => ['required', Rule::in(array_keys(RejetConvention::MOTIFS))],
            'motif' => 'nullable|string|max:1000',
            'montant' => 'nullable|numeric|min:0.01',
            'date_rejet' => 'required|date|before_or_equal:today',
        ], [
            'ligne_id.required' => 'Choisissez la facture rejetée.',
            'motif_categorie.required' => 'Choisissez le motif du rejet.',
        ]);

        $ligne = LigneFactureConvention::findOrFail($donnees['ligne_id']);

        // Le bordereau est dans sa devise : la part de la ligne s'y convertit
        // comme son total l'a été.
        $taux = max((float) $facture->taux_change, 0.0001);
        $partLigne = round((float) $ligne->part_assurance / $taux, 2);
        $dejaRejete = (float) $facture->rejets()->where('ligne_facture_convention_id', $ligne->id)
            ->where('statut', '!=', 'leve')->sum('montant');
        $montant = round((float) ($donnees['montant'] ?? ($partLigne - $dejaRejete)), 2);

        if ($montant <= 0 || $montant > $partLigne - $dejaRejete + 0.009) {
            return back()->withInput()->with('error',
                'Le rejet ne peut dépasser ce que le bordereau réclame pour cette facture ('
                .number_format(max(0, $partLigne - $dejaRejete), 2, ',', ' ').' '.$facture->devise.').');
        }

        RejetConvention::create([
            'facture_convention_id' => $facture->id,
            'ligne_facture_convention_id' => $ligne->id,
            'facture_id' => $ligne->facture_id,
            'motif_categorie' => $donnees['motif_categorie'],
            'motif' => $donnees['motif'] ?? null,
            'montant' => $montant,
            'date_rejet' => $donnees['date_rejet'],
            'statut' => 'ouvert',
            'saisi_par' => auth()->id(),
        ]);

        $this->rafraichirStatut($facture);

        return back()->with('success', 'Rejet enregistré : '.number_format($montant, 2, ',', ' ').' '.$facture->devise
            .' sortent de ce que l\'assureur doit, tant qu\'il n\'est pas levé.');
    }

    /** Suite donnée au rejet : contesté, levé ou abandonné. */
    public function traiterRejet(Request $request, RejetConvention $rejet): RedirectResponse
    {
        $this->autoriser();

        $donnees = $request->validate([
            'statut' => ['required', Rule::in(['conteste', 'leve', 'abandonne'])],
            'suite_donnee' => 'required|string|min:3|max:1000',
        ], [
            'suite_donnee.required' => 'Écrivez la suite donnée : pièce redéposée, courrier de contestation…',
        ]);

        $rejet->update([...$donnees, 'traite_le' => now(), 'traite_par' => auth()->id()]);
        $this->rafraichirStatut($rejet->factureConvention);

        return back()->with('success', 'Rejet « '.$rejet->libelleMotif().' » : '.mb_strtolower($rejet->libelleStatut()).'.');
    }

    public function relance(Request $request, FactureConvention $facture): RedirectResponse
    {
        $this->autoriser();

        $donnees = $request->validate([
            'date_relance' => 'required|date|before_or_equal:today',
            'mode' => ['required', Rule::in(array_keys(RelanceConvention::MODES))],
            'commentaire' => 'nullable|string|max:1000',
            'prochaine_action' => 'nullable|date|after:date_relance',
        ], [
            'prochaine_action.after' => 'La prochaine action vient après la relance.',
        ]);

        RelanceConvention::create([...$donnees, 'facture_convention_id' => $facture->id, 'user_id' => auth()->id()]);

        return back()->with('success', 'Relance enregistrée'
            .(filled($donnees['prochaine_action'] ?? null)
                ? ' ; prochaine action le '.Carbon::parse($donnees['prochaine_action'])->format('d/m/Y').'.'
                : '.'));
    }

    /** Un rejet change ce qui reste dû : le statut du bordereau suit. */
    private function rafraichirStatut(FactureConvention $facture): void
    {
        $facture->refresh();

        if ($facture->statut === 'annulee') {
            return;
        }

        // Un bordereau entièrement rejeté n'est pas « réglé » : il n'a rien
        // reçu. Il reste émis, sans plus rien d'attendu.
        $regle = (float) $facture->montant_regle > 0;
        $solde = $regle && $facture->resteDu() <= 0.01;
        $facture->update([
            'statut' => $solde ? 'reglee' : ($regle ? 'partiellement_reglee' : 'emise'),
            'date_reglement' => $solde ? ($facture->date_reglement ?? now()) : null,
        ]);
    }

    private function mois(?string $mois): Carbon
    {
        try {
            return $mois ? Carbon::createFromFormat('Y-m', $mois)->startOfMonth() : now()->startOfMonth();
        } catch (\Throwable) {
            return now()->startOfMonth();
        }
    }

    private function date(?string $valeur, Carbon $defaut): Carbon
    {
        try {
            return Carbon::parse($valeur);
        } catch (\Throwable) {
            return $defaut;
        }
    }

    private function autoriser(): void
    {
        $user = auth()->user();

        abort_unless(
            $user?->can('rapport.financier.view') || $user?->can('facture.annuler'),
            403,
            'Le recouvrement est suivi par la facturation et la direction.'
        );
    }
}
