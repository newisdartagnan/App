<?php

namespace App\Http\Controllers;

use App\Models\AdministrationTraitement;
use App\Models\PlanAdministration;
use App\Models\Visit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Plan d'administration des médicaments (MAR) : une grille traitement × 24 h
 * où l'infirmier coche chaque prise réellement administrée.
 */
class PlanAdministrationController extends Controller
{
    public function index(Request $request, Visit $visit): View
    {
        $jour = $request->query('jour', now()->toDateString());

        $visit->load(['patient', 'service', 'lit', 'consultations.prescriptions.lignes.medicament']);

        $plans = PlanAdministration::with(['administrations.soignant', 'lignePrescription.medicament'])
            ->where('visit_id', $visit->id)
            ->whereDate('jour', $jour)
            ->orderBy('created_at')
            ->get();

        // Traitements prescrits pouvant alimenter le plan du jour
        $lignesDisponibles = $visit->consultations
            ->flatMap->prescriptions
            ->flatMap->lignes
            ->reject(fn ($ligne) => $plans->contains('ligne_prescription_id', $ligne->id));

        return view('mar.index', compact('visit', 'jour', 'plans', 'lignesDisponibles'));
    }

    public function store(Request $request, Visit $visit): RedirectResponse
    {
        $request->validate([
            'jour' => 'required|date',
            'libelle' => 'required_without:ligne_prescription_id|nullable|string|max:250',
            'ligne_prescription_id' => 'nullable|uuid|exists:lignes_prescription,id',
            'heures' => 'required|array|min:1',
            'heures.*' => 'integer|between:0,23',
        ], [
            'heures.required' => 'Choisissez au moins une heure d\'administration.',
        ]);

        if (! $visit->peutRecevoirServices()) {
            return back()->with('error', 'Séjour terminé — le plan n\'est plus modifiable.');
        }

        $libelle = $request->input('libelle');

        if ($request->filled('ligne_prescription_id') && blank($libelle)) {
            $ligne = \App\Models\LignePrescription::with('medicament')->find($request->ligne_prescription_id);
            $libelle = trim(sprintf(
                '%s %s — %s %s',
                $ligne->medicament->denomination_commune,
                $ligne->medicament->dosage,
                $ligne->dose,
                $ligne->frequence
            ));
        }

        PlanAdministration::create([
            'visit_id' => $visit->id,
            'ligne_prescription_id' => $request->ligne_prescription_id,
            'libelle' => $libelle,
            'jour' => $request->jour,
            'heures' => array_values(array_unique(array_map('intval', $request->input('heures')))),
            'cree_par' => auth()->id(),
        ]);

        return back()->with('success', 'Traitement ajouté au plan du jour.');
    }

    /**
     * Coche (ou décoche) une prise sur la grille.
     */
    public function basculer(Request $request, PlanAdministration $plan): RedirectResponse
    {
        $request->validate([
            'heure' => 'required|integer|between:0,23',
            'observation' => 'nullable|string|max:250',
        ]);

        $heure = (int) $request->heure;
        $existante = AdministrationTraitement::where('plan_id', $plan->id)
            ->where('heure', $heure)->first();

        if ($existante) {
            $existante->delete();

            return back()->with('success', "Prise de {$heure} h annulée.");
        }

        AdministrationTraitement::create([
            'plan_id' => $plan->id,
            'heure' => $heure,
            'user_id' => auth()->id(),
            'administre_at' => now(),
            'observation' => $request->observation,
        ]);

        return back()->with('success', "Prise de {$heure} h enregistrée.");
    }

    public function destroy(PlanAdministration $plan): RedirectResponse
    {
        $plan->delete();

        return back()->with('success', 'Traitement retiré du plan.');
    }

    /**
     * Reconduit le plan d'un jour sur le lendemain — les traitements au long
     * cours n'ont pas à être ressaisis chaque matin.
     */
    public function copierJourSuivant(Request $request, Visit $visit): RedirectResponse
    {
        $request->validate(['jour' => 'required|date']);

        $source = \Carbon\Carbon::parse($request->jour);
        $cible = $source->copy()->addDay();

        if (! $visit->peutRecevoirServices()) {
            return back()->with('error', 'Séjour terminé — le plan n\'est plus modifiable.');
        }

        $plans = PlanAdministration::where('visit_id', $visit->id)
            ->whereDate('jour', $source->toDateString())->get();

        if ($plans->isEmpty()) {
            return back()->with('error', 'Aucun traitement à reconduire pour ce jour.');
        }

        $copies = 0;
        DB::transaction(function () use ($plans, $visit, $cible, &$copies) {
            foreach ($plans as $plan) {
                $existe = PlanAdministration::where('visit_id', $visit->id)
                    ->whereDate('jour', $cible->toDateString())
                    ->where('libelle', $plan->libelle)
                    ->exists();

                if ($existe) {
                    continue;
                }

                PlanAdministration::create([
                    'visit_id' => $visit->id,
                    'ligne_prescription_id' => $plan->ligne_prescription_id,
                    'libelle' => $plan->libelle,
                    'jour' => $cible->toDateString(),
                    'heures' => $plan->heures,
                    'cree_par' => auth()->id(),
                ]);
                $copies++;
            }
        });

        return redirect()->route('mar.index', ['visit' => $visit->id, 'jour' => $cible->toDateString()])
            ->with('success', $copies > 0
                ? "{$copies} traitement(s) reconduit(s) au " . $cible->format('d/m/Y') . '.'
                : 'Le plan du lendemain était déjà en place.');
    }
}
