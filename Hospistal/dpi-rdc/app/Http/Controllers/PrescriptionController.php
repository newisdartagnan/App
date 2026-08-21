<?php

namespace App\Http\Controllers;

use App\Models\Consultation;
use App\Models\LignePrescription;
use App\Models\Medicament;
use App\Models\Officine;
use App\Models\Prescription;
use App\Services\DossierMedicalService;
use App\Services\NotificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class PrescriptionController extends Controller
{
    public function create(Consultation $consultation): View
    {
        $consultation->load('visit.patient');

        $officine = Officine::pourVisite($consultation->visit);

        // On montre au prescripteur le stock de l'officine qui servira son
        // ordonnance, et non celui du dépôt central : c'est là que le patient
        // ira. Un produit absent de cette officine se prescrit à l'extérieur.
        $medicaments = Medicament::with(['stock', 'stocks'])
            ->where('est_actif', true)
            ->orderBy('denomination_commune')
            ->get()
            ->map(function (Medicament $medicament) use ($officine) {
                $medicament->stock_officine = (float) $medicament->stocks
                    ->where('officine_id', $officine?->id)
                    ->sum('quantite_disponible');

                return $medicament;
            });

        return view('prescriptions.create', compact('consultation', 'medicaments', 'officine'));
    }

    /**
     * Ordonnance par formulaire classique — lignes vides ignorées.
     *
     * Le médecin pose sa posologie (dose par prise, prises par jour, durée) ;
     * la quantité en découle. Il n'a pas à la calculer, ni à convertir en
     * plaquettes : la pharmacie le fait au moment de servir.
     */
    public function store(Request $request, Consultation $consultation): RedirectResponse
    {
        $request->validate([
            'lignes' => 'required|array',
            'lignes.*.medicament_id' => 'nullable|uuid|exists:medicaments,id',
            'lignes.*.libelle_externe' => 'nullable|string|max:255',
            'lignes.*.dose' => 'nullable|numeric|min:0.25|max:100',
            'lignes.*.frequence' => 'nullable|integer|min:1|max:12',
            'lignes.*.duree_jours' => 'nullable|integer|min:1|max:365',
            'lignes.*.quantite_totale' => 'nullable|numeric|min:0.5',
            'lignes.*.instructions' => 'nullable|string|max:255',
        ], [
            'lignes.*.dose.numeric' => 'La dose est un nombre d\'unités par prise (1, 2, 0.5…).',
            'lignes.*.frequence.integer' => 'La fréquence est un nombre de prises par jour.',
        ]);

        if (! $consultation->visit->peutRecevoirServices()) {
            return back()->with('error', 'Séjour terminé — aucune nouvelle prescription possible.');
        }

        // Une ligne existe dès qu'elle désigne un produit du dépôt ou porte un
        // nom écrit à la main pour l'extérieur.
        $lignes = collect($request->input('lignes', []))
            ->filter(fn ($l) => ! blank($l['medicament_id'] ?? null) || ! blank($l['libelle_externe'] ?? null));

        if ($lignes->isEmpty()) {
            return back()->withErrors([
                'lignes' => 'Sélectionnez au moins un médicament, ou nommez un produit à acheter à l\'extérieur.',
            ])->withInput();
        }

        foreach ($lignes as $l) {
            if (blank($l['dose'] ?? null) || blank($l['frequence'] ?? null) || blank($l['duree_jours'] ?? null)) {
                return back()->withErrors([
                    'lignes' => 'Chaque ligne demande une dose par prise, un nombre de prises par jour et une durée.',
                ])->withInput();
            }
        }

        // Une allergie connue au produit doit être confirmée explicitement.
        $alertes = app(DossierMedicalService::class)->alertesAllergie(
            $consultation->visit->patient,
            $lignes->pluck('medicament_id')->filter()->all()
        );

        if ($alertes !== [] && ! $request->boolean('confirmer_allergie')) {
            $messages = array_map(
                fn ($a) => "{$a['medicament']} — allergie connue : {$a['allergie']}"
                    .($a['severite'] ? " ({$a['severite']})" : ''),
                $alertes
            );

            return back()
                ->withErrors(['allergie' => $messages])
                ->withInput()
                ->with('error', 'Allergie connue au produit prescrit — confirmez pour passer outre.');
        }

        $officine = Officine::pourVisite($consultation->visit);

        $prescription = DB::transaction(function () use ($consultation, $lignes, $request, $officine) {
            $prescription = Prescription::create([
                'consultation_id' => $consultation->id,
                'patient_id' => $consultation->visit->patient_id,
                'prescripteur_id' => auth()->id(),
                'officine_id' => $officine?->id,
                'date_prescription' => now(),
                'statut' => 'brouillon',
                'observations' => $request->input('observations') ?: null,
            ]);

            foreach ($lignes as $l) {
                $this->creerLigne($prescription, $l);
            }

            return $prescription;
        });

        app(NotificationService::class)->prescriptionPharmacie($prescription);

        $externes = $prescription->lignes()->where('est_externe', true)->count();

        return redirect()->route('consultations.show', $consultation)
            ->with('success', 'Ordonnance enregistrée — le patient règle à la caisse, puis retire à l\''
                .($officine?->nom ?? 'officine').'.'
                .($externes > 0
                    ? ' '.$externes.' produit(s) à acheter à l\'extérieur : l\'ordonnance externe s\'imprime sans prix.'
                    : ''));
    }

    /**
     * Une ligne d'ordonnance, avec sa quantité calculée.
     *
     * @param  array<string, mixed>  $donnees
     */
    private function creerLigne(Prescription $prescription, array $donnees): void
    {
        $dose = (float) $donnees['dose'];
        $frequence = (int) $donnees['frequence'];
        $duree = (int) $donnees['duree_jours'];

        // Le médecin peut corriger la quantité, sinon elle découle du schéma.
        $quantite = filled($donnees['quantite_totale'] ?? null)
            ? (float) $donnees['quantite_totale']
            : Medicament::quantiteTheorique($dose, $frequence, $duree);

        $externe = blank($donnees['medicament_id'] ?? null);
        $medicament = $externe ? null : Medicament::find($donnees['medicament_id']);

        // Ce qui sortira du tiroir : le conditionnement entier. Une ligne
        // externe n'est pas servie ici, donc rien à majorer ni à facturer.
        $delivrance = $medicament
            ? $medicament->conditionnementPour($quantite)
            : ['unites' => 0.0, 'conditionnements' => 0];

        LignePrescription::create([
            'prescription_id' => $prescription->id,
            'medicament_id' => $medicament?->id,
            'est_externe' => $externe,
            'libelle_externe' => $externe ? trim($donnees['libelle_externe']) : null,
            'dose' => $dose + 0 .' '.($medicament?->unite($dose) ?? 'unité'.($dose > 1 ? 's' : '')),
            'frequence' => $frequence.'×/jour',
            'duree_jours' => $duree,
            'voie_administration' => $medicament?->voie_administration ?? 'orale',
            'instructions' => $donnees['instructions'] ?? null,
            'quantite_totale' => $quantite,
            'quantite_facturee' => $delivrance['unites'],
            'conditionnements' => $delivrance['conditionnements'],
            'quantite_dispensee' => 0,
            'est_substituable' => false,
        ]);
    }
}
