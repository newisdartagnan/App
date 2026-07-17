<?php
namespace App\Http\Controllers;

use App\Models\Consultation;
use App\Models\LignePrescription;
use App\Models\Medicament;
use App\Models\Prescription;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class PrescriptionController extends Controller
{
    public function create(Consultation $consultation): View
    {
        $consultation->load('visit.patient');
        $medicaments = Medicament::with('stock')
            ->where('est_actif', true)
            ->orderBy('denomination_commune')
            ->get();

        return view('prescriptions.create', compact('consultation', 'medicaments'));
    }

    /**
     * Ordonnance par formulaire classique — lignes vides ignorées.
     */
    public function store(Request $request, Consultation $consultation): RedirectResponse
    {
        $request->validate([
            'lignes' => 'required|array',
            'lignes.*.medicament_id' => 'nullable|uuid|exists:medicaments,id',
            'lignes.*.dose' => 'nullable|string|max:100',
            'lignes.*.frequence' => 'nullable|string|max:100',
            'lignes.*.duree_jours' => 'nullable|integer|min:1',
            'lignes.*.quantite_totale' => 'nullable|numeric|min:0.5',
        ]);

        $lignes = collect($request->input('lignes', []))
            ->filter(fn ($l) => ! blank($l['medicament_id'] ?? null));

        if ($lignes->isEmpty()) {
            return back()->withErrors(['lignes' => 'Sélectionnez au moins un médicament.'])->withInput();
        }

        foreach ($lignes as $l) {
            if (blank($l['dose'] ?? null) || blank($l['frequence'] ?? null) || blank($l['quantite_totale'] ?? null)) {
                return back()->withErrors(['lignes' => 'Chaque médicament sélectionné doit avoir une dose, une fréquence et une quantité totale.'])->withInput();
            }
        }

        $prescription = DB::transaction(function () use ($consultation, $lignes, $request) {
            $prescription = Prescription::create([
                'consultation_id' => $consultation->id,
                'patient_id' => $consultation->visit->patient_id,
                'prescripteur_id' => auth()->id(),
                'date_prescription' => now(),
                'statut' => 'brouillon',
                'observations' => $request->input('observations') ?: null,
            ]);

            foreach ($lignes as $l) {
                LignePrescription::create([
                    'prescription_id' => $prescription->id,
                    'medicament_id' => $l['medicament_id'],
                    'dose' => $l['dose'],
                    'frequence' => $l['frequence'],
                    'duree_jours' => $l['duree_jours'] ?? null,
                    'voie_administration' => 'orale',
                    'quantite_totale' => $l['quantite_totale'],
                    'quantite_dispensee' => 0,
                    'est_substituable' => false,
                ]);
            }

            return $prescription;
        });

        return redirect()->route('consultations.show', $consultation)
            ->with('success', 'Ordonnance enregistrée — le patient passe à la caisse pour la pharmacie.');
    }
}
