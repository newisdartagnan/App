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
        abort_unless(auth()->user()->can('consultation.create'), 403,
            'Réservé aux médecins — les infirmiers font le triage.');

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

    /**
     * Enregistrement de la consultation du médecin (formulaire classique,
     * aucune dépendance JavaScript — remplace le wizard Livewire).
     */
    public function store(\Illuminate\Http\Request $request, Visit $visit): RedirectResponse
    {
        if (! $visit->consultationPayee() && ! $visit->serviACredit()) {
            return redirect()->route('consultations.index')
                ->with('error', 'Consultation non réglée — le patient doit passer à la caisse.');
        }

        if ($existante = $visit->consultations()->first()) {
            return redirect()->route('consultations.show', $existante)
                ->with('info', 'La consultation de cette visite est déjà enregistrée.');
        }

        $donnees = $request->validate([
            'histoire_maladie' => 'nullable|string',
            'antecedents_personnels' => 'nullable|string',
            'antecedents_familiaux' => 'nullable|string',
            'allergies' => 'nullable|string',
            'traitements_en_cours' => 'nullable|string',
            'examen_general' => 'nullable|string',
            'conclusion' => 'nullable|string',
            'conduite_a_tenir' => 'nullable|string',
            'diagnostics' => 'nullable|array',
            'diagnostics.*.libelle' => 'nullable|string|max:255',
            'diagnostics.*.code_cim10' => 'nullable|string|max:20',
        ]);

        $diagnostics = [];
        foreach ($request->input('diagnostics', []) as $i => $diag) {
            if (blank($diag['libelle'] ?? null)) {
                continue;
            }
            $diagnostics[] = [
                'libelle' => trim($diag['libelle']),
                'code_cim10' => strtoupper(trim($diag['code_cim10'] ?? '')),
                'type' => $i === 0 ? 'principal' : 'associe',
            ];
        }

        $consultation = Consultation::create([
            'visit_id' => $visit->id,
            'user_id' => auth()->id(),
            'date_consultation' => now(),
            'type' => $visit->type === 'urgence' ? 'urgence' : ($visit->gratuite ? 'suivi' : 'initiale'),
            'histoire_maladie' => $donnees['histoire_maladie'] ?? null,
            'antecedents_personnels' => $donnees['antecedents_personnels'] ?? null,
            'antecedents_familiaux' => $donnees['antecedents_familiaux'] ?? null,
            'allergies' => $donnees['allergies'] ?? null,
            'traitements_en_cours' => array_filter([trim((string) ($donnees['traitements_en_cours'] ?? ''))]),
            'examen_general' => $donnees['examen_general'] ?? null,
            'diagnostics' => $diagnostics,
            'conclusion' => $donnees['conclusion'] ?? null,
            'conduite_a_tenir' => $donnees['conduite_a_tenir'] ?? null,
            'statut' => 'finalise',
        ]);

        $visit->update(['user_id' => auth()->id()]);

        return redirect()->route('consultations.show', $consultation)
            ->with('success', 'Consultation enregistrée — vous pouvez prescrire bilans et médicaments.');
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
