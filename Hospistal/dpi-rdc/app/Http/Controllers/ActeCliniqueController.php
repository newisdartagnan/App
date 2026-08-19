<?php

namespace App\Http\Controllers;

use App\Models\ActeClinique;
use App\Models\TypeConsultation;
use App\Models\User;
use App\Models\Visit;
use App\Services\FacturationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ActeCliniqueController extends Controller
{
    /**
     * Salle d'opération / maternité sur le modèle du programme GPS :
     * demandes à planifier, programme planifié, puis actes réalisés.
     */
    public function index(Request $request): View
    {
        $domaine = $request->get('domaine', 'chirurgie');

        $actes = ActeClinique::with(['patient', 'visit.service', 'prescripteur', 'operateur'])
            ->where('domaine', $domaine)
            ->orderByDesc('created_at')
            ->get();

        $programme = [
            // Demandes reçues, pas encore inscrites au programme
            'demandes' => $actes->where('statut', 'planifie')->whereNull('date_prevue'),
            // Programme opératoire daté
            'planifies' => $actes->where('statut', 'planifie')->whereNotNull('date_prevue')
                ->sortBy('date_prevue'),
            // Actes réalisés (registre)
            'realises' => $actes->whereIn('statut', ['realise', 'facture'])->take(50),
        ];

        $operateurs = User::role(['medecin', 'infirmier_chef'])
            ->orderBy('nom')
            ->get(['id', 'nom', 'prenom']);

        return view('actes.index', compact('actes', 'domaine', 'programme', 'operateurs'));
    }

    /**
     * Inscrire une demande au programme opératoire (date, opérateur, durée).
     */
    public function planifier(Request $request, ActeClinique $acte): RedirectResponse
    {
        $request->validate([
            'date_prevue' => 'required|date',
            'operateur_id' => 'nullable|uuid|exists:users,id',
            'duree_minutes' => 'nullable|integer|min:5|max:1440',
            'indication' => 'nullable|string|max:255',
        ]);

        if (! $acte->visit?->peutRecevoirServices()) {
            return back()->with('error', 'Séjour terminé — programmation impossible.');
        }

        $acte->update([
            'date_prevue' => $request->date_prevue,
            'operateur_id' => $request->operateur_id,
            'duree_minutes' => $request->duree_minutes,
            'indication' => $request->indication,
            'consentement' => $request->boolean('consentement'),
            'urgence' => $request->boolean('urgence'),
        ]);

        return back()->with('success', 'Acte inscrit au programme opératoire.');
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
            'examen_specialise' => TypeConsultation::where('categorie', 'specialisee')
                ->where('est_actif', true)
                ->orderBy('libelle')
                ->get()
                ->map(fn ($tc) => [
                    'libelle' => 'Examen spécialisé '.$tc->libelle,
                    'prix' => $tc->prixCdf(),
                ])->all(),
            'dialyse' => [
                ['libelle' => 'Séance d\'hémodialyse (4 h)', 'prix' => $tarifs['dialyse_seance'] ?? 120000],
                ['libelle' => 'Séance d\'hémodialyse avec érythropoïétine', 'prix' => $tarifs['dialyse_seance_epo'] ?? 165000],
                ['libelle' => 'Dialyse péritonéale — échange', 'prix' => $tarifs['dialyse_peritoneale'] ?? 60000],
                ['libelle' => 'Pose de cathéter de dialyse', 'prix' => $tarifs['dialyse_catheter'] ?? 180000],
                ['libelle' => 'Confection de fistule artério-veineuse', 'prix' => $tarifs['dialyse_fistule'] ?? 400000],
            ],
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
            'domaine' => 'required|in:chirurgie,maternite,examen_specialise,dialyse',
            'libelle' => 'required|string|max:255',
            'prix' => 'required|numeric|min:0',
            'compte_rendu' => 'nullable|string',
        ]);

        $visit = Visit::findOrFail($request->visit_id);

        if (! $visit->peutRecevoirServices()) {
            return back()->with('error', 'Séjour terminé — aucun nouvel acte possible.');
        }

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

        $route = match ($request->domaine) {
            'maternite' => 'maternite.index',
            'examen_specialise' => 'examens-specialises.index',
            'dialyse' => 'dialyse.index',
            default => 'bloc.index',
        };

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
