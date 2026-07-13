<?php

namespace App\Http\Controllers;

use App\Models\ExamenLaboratoire;
use App\Models\TypeExamen;
use App\Models\Visit;
use App\Services\FacturationService;
use App\Services\LaboratoireService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class LaboratoireController extends Controller
{
    public function index(Request $request): View
    {
        $domaine = $request->get('domaine', 'labo');

        $examens = ExamenLaboratoire::with(['patient', 'prescripteur', 'resultats.typeExamen'])
            ->where('domaine', $domaine)
            ->orderByDesc('date_prescription')
            ->paginate(20)
            ->withQueryString();

        return view('labo.index', compact('examens', 'domaine'));
    }

    public function create(Request $request): View|RedirectResponse
    {
        if (! $request->visit_id) {
            return redirect()->route('visites.index')
                ->with('info', 'Ouvrez le parcours patient pour prescrire des examens.');
        }

        $visit = Visit::with('patient')->findOrFail($request->visit_id);
        $domaine = $request->get('domaine', 'labo');

        $types = TypeExamen::where('est_actif', true)
            ->when($domaine === 'imagerie', fn ($q) => $q->where('code', 'like', 'IMG-%'))
            ->when($domaine === 'labo', fn ($q) => $q->where('code', 'not like', 'IMG-%'))
            ->orderBy('categorie')
            ->orderBy('libelle')
            ->get();

        return view('labo.create', compact('visit', 'types', 'domaine'));
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'visit_id' => 'required|uuid|exists:visits,id',
            'domaine' => 'required|in:labo,imagerie',
            'types' => 'required|array|min:1',
            'types.*' => 'uuid|exists:types_examens,id',
        ]);

        $visit = Visit::findOrFail($request->visit_id);

        $examen = app(LaboratoireService::class)->prescrireExamens(
            $visit,
            $request->types,
            $request->domaine,
            $request->boolean('urgence'),
            $request->observations
        );

        $facture = app(FacturationService::class)->creerFactureExamen($examen);

        return redirect()->route('caisse.show', $facture)
            ->with('success', 'Examens prescrits — facture émise au guichet.');
    }

    public function show(ExamenLaboratoire $examen): View
    {
        $examen->load(['patient', 'visit', 'prescripteur', 'resultats.typeExamen', 'facture']);

        return view('labo.show', compact('examen'));
    }

    public function saisirResultats(Request $request, ExamenLaboratoire $examen): RedirectResponse
    {
        // Hospitalisation : le patient est servi à crédit durant le séjour,
        // tout est réglé avant la sortie. Sinon : paiement guichet d'abord.
        $aCredit = $examen->visit?->serviACredit();

        if (! $aCredit && $examen->facture && $examen->facture->statut !== 'payee') {
            return back()->with('error', 'Paiement guichet requis avant saisie des résultats.');
        }

        $request->validate(['resultats' => 'required|array']);

        app(LaboratoireService::class)->saisirResultats($examen, $request->resultats);

        return back()->with('success', 'Résultats enregistrés.');
    }

    public function valider(Request $request, ExamenLaboratoire $examen): RedirectResponse
    {
        app(LaboratoireService::class)->valider($examen, $request->input('conclusion'));

        return back()->with('success', $examen->domaine === 'imagerie'
            ? 'Compte-rendu validé.'
            : 'Bilan validé par le biologiste.');
    }

    /**
     * Bon d'examen imprimable (remis au patient pour la caisse / le préleveur).
     */
    public function bon(ExamenLaboratoire $examen): View
    {
        $examen->load(['patient', 'visit', 'prescripteur', 'resultats.typeExamen', 'facture']);

        return view('labo.bon', compact('examen'));
    }

    /**
     * Bulletin de résultats (labo) ou compte-rendu (imagerie) imprimable.
     */
    public function bulletin(ExamenLaboratoire $examen): View
    {
        $examen->load(['patient', 'visit', 'prescripteur', 'laborantin', 'resultats.typeExamen', 'facture']);

        return view('labo.bulletin', compact('examen'));
    }
}
