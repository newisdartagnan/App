<?php

namespace App\Http\Controllers;

use App\Models\ActePlanificationFamiliale;
use App\Models\Establishment;
use App\Models\Patient;
use App\Models\Vaccination;
use App\Services\PreventionService;
use App\Services\SystemeSanteService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Les deux registres préventifs : planification familiale et vaccination.
 *
 * Ils vivaient entièrement sur papier. La PF se recomptait méthode par
 * méthode sur un cahier à colonnes ; le PEV se pointait au bâton sur une
 * fiche de comptage, et le carnet de l'enfant était le seul endroit où
 * savoir ce qu'il avait reçu — quand la mère ne l'avait pas perdu.
 */
class PreventionController extends Controller
{
    public function __construct(
        private readonly PreventionService $prevention,
        private readonly SystemeSanteService $systemes,
    ) {}

    /** Poser une méthode ou une dose, c'est un soin. */
    private function autoriser(): void
    {
        abort_unless(
            auth()->user()?->can('soin.execute'),
            403,
            'Les registres de prévention sont tenus par les soignants.'
        );
    }

    private function etablissementId(): ?string
    {
        return auth()->user()?->establishment_id
            ?? Establishment::orderBy('created_at')->value('id');
    }

    // ═══════════════════════════════════════════════════════════
    // Planification familiale
    // ═══════════════════════════════════════════════════════════

    public function pf(Request $request): View
    {
        $this->autoriser();

        $depuis = now()->startOfMonth();

        return view('prevention.pf', [
            'actes' => ActePlanificationFamiliale::query()
                ->with(['patient', 'auteur'])
                ->where('establishment_id', $this->etablissementId())
                ->where('date_acte', '>=', $depuis->toDateString())
                ->orderByDesc('date_acte')
                ->orderByDesc('created_at')
                ->limit(100)
                ->get(),
            'depuis' => $depuis,
            'systeme' => $this->systemes->definition(),
            'produite' => $this->systemes->produit('planification_familiale'),
        ]);
    }

    public function storePf(Request $request, Patient $patient): RedirectResponse
    {
        $this->autoriser();

        $donnees = $request->validate([
            'type_acte' => 'required|in:'.implode(',', array_keys(ActePlanificationFamiliale::TYPES_ACTE)),
            'methode' => 'nullable|in:'.implode(',', array_keys(ActePlanificationFamiliale::METHODES)),
            'type_acceptation' => 'required|in:'.implode(',', array_keys(ActePlanificationFamiliale::ACCEPTATIONS)),
            'canal' => 'required|in:'.implode(',', array_keys(ActePlanificationFamiliale::CANAUX)),
            'post_partum' => 'nullable|boolean',
            'date_acte' => 'nullable|date|before_or_equal:today',
            'observation' => 'nullable|string|max:1000',
        ]);

        try {
            $this->prevention->enregistrerActePf($patient, $donnees + [
                'post_partum' => $request->boolean('post_partum'),
            ]);
        } catch (\InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['methode' => $e->getMessage()]);
        }

        return redirect()
            ->route('prevention.patient', $patient)
            ->with('success', 'Acte de planification familiale enregistré.');
    }

    // ═══════════════════════════════════════════════════════════
    // Vaccination
    // ═══════════════════════════════════════════════════════════

    public function pev(): View
    {
        $this->autoriser();

        $depuis = now()->startOfMonth();

        return view('prevention.pev', [
            'doses' => Vaccination::query()
                ->with(['patient', 'auteur'])
                ->where('establishment_id', $this->etablissementId())
                ->where('date_vaccination', '>=', $depuis->toDateString())
                ->orderByDesc('date_vaccination')
                ->orderByDesc('created_at')
                ->limit(100)
                ->get(),
            'depuis' => $depuis,
            'systeme' => $this->systemes->definition(),
            'produite' => $this->systemes->produit('vaccination'),
        ]);
    }

    public function storeVaccination(Request $request, Patient $patient): RedirectResponse
    {
        $this->autoriser();

        $donnees = $request->validate([
            'antigene' => 'required|in:'.implode(',', array_keys(Vaccination::ANTIGENES)),
            'strategie' => 'required|in:'.implode(',', array_keys(Vaccination::STRATEGIES)),
            'date_vaccination' => 'nullable|date|before_or_equal:today',
            'lot' => 'nullable|string|max:60',
            'observation' => 'nullable|string|max:1000',
        ]);

        try {
            $this->prevention->vacciner($patient, $donnees);
        } catch (\RuntimeException|\InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['antigene' => $e->getMessage()]);
        }

        return redirect()
            ->route('prevention.patient', $patient)
            ->with('success', Vaccination::ANTIGENES[$donnees['antigene']]['libelle'].' enregistré.');
    }

    // ═══════════════════════════════════════════════════════════
    // Le dossier préventif d'un patient
    // ═══════════════════════════════════════════════════════════

    /** Le carnet de vaccination et l'historique PF, au même endroit. */
    public function patient(Patient $patient): View
    {
        $this->autoriser();

        return view('prevention.patient', [
            'patient' => $patient,
            'carnet' => $this->prevention->carnet($patient),
            'actesPf' => ActePlanificationFamiliale::where('patient_id', $patient->id)
                ->with('auteur')
                ->orderByDesc('date_acte')
                ->limit(30)
                ->get(),
            'suitLaPf' => $this->systemes->produit('planification_familiale'),
            'suitLePev' => $this->systemes->produit('vaccination'),
        ]);
    }
}
