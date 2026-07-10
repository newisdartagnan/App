<?php

namespace App\Http\Controllers;

use App\Models\ActeClinique;
use App\Models\Visit;
use App\Services\FacturationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ActeCliniqueController extends Controller
{
    public function index(Request $request): View
    {
        $domaine = $request->get('domaine', 'chirurgie');

        $actes = ActeClinique::with(['patient', 'visit', 'prescripteur'])
            ->where('domaine', $domaine)
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();

        return view('actes.index', compact('actes', 'domaine'));
    }

    public function create(Request $request): View|RedirectResponse
    {
        if (! $request->visit_id) {
            return redirect()->route('visites.index')
                ->with('info', 'Ouvrez le parcours patient pour planifier un acte.');
        }

        $visit = Visit::with('patient')->findOrFail($request->visit_id);
        $domaine = $request->get('domaine', 'chirurgie');
        $tarifs = config('dpi.tarifs_cdf', []);

        $catalogue = match ($domaine) {
            'maternite' => [
                ['libelle' => 'Accouchement voie basse', 'prix' => $tarifs['accouchement'] ?? 200000],
                ['libelle' => 'Césarienne', 'prix' => 350000],
                ['libelle' => 'Suivi prénatal complet', 'prix' => 80000],
            ],
            default => [
                ['libelle' => 'Petite chirurgie', 'prix' => $tarifs['chirurgie_minor'] ?? 150000],
                ['libelle' => 'Intervention sous anesthésie locale', 'prix' => 250000],
                ['libelle' => 'Suture complexe', 'prix' => 75000],
            ],
        };

        return view('actes.create', compact('visit', 'domaine', 'catalogue'));
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'visit_id' => 'required|uuid|exists:visits,id',
            'domaine' => 'required|in:chirurgie,maternite',
            'libelle' => 'required|string|max:255',
            'prix' => 'required|numeric|min:0',
            'compte_rendu' => 'nullable|string',
        ]);

        $visit = Visit::findOrFail($request->visit_id);

        $acte = ActeClinique::create([
            'visit_id' => $visit->id,
            'patient_id' => $visit->patient_id,
            'prescripteur_id' => auth()->id(),
            'domaine' => $request->domaine,
            'libelle' => $request->libelle,
            'prix' => $request->prix,
            'statut' => $request->filled('compte_rendu') ? 'realise' : 'planifie',
            'compte_rendu' => $request->compte_rendu,
            'date_realisation' => $request->filled('compte_rendu') ? now() : null,
        ]);

        if ($request->boolean('facturer')) {
            $facture = app(FacturationService::class)->creerFactureActeClinique($acte);

            return redirect()->route('caisse.show', $facture)
                ->with('success', 'Acte enregistré — facture au guichet.');
        }

        $route = $request->domaine === 'maternite' ? 'maternite.index' : 'bloc.index';

        return redirect()->route($route)->with('success', 'Acte planifié.');
    }

    public function realiser(Request $request, ActeClinique $acte): RedirectResponse
    {
        $request->validate(['compte_rendu' => 'required|string|min:10']);

        $acte->update([
            'statut' => 'realise',
            'compte_rendu' => $request->compte_rendu,
            'date_realisation' => now(),
        ]);

        return back()->with('success', 'Compte-rendu enregistré.');
    }

    public function facturer(ActeClinique $acte): RedirectResponse
    {
        if ($acte->facture_id) {
            return redirect()->route('caisse.show', $acte->facture_id)
                ->with('info', 'Facture déjà existante.');
        }

        $facture = app(FacturationService::class)->creerFactureActeClinique($acte);

        return redirect()->route('caisse.show', $facture)
            ->with('success', 'Facture acte émise.');
    }
}
