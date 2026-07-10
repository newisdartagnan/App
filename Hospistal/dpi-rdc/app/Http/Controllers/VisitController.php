<?php

namespace App\Http\Controllers;

use App\Models\Lit;
use App\Models\Service;
use App\Models\Visit;
use App\Services\FacturationService;
use App\Services\VisiteService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class VisitController extends Controller
{
    public function index(Request $request): View
    {
        $query = Visit::with(['patient', 'service', 'lit'])
            ->where('establishment_id', auth()->user()->establishment_id)
            ->orderByDesc('date_entree');

        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }
        if ($request->filled('statut')) {
            $query->where('statut', $request->statut);
        } else {
            $query->where('statut', 'en_cours');
        }

        $visites = $query->paginate(20)->withQueryString();

        return view('visites.index', compact('visites'));
    }

    public function show(Visit $visit): View
    {
        $visit->load([
            'patient', 'service', 'lit', 'consultations.user',
            'factures.lignes', 'examensLaboratoire.resultats.typeExamen',
            'actesCliniques.prescripteur',
        ]);

        $services = Service::where('establishment_id', $visit->establishment_id)
            ->whereIn('type', ['medecine', 'chirurgie', 'maternite', 'pediatrie'])
            ->where('is_active', true)
            ->with(['lits' => fn ($q) => $q->where('statut', 'libre')])
            ->get();

        $impayees = app(VisiteService::class)->facturesImpayees($visit);

        return view('visites.show', compact('visit', 'services', 'impayees'));
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

        $service->sortir($visit, $request->mode_sortie);

        return redirect()->route('visites.index', ['statut' => 'termine'])
            ->with('success', 'Sortie enregistrée — lit libéré.');
    }
}
