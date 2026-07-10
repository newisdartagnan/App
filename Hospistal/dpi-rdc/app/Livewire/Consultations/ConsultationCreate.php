<?php
namespace App\Livewire\Consultations;

use App\Models\Consultation;
use App\Models\Patient;
use App\Models\Visit;
use Livewire\Component;

class ConsultationCreate extends Component
{
    public Patient $patient;

    // Visite
    public string $type_visite = 'consultation_externe';
    public string $motif_consultation = '';
    public string $symptomes_principaux = '';
    public ?float $poids_kg = null;
    public ?float $taille_cm = null;
    public ?int $tension_systolique = null;
    public ?int $tension_diastolique = null;
    public ?float $temperature = null;
    public ?int $frequence_cardiaque = null;
    public ?int $frequence_respiratoire = null;
    public ?float $saturation_o2 = null;

    // Consultation
    public string $type_consultation = 'initiale';
    public string $histoire_maladie = '';
    public string $antecedents_personnels = '';
    public string $antecedents_familiaux = '';
    public string $allergies = '';
    public string $examen_general = '';
    public string $conclusion = '';
    public string $conduite_a_tenir = '';
    public array $diagnostics = [];
    public string $nouveau_diagnostic_code = '';
    public string $nouveau_diagnostic_libelle = '';
    public string $nouveau_diagnostic_type = 'principal';

    // Alertes vitales
    public array $alertes = [];

    public int $etape = 1;

    public function mount(Patient $patient): void
    {
        $this->patient = $patient;
    }

    protected function rules(): array
    {
        return [
            'motif_consultation' => 'required|string|min:3',
            'type_visite' => 'required|in:consultation_externe,urgence,hospitalisation',
            'type_consultation' => 'required|in:initiale,suivi,urgence',
            'poids_kg' => 'nullable|numeric|min:0.5|max:300',
            'taille_cm' => 'nullable|numeric|min:20|max:250',
            'tension_systolique' => 'nullable|integer|min:50|max:300',
            'tension_diastolique' => 'nullable|integer|min:30|max:200',
            'temperature' => 'nullable|numeric|min:30|max:45',
            'frequence_cardiaque' => 'nullable|integer|min:20|max:300',
            'saturation_o2' => 'nullable|numeric|min:50|max:100',
        ];
    }

    public function updatedTensionSystolique(): void { $this->verifierAlertes(); }
    public function updatedTemperature(): void { $this->verifierAlertes(); }
    public function updatedSaturationO2(): void { $this->verifierAlertes(); }
    public function updatedFrequenceCardiaque(): void { $this->verifierAlertes(); }

    public function verifierAlertes(): void
    {
        $this->alertes = [];
        if ($this->tension_systolique && $this->tension_systolique > 180)
            $this->alertes[] = "⚠️ Tension systolique critique : {$this->tension_systolique} mmHg";
        if ($this->temperature && $this->temperature > 40)
            $this->alertes[] = "⚠️ Fièvre critique : {$this->temperature}°C";
        if ($this->saturation_o2 && $this->saturation_o2 < 90)
            $this->alertes[] = "⚠️ Saturation O₂ critique : {$this->saturation_o2}%";
        if ($this->frequence_cardiaque && ($this->frequence_cardiaque > 150 || $this->frequence_cardiaque < 40))
            $this->alertes[] = "⚠️ Fréquence cardiaque anormale : {$this->frequence_cardiaque} bpm";
    }

    public function ajouterDiagnostic(): void
    {
        if (empty(trim($this->nouveau_diagnostic_libelle))) return;
        $this->diagnostics[] = [
            'code_cim10' => strtoupper(trim($this->nouveau_diagnostic_code)),
            'libelle' => trim($this->nouveau_diagnostic_libelle),
            'type' => $this->nouveau_diagnostic_type,
        ];
        $this->nouveau_diagnostic_code = '';
        $this->nouveau_diagnostic_libelle = '';
        $this->nouveau_diagnostic_type = 'principal';
    }

    public function retirerDiagnostic(int $index): void
    {
        array_splice($this->diagnostics, $index, 1);
        $this->diagnostics = array_values($this->diagnostics);
    }

    public function etapeSuivante(): void
    {
        if ($this->etape === 1) {
            $this->validateOnly('motif_consultation');
            $this->validateOnly('type_visite');
        }
        $this->etape++;
    }

    public function etapePrecedente(): void
    {
        $this->etape = max(1, $this->etape - 1);
    }

    public function save(): void
    {
        $this->validate();

        $imc = null;
        if ($this->poids_kg && $this->taille_cm) {
            $taille_m = $this->taille_cm / 100;
            $imc = round($this->poids_kg / ($taille_m * $taille_m), 1);
        }

        $visit = Visit::create([
            'patient_id' => $this->patient->id,
            'establishment_id' => auth()->user()->establishment_id,
            'user_id' => auth()->id(),
            'type' => $this->type_visite,
            'statut' => 'en_cours',
            'date_entree' => now(),
            'motif_consultation' => $this->motif_consultation,
            'symptomes_principaux' => $this->symptomes_principaux ?: null,
            'poids_kg' => $this->poids_kg,
            'taille_cm' => $this->taille_cm,
            'imc' => $imc,
            'tension_systolique' => $this->tension_systolique,
            'tension_diastolique' => $this->tension_diastolique,
            'temperature' => $this->temperature,
            'frequence_cardiaque' => $this->frequence_cardiaque,
            'frequence_respiratoire' => $this->frequence_respiratoire,
            'saturation_o2' => $this->saturation_o2,
        ]);

        $consultation = Consultation::create([
            'visit_id' => $visit->id,
            'user_id' => auth()->id(),
            'date_consultation' => now(),
            'type' => $this->type_consultation,
            'histoire_maladie' => $this->histoire_maladie ?: null,
            'antecedents_personnels' => $this->antecedents_personnels ?: null,
            'antecedents_familiaux' => $this->antecedents_familiaux ?: null,
            'allergies' => $this->allergies ?: null,
            'examen_general' => $this->examen_general ?: null,
            'diagnostics' => $this->diagnostics,
            'conclusion' => $this->conclusion ?: null,
            'conduite_a_tenir' => $this->conduite_a_tenir ?: null,
            'statut' => 'finalise',
        ]);

        session()->flash('success', 'Consultation enregistrée pour ' . $this->patient->nom_complet);
        $this->redirect(route('consultations.show', $consultation));
    }

    public function render()
    {
        return view('livewire.consultations.consultation-create');
    }
}