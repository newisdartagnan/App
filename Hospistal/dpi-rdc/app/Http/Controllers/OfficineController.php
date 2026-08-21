<?php

namespace App\Http\Controllers;

use App\Models\Medicament;
use App\Models\MouvementStock;
use App\Models\Officine;
use App\Models\Requisition;
use App\Models\StockMedicament;
use App\Services\OfficineService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Pharmacie à deux niveaux : officines (dispensation) et dépôt central
 * (approvisionnement), reliés par un circuit de réquisition.
 */
class OfficineController extends Controller
{
    public function __construct(protected OfficineService $officines) {}

    /**
     * Vue d'ensemble des officines.
     *
     * Le responsable de la pharmacie doit voir, d'un seul écran, ce que
     * chacune détient, ce qui lui manque, ce qu'elle a demandé et ce qu'elle
     * a sorti. Sans cette vue, contrôler l'ensemble demandait d'entrer dans
     * chaque officine l'une après l'autre.
     */
    public function tableau(Request $request): View
    {
        $debut = $request->query('debut', now()->startOfMonth()->toDateString());
        $fin = $request->query('fin', now()->toDateString());

        $officines = Officine::with('service')
            ->where('est_actif', true)
            ->orderByRaw("CASE type WHEN 'depot_central' THEN 0 WHEN 'ambulatoire' THEN 1 ELSE 2 END")
            ->orderBy('nom')
            ->get();

        // Un seul passage sur chaque table, puis on répartit : l'écran doit
        // rester rapide même avec dix officines et mille références.
        $stocks = StockMedicament::with('medicament')->get()->groupBy('officine_id');

        $sorties = MouvementStock::with('medicament')
            ->whereBetween('created_at', [$debut.' 00:00:00', $fin.' 23:59:59'])
            ->get()
            ->groupBy('officine_id');

        $requisitions = Requisition::with(['lignes', 'demandeur'])
            ->orderByDesc('date_demande')
            ->get()
            ->groupBy('officine_id');

        $lignes = $officines->map(function (Officine $officine) use ($stocks, $sorties, $requisitions) {
            $sonStock = $stocks[$officine->id] ?? collect();
            $sesMouvements = $sorties[$officine->id] ?? collect();
            $sesRequisitions = $requisitions[$officine->id] ?? collect();

            $enRupture = $sonStock->filter(fn ($s) => (float) $s->quantite_disponible <= 0);
            $sousAlerte = $sonStock->filter(fn ($s) => (float) $s->quantite_disponible > 0
                && (float) $s->quantite_disponible <= (float) $s->quantite_alerte);

            return [
                'officine' => $officine,
                'references' => $sonStock->count(),
                'unites' => (float) $sonStock->sum('quantite_disponible'),
                // Ce que vaut le tiroir, au prix de vente.
                'valeur' => (float) $sonStock->sum(fn ($s) => (float) $s->quantite_disponible * (float) $s->prix_unitaire_vente),
                'ruptures' => $enRupture->count(),
                'alertes' => $sousAlerte->count(),
                'sorties' => (float) $sesMouvements->filter(fn ($m) => str_starts_with($m->type, 'sortie'))->sum('quantite'),
                'entrees' => (float) $sesMouvements->filter(fn ($m) => in_array($m->type, ['entree', 'transfert_entree'], true))->sum('quantite'),
                'requisitions_ouvertes' => $sesRequisitions->whereIn('statut', ['envoyee', 'partiellement_servie'])->count(),
                'requisitions_periode' => $sesRequisitions->count(),
                'produits_en_rupture' => $enRupture->take(6)->map(fn ($s) => $s->medicament?->designation())->filter()->values(),
            ];
        });

        return view('officines.tableau', [
            'lignes' => $lignes,
            'debut' => $debut,
            'fin' => $fin,
            'active' => $this->officines->officineActive(),
            // Les demandes que le dépôt doit encore servir, tous services confondus.
            'requisitionsOuvertes' => Requisition::with(['officine', 'lignes.medicament', 'demandeur'])
                ->whereIn('statut', ['envoyee', 'partiellement_servie'])
                ->orderBy('date_demande')
                ->get(),
        ]);
    }

    /**
     * Choix de l'officine de travail — préalable obligatoire à l'affichage
     * d'un stock, comme dans le système de référence.
     */
    public function index(): View
    {
        $officines = Officine::with('service')
            ->where('est_actif', true)
            ->orderByRaw("CASE type WHEN 'depot_central' THEN 0 WHEN 'ambulatoire' THEN 1 ELSE 2 END")
            ->orderBy('nom')
            ->get();

        $active = $this->officines->officineActive();

        return view('officines.index', compact('officines', 'active'));
    }

    public function activer(Request $request, Officine $officine): RedirectResponse
    {
        $this->officines->definirOfficineActive($officine);

        return redirect()->route('officines.stock')
            ->with('success', "Officine active : {$officine->nom}.");
    }

    /**
     * Stock de l'officine active, avec ses alertes.
     */
    public function stock(Request $request): View|RedirectResponse
    {
        $officine = $this->officines->officineActive();

        if (! $officine) {
            return redirect()->route('officines.index')
                ->with('error', 'Prière de sélectionner une officine.');
        }

        $stocks = $this->officines->stock($officine, $request->query('q'));
        $medicaments = Medicament::where('est_actif', true)
            ->orderBy('denomination_commune')->get();

        $requisitions = Requisition::with(['lignes.medicament', 'demandeur'])
            ->where('officine_id', $officine->id)
            ->orderByDesc('date_demande')
            ->limit(10)
            ->get();

        $mouvements = MouvementStock::with(['medicament', 'user'])
            ->where('officine_id', $officine->id)
            ->orderByDesc('created_at')
            ->limit(15)
            ->get();

        return view('officines.stock', compact('officine', 'stocks', 'medicaments', 'requisitions', 'mouvements'));
    }

    /**
     * L'officine demande des produits au dépôt central.
     */
    public function storeRequisition(Request $request): RedirectResponse
    {
        $request->validate([
            'quantites' => 'required|array',
            'quantites.*' => 'nullable|numeric|min:0',
            'motif' => 'nullable|string|max:500',
        ]);

        $officine = $this->officines->officineActive();
        if (! $officine) {
            return redirect()->route('officines.index')->with('error', 'Prière de sélectionner une officine.');
        }

        try {
            $requisition = $this->officines->creerRequisition(
                $officine,
                $request->input('quantites', []),
                $request->input('motif')
            );
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Réquisition {$requisition->numero} envoyée au dépôt central.");
    }

    /**
     * Tableau de bord du dépôt central : demandes des officines, stock, entrées.
     */
    public function depot(Request $request): View|RedirectResponse
    {
        $depot = $this->officines->depotCentral();

        if (! $depot) {
            return redirect()->route('officines.index')
                ->with('error', "Aucun dépôt central n'est configuré.");
        }

        $requisitions = Requisition::with(['lignes.medicament', 'officine', 'demandeur'])
            ->whereIn('statut', ['envoyee', 'partiellement_servie'])
            ->orderBy('date_demande')
            ->get();

        $historique = Requisition::with('officine')
            ->whereIn('statut', ['servie', 'refusee'])
            ->orderByDesc('date_service')
            ->limit(15)
            ->get();

        $stocks = $this->officines->stock($depot, $request->query('q'));
        $medicaments = Medicament::where('est_actif', true)->orderBy('denomination_commune')->get();

        // Vue consolidée du stock de toutes les officines
        $stockOfficines = Officine::with(['stocks.medicament'])
            ->where('est_actif', true)
            ->where('type', '!=', 'depot_central')
            ->get()
            ->map(fn ($o) => [
                'officine' => $o,
                'references' => $o->stocks->where('quantite_disponible', '>', 0)->count(),
                'alertes' => $o->stocks->filter(fn ($s) => $s->quantite_disponible <= $s->quantite_alerte)->count(),
            ]);

        return view('officines.depot', compact(
            'depot', 'requisitions', 'historique', 'stocks', 'medicaments', 'stockOfficines'
        ));
    }

    /**
     * Le dépôt sert une réquisition, éventuellement partiellement.
     */
    public function servir(Request $request, Requisition $requisition): RedirectResponse
    {
        $request->validate([
            'servies' => 'required|array',
            'servies.*' => 'nullable|numeric|min:0',
        ]);

        $erreurs = $this->officines->servirRequisition($requisition, $request->input('servies', []));

        if ($erreurs !== []) {
            return back()->with('error', implode(' ', $erreurs));
        }

        return back()->with('success', "Réquisition {$requisition->numero} servie.");
    }

    public function refuser(Request $request, Requisition $requisition): RedirectResponse
    {
        $this->officines->refuserRequisition($requisition, $request->input('motif'));

        return back()->with('success', "Réquisition {$requisition->numero} refusée.");
    }

    /**
     * Entrée fournisseur au dépôt central.
     */
    public function entree(Request $request): RedirectResponse
    {
        $request->validate([
            'officine_id' => 'required|uuid|exists:officines,id',
            'medicament_id' => 'required|uuid|exists:medicaments,id',
            'quantite' => 'required|numeric|min:0.01',
            'provenance' => 'nullable|string|max:150',
            'lot' => 'nullable|string|max:100',
            'date_peremption' => 'nullable|date|after:today',
            'prix_unitaire_vente' => 'nullable|numeric|min:0',
        ]);

        $this->officines->entreeDepot(
            Officine::findOrFail($request->officine_id),
            Medicament::findOrFail($request->medicament_id),
            (float) $request->quantite,
            $request->provenance,
            $request->lot,
            $request->date_peremption,
            $request->filled('prix_unitaire_vente') ? (float) $request->prix_unitaire_vente : null
        );

        return back()->with('success', 'Entrée enregistrée en stock.');
    }
}
