<?php

namespace App\Http\Controllers;

use App\Models\Service;
use App\Models\Visit;
use App\Services\FacturationService;
use App\Services\VisiteService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class VisitController extends Controller
{
    /**
     * Admissions et lits.
     *
     * Cet écran est celui de l'hospitalisation : les séjours et l'occupation
     * des lits. Les consultations externes ont leur propre file et n'ont rien
     * à faire ici — les mélanger ne faisait qu'allonger une liste que
     * personne ne lisait.
     */
    public function index(Request $request): View
    {
        $etablissement = auth()->user()->establishment_id;

        // Types relevant du séjour hospitalier, consultations exclues.
        $typesSejour = ['hospitalisation', 'chirurgie', 'accouchement'];
        $type = $request->query('type');

        $visites = Visit::with(['patient', 'service', 'lit', 'forfait'])
            ->where('establishment_id', $etablissement)
            ->whereIn('type', in_array($type, $typesSejour, true) ? [$type] : $typesSejour)
            ->when(
                ($statut = $request->query('statut', 'en_cours')) !== 'tous',
                fn ($q) => $q->where('statut', $statut)
            )
            ->when($request->filled('service_id'), fn ($q) => $q->where('service_id', $request->service_id))
            ->orderByDesc('date_entree')
            ->paginate(20)
            ->withQueryString();

        // Occupation des lits, service par service : la donnée que le cadre
        // de garde cherche en premier en arrivant sur cet écran.
        $services = Service::where('establishment_id', $etablissement)
            ->where('is_active', true)
            ->whereNotIn('type', ['labo', 'pharmacie'])
            ->withCount([
                'lits as lits_total' => fn ($q) => $q->where('is_active', true),
                'lits as lits_occupes' => fn ($q) => $q->where('is_active', true)->where('statut', 'occupe'),
            ])
            ->orderBy('nom')
            ->get();

        $totalLits = (int) $services->sum('lits_total');
        $totalOccupes = (int) $services->sum('lits_occupes');

        return view('visites.index', compact(
            'visites', 'services', 'totalLits', 'totalOccupes', 'typesSejour'
        ));
    }

    public function show(Visit $visit): View
    {
        $visit->load([
            'patient', 'service', 'lit', 'consultations.user',
            'factures.lignes', 'examensLaboratoire.resultats.typeExamen',
            'actesCliniques.prescripteur',
        ]);

        $services = Service::where('establishment_id', $visit->establishment_id)
            ->whereIn('type', ['medecine', 'chirurgie', 'maternite', 'pediatrie', 'reanimation', 'neonatologie'])
            ->where('is_active', true)
            ->with(['lits' => fn ($q) => $q->where('statut', 'libre')])
            ->get();

        $impayees = app(VisiteService::class)->facturesImpayees($visit);

        return view('visites.show', compact('visit', 'services', 'impayees'));
    }

    /**
     * Triage infirmier : motif + constantes vitales avant le médecin.
     */
    public function triage(Visit $visit): View
    {
        $visit->load(['patient', 'typeConsultation']);

        return view('visites.triage', compact('visit'));
    }

    public function triageStore(Request $request, Visit $visit): RedirectResponse
    {
        $donnees = $request->validate([
            'motif_consultation' => 'required|string|max:500',
            'symptomes_principaux' => 'nullable|string|max:1000',
            'poids_kg' => 'nullable|numeric|min:0.5|max:300',
            'taille_cm' => 'nullable|numeric|min:20|max:250',
            'temperature' => 'nullable|numeric|min:30|max:45',
            'tension_systolique' => 'nullable|integer|min:50|max:300',
            'tension_diastolique' => 'nullable|integer|min:30|max:200',
            'frequence_cardiaque' => 'nullable|integer|min:20|max:300',
            'frequence_respiratoire' => 'nullable|integer|min:5|max:90',
            'saturation_o2' => 'nullable|numeric|min:50|max:100',
        ], [
            'motif_consultation.required' => 'Le motif est obligatoire.',
        ]);

        $imc = null;
        if (! empty($donnees['poids_kg']) && ! empty($donnees['taille_cm'])) {
            $m = $donnees['taille_cm'] / 100;
            $imc = round($donnees['poids_kg'] / ($m * $m), 1);
        }

        $visit->update($donnees + [
            'imc' => $imc,
            'triage_fait_at' => now(),
            'triage_par' => auth()->id(),
        ]);

        return redirect()->route('consultations.index')
            ->with('success', 'Triage enregistré — le patient est prêt pour le médecin.');
    }

    public function hospitaliser(Request $request, Visit $visit): RedirectResponse
    {
        $request->validate([
            'service_id' => 'required|uuid|exists:services,id',
            'lit_id' => 'required|uuid|exists:lits,id',
        ]);

        app(VisiteService::class)->hospitaliser($visit, $request->service_id, $request->lit_id);

        return back()->with('success', 'Patient hospitalisé — lit assigné.');
    }

    public function facturerSejour(Visit $visit): RedirectResponse
    {
        if ($visit->type !== 'hospitalisation') {
            return back()->with('error', 'Cette visite n\'est pas une hospitalisation.');
        }

        $facture = app(FacturationService::class)->creerFactureHospitalisation($visit);

        if (! $facture) {
            return back()->with('info',
                'Rien de nouveau à facturer : les '.$visit->jours_factures
                .' journée(s) du séjour et les diètes servies sont déjà portées sur une facture.');
        }

        return redirect()->route('caisse.show', $facture)
            ->with('success', 'Facture hospitalisation émise.');
    }

    public function sortir(Request $request, Visit $visit): RedirectResponse
    {
        $request->validate([
            'mode_sortie' => 'required|in:gueri,ameliore,stationnaire,agrave,transfert,sortie_contre_avis,deces,inconnu',
        ]);

        $service = app(VisiteService::class);

        if ($service->facturesImpayees($visit) > 0) {
            return back()->with('error', 'Des factures sont encore impayées. Régler au guichet avant la sortie.');
        }

        if ($manquants = $service->prestationsNonFacturees($visit)) {
            return back()->with('error', 'Facturer le séjour avant la sortie : '.implode(' et ', $manquants).'.');
        }

        $service->sortir($visit, $request->mode_sortie);

        return redirect()->route('visites.index', ['statut' => 'termine'])
            ->with('success', 'Sortie enregistrée — lit libéré.');
    }
}
