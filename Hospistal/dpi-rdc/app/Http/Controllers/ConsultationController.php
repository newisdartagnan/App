<?php
namespace App\Http\Controllers;

use App\Models\Consultation;
use App\Models\Facture;
use App\Models\Patient;
use App\Models\Visit;
use App\Services\FacturationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class ConsultationController extends Controller
{
    public function index(): View
    {
        return view('consultations.index');
    }

    public function create(Patient $patient): View
    {
        return view('consultations.create', compact('patient'));
    }

    public function show(Consultation $consultation): View
    {
        $consultation->load(['visit.patient', 'visit.factures', 'user']);
        $factureConsult = $consultation->visit->factures()
            ->whereHas('lignes', fn ($q) => $q->where('type', 'consultation'))
            ->first();
        return view('consultations.show', compact('consultation', 'factureConsult'));
    }

    public function facturer(Consultation $consultation): RedirectResponse
    {
        $visit = $consultation->visit;

        $existante = Facture::where('visit_id', $visit->id)
            ->whereHas('lignes', fn ($q) => $q->where('type', 'consultation'))
            ->whereIn('statut', ['emise', 'partiellement_payee', 'payee'])
            ->first();

        if ($existante) {
            return redirect()->route('caisse.show', $existante)
                ->with('info', 'Facture consultation déjà émise.');
        }

        $facture = app(FacturationService::class)->creerFactureConsultation($visit);

        return redirect()->route('caisse.show', $facture)
            ->with('success', 'Facture consultation émise — patient au guichet.');
    }
}