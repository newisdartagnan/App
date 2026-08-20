<?php

namespace App\Http\Controllers;

use App\Models\Assurance;
use App\Models\Billetage;
use App\Models\FactureConvention;
use App\Services\ConventionService;
use App\Services\DeviseService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Facturation aux sociétés et conventions, et contrôle de caisse.
 */
class ConventionController extends Controller
{
    public function __construct(protected ConventionService $conventions) {}

    public function index(Request $request): View
    {
        $assuranceId = $request->query('assurance_id');
        $debut = $request->query('debut', now()->startOfMonth()->toDateString());
        $fin = $request->query('fin', now()->endOfMonth()->toDateString());

        $assurances = Assurance::where('est_actif', true)->orderBy('nom')->get();
        $assurance = $assuranceId ? Assurance::find($assuranceId) : null;

        $aRefacturer = $assurance
            ? $this->conventions->facturesARefacturer($assurance, $debut, $fin)
            : collect();

        $facturesConvention = FactureConvention::with(['assurance', 'lignes'])
            ->when($assurance, fn ($q) => $q->where('assurance_id', $assurance->id))
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();

        return view('conventions.index', compact(
            'assurances', 'assurance', 'debut', 'fin', 'aRefacturer', 'facturesConvention'
        ));
    }

    public function emettre(Request $request): RedirectResponse
    {
        $request->validate([
            'assurance_id' => 'required|uuid|exists:assurances,id',
            'debut' => 'required|date',
            'fin' => 'required|date|after_or_equal:debut',
            'mode' => 'required|in:collective,individuelle',
            'devise' => 'required|in:CDF,USD,EUR',
            'taux_change' => 'nullable|numeric|min:0.0001',
        ]);

        try {
            $facture = $this->conventions->emettre(
                Assurance::findOrFail($request->assurance_id),
                $request->debut,
                $request->fin,
                $request->mode,
                $request->devise,
                (float) ($request->taux_change ?: 1)
            );
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('conventions.show', $facture)
            ->with('success', "Facture {$facture->numero} émise.");
    }

    public function show(FactureConvention $facture): View
    {
        $facture->load(['assurance', 'lignes.patient', 'lignes.facture', 'reglements.encaissePar', 'emisePar']);

        return view('conventions.show', compact('facture'));
    }

    public function imprimer(FactureConvention $facture): View
    {
        $facture->load(['assurance', 'lignes.patient', 'lignes.facture', 'emisePar']);

        return view('conventions.imprimer', compact('facture'));
    }

    public function regler(Request $request, FactureConvention $facture): RedirectResponse
    {
        $request->validate([
            'montant' => 'required|numeric|min:0.01',
            'mode_paiement' => 'required|string|max:30',
            'reference' => 'nullable|string|max:100',
        ]);

        try {
            $this->conventions->enregistrerReglement(
                $facture,
                (float) $request->montant,
                $request->mode_paiement,
                $request->reference
            );
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Règlement enregistré.');
    }

    /**
     * Dettes à recouvrer, toutes conventions confondues.
     */
    public function dettes(): View
    {
        $dettes = $this->conventions->dettesParConvention();

        return view('conventions.dettes', compact('dettes'));
    }

    // ══════════════════════════════════════════════════════════════
    // Billetage
    // ══════════════════════════════════════════════════════════════

    public function billetage(Request $request): View
    {
        $devise = $request->query('devise', 'CDF');
        $debut = $request->query('debut', now()->startOfDay()->format('Y-m-d\TH:i'));
        $fin = $request->query('fin', now()->format('Y-m-d\TH:i'));

        $historique = Billetage::with('caissier')
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();

        // Le théorique se calcule devise par devise : on compte un tiroir
        // de francs, pas un agrégat de francs, de dollars et d'euros.
        $theorique = $this->conventions->recettesEspeces(
            str_replace('T', ' ', $debut).':00',
            str_replace('T', ' ', $fin).':59',
            $devise
        );

        return view('caisse.billetage', [
            'devise' => $devise,
            'devises' => app(DeviseService::class)->referentiel(),
            'debut' => $debut,
            'fin' => $fin,
            'coupures' => Billetage::coupuresPour($devise),
            'historique' => $historique,
            'theorique' => $theorique,
        ]);
    }

    public function storeBilletage(Request $request): RedirectResponse
    {
        $request->validate([
            'devise' => 'required|'.app(DeviseService::class)->regleValidation(),
            'debut' => 'required|date',
            'fin' => 'required|date|after_or_equal:debut',
            'coupures' => 'required|array',
            'coupures.*' => 'nullable|integer|min:0',
            'observation' => 'nullable|string|max:500',
        ]);

        $billetage = $this->conventions->enregistrerBilletage(
            $request->input('coupures', []),
            $request->devise,
            $request->debut,
            $request->fin,
            $request->observation
        );

        $devises = app(DeviseService::class);

        return back()->with('success', sprintf(
            'Billetage enregistré : %s comptés, écart de %s.',
            $devises->formater((float) $billetage->total_compte, $billetage->devise),
            $devises->formater((float) $billetage->ecart, $billetage->devise)
        ));
    }
}
