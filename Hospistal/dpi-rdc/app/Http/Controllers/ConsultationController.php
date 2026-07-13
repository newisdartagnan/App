<?php
namespace App\Http\Controllers;

use App\Models\Consultation;
use App\Models\Facture;
use App\Models\Patient;
use App\Models\Visit;
use App\Services\FacturationService;
use App\Services\VisiteService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class ConsultationController extends Controller
{
    public function index(): View
    {
        return view('consultations.index');
    }

    /**
     * Ancien point d'entrée « nouvelle consultation depuis le patient ».
     * Workflow caisse-first : on redirige vers la visite active du patient
     * (payée → wizard) ou vers sa fiche pour l'envoyer à la caisse.
     */
    public function create(Patient $patient): RedirectResponse
    {
        $active = app(VisiteService::class)->visiteActive($patient);

        if ($active && ($active->consultationPayee() || $active->serviACredit())) {
            return redirect()->route('visites.consulter', $active);
        }

        return redirect()->route('patients.show', $patient)
            ->with('info', 'Le patient doit d\'abord régler la consultation à la caisse.');
    }

    /**
     * Le médecin démarre la consultation d'une visite payée au guichet.
     */
    public function consulter(Visit $visit): View|RedirectResponse
    {
        $visit->load('patient');

        if ($consultation = $visit->consultations()->first()) {
            return redirect()->route('consultations.show', $consultation)
                ->with('info', 'La consultation de cette visite est déjà enregistrée.');
        }

        if (! $visit->consultationPayee() && ! $visit->serviACredit()) {
            return redirect()->route('consultations.index')
                ->with('error', 'Consultation non réglée — le patient doit passer à la caisse avant de voir le médecin.');
        }

        return view('consultations.create', ['visit' => $visit, 'patient' => $visit->patient]);
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
