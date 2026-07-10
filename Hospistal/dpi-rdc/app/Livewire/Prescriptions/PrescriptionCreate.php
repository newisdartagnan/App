<?php
namespace App\Livewire\Prescriptions;

use App\Models\Consultation;
use App\Models\LignePrescription;
use App\Models\Medicament;
use App\Models\Prescription;
use Livewire\Component;

class PrescriptionCreate extends Component
{
    public Consultation $consultation;
    public array $lignes = [];
    public string $observations = '';
    public bool $saved = false;

    // Recherche médicament
    public string $searchMed = '';
    public array $resultsMed = [];
    public ?int $ligneActive = null;

    public function mount(Consultation $consultation): void
    {
        $this->consultation = $consultation->load(['visit.patient']);
        $this->lignes = [[
            'medicament_id' => '',
            'medicament_nom' => '',
            'dose' => '',
            'frequence' => '',
            'duree_jours' => '',
            'voie_administration' => 'orale',
            'instructions' => '',
            'quantite_totale' => '',
            'est_substituable' => false,
        ]];
    }

    public function searchMedicament(int $index): void
    {
        $this->ligneActive = $index;
        $search = $this->lignes[$index]['medicament_nom'] ?? '';
        if (strlen($search) < 2) {
            $this->resultsMed = [];
            return;
        }
        $this->resultsMed = Medicament::where('est_actif', true)
            ->where(function ($q) use ($search) {
                $q->whereRaw('LOWER(denomination_commune) LIKE ?', ['%' . strtolower($search) . '%'])
                  ->orWhereRaw('LOWER(nom_commercial) LIKE ?', ['%' . strtolower($search) . '%']);
            })
            ->with('stock')
            ->limit(8)
            ->get()
            ->map(fn($m) => [
                'id' => $m->id,
                'label' => $m->denomination_commune . ($m->nom_commercial ? " ({$m->nom_commercial})" : '') . " — {$m->dosage} {$m->forme}",
                'stock' => $m->stock?->quantite_disponible ?? 0,
                'unite' => $m->unite_dispensation,
            ])
            ->toArray();
    }

    public function selectMedicament(string $id, string $label, int $index): void
    {
        $this->lignes[$index]['medicament_id'] = $id;
        $this->lignes[$index]['medicament_nom'] = $label;
        $this->resultsMed = [];
        $this->ligneActive = null;
    }

    public function ajouterLigne(): void
    {
        $this->lignes[] = [
            'medicament_id' => '',
            'medicament_nom' => '',
            'dose' => '',
            'frequence' => '',
            'duree_jours' => '',
            'voie_administration' => 'orale',
            'instructions' => '',
            'quantite_totale' => '',
            'est_substituable' => false,
        ];
    }

    public function retirerLigne(int $index): void
    {
        if (count($this->lignes) <= 1) return;
        array_splice($this->lignes, $index, 1);
        $this->lignes = array_values($this->lignes);
    }

    protected function rules(): array
    {
        return [
            'lignes.*.medicament_id' => 'required|exists:medicaments,id',
            'lignes.*.dose' => 'required|string|max:100',
            'lignes.*.frequence' => 'required|string|max:100',
            'lignes.*.quantite_totale' => 'required|numeric|min:0.5',
            'lignes.*.voie_administration' => 'required',
        ];
    }

    protected function messages(): array
    {
        return [
            'lignes.*.medicament_id.required' => 'Sélectionnez un médicament.',
            'lignes.*.dose.required' => 'La dose est obligatoire.',
            'lignes.*.frequence.required' => 'La fréquence est obligatoire.',
            'lignes.*.quantite_totale.required' => 'La quantité totale est obligatoire.',
        ];
    }

    public function save(): void
    {
        $this->validate();

        $prescription = Prescription::create([
            'consultation_id' => $this->consultation->id,
            'patient_id' => $this->consultation->visit->patient_id,
            'prescripteur_id' => auth()->id(),
            'date_prescription' => now(),
            'statut' => 'brouillon',
            'observations' => $this->observations ?: null,
        ]);

        foreach ($this->lignes as $ligne) {
            LignePrescription::create([
                'prescription_id' => $prescription->id,
                'medicament_id' => $ligne['medicament_id'],
                'dose' => $ligne['dose'],
                'frequence' => $ligne['frequence'],
                'duree_jours' => $ligne['duree_jours'] ?: null,
                'voie_administration' => $ligne['voie_administration'],
                'instructions' => $ligne['instructions'] ?: null,
                'quantite_totale' => $ligne['quantite_totale'],
                'quantite_dispensee' => 0,
                'est_substituable' => $ligne['est_substituable'] ?? false,
            ]);
        }

        $this->saved = true;
        session()->flash('prescription_ok', 'Ordonnance enregistrée — en attente de validation caisse.');
        $this->redirect(route('consultations.show', $this->consultation));
    }

    public function render()
    {
        return view('livewire.prescriptions.prescription-create');
    }
}