<?php
namespace App\Livewire\Patients;

use App\Models\Establishment;
use App\Models\Patient;
use App\Services\DossierNumberService;
use App\Services\PatientDeduplicationService;
use Livewire\Component;

class PatientCreate extends Component
{
    public string $nom = '';
    public string $prenom = '';
    public string $date_naissance = '';
    public string $lieu_naissance = '';
    public string $sexe = 'Inconnu';
    public string $nationalite = 'Congolaise';
    public string $telephone = '';
    public string $adresse = '';
    public string $province = '';
    public string $territoire = '';
    public string $profession = '';
    public string $situation_matrimoniale = 'inconnu';
    public string $niveau_instruction = 'inconnu';
    public string $contact_urgence_nom = '';
    public string $contact_urgence_telephone = '';
    public string $contact_urgence_lien = '';
    public string $type_prise_en_charge = 'prive';
    public string $assurance_nom = '';
    public string $assurance_numero = '';

    public array $duplicates = [];
    public bool $showDuplicateWarning = false;
    public bool $confirmedNoDuplicate = false;

    protected function rules(): array
    {
        return [
            'nom' => 'required|string|max:100',
            'prenom' => 'required|string|max:100',
            'sexe' => 'required|in:M,F,Inconnu',
            'date_naissance' => 'nullable|date|before:today',
            'lieu_naissance' => 'nullable|string|max:150',
            'telephone' => 'nullable|string|max:50',
            'province' => 'nullable|string|max:100',
            'type_prise_en_charge' => 'required|in:prive,assurance,indigent,fonctionnaire,autre',
        ];
    }

    public function updated($field): void
    {
        if (in_array($field, ['nom', 'prenom', 'date_naissance', 'sexe'])) {
            $this->checkDuplicates();
        }
    }

    public function checkDuplicates(): void
    {
        if (strlen($this->nom) < 2 || strlen($this->prenom) < 2) return;

        $service = app(PatientDeduplicationService::class);
        $found = $service->findDuplicates([
            'nom' => $this->nom,
            'prenom' => $this->prenom,
            'date_naissance' => $this->date_naissance ?: null,
            'sexe' => $this->sexe,
            'nom_soundex' => $service->metaphone($this->nom),
            'prenom_soundex' => $service->metaphone($this->prenom),
        ]);

        $this->duplicates = $found->map(fn($p) => [
            'id' => $p->id,
            'nom_complet' => $p->nom . ' ' . $p->prenom,
            'dossier_number' => $p->dossier_number,
            'date_naissance' => $p->date_naissance?->format('d/m/Y'),
            'score' => round($p->duplicate_score * 100),
        ])->toArray();

        $this->showDuplicateWarning = count($this->duplicates) > 0;
    }

    public function confirmNoDuplicate(): void
    {
        $this->confirmedNoDuplicate = true;
        $this->showDuplicateWarning = false;
    }

    public function save(): void
    {
        $this->validate();

        if ($this->showDuplicateWarning && !$this->confirmedNoDuplicate) {
            $this->addError('nom', 'Veuillez confirmer qu\'il ne s\'agit pas d\'un doublon.');
            return;
        }

        $establishment = auth()->user()->establishment;
        $dossierService = app(DossierNumberService::class);

        $patient = Patient::create([
            'establishment_id' => $establishment->id,
            'dossier_number' => $dossierService->generate($establishment->code),
            'nom' => strtoupper(trim($this->nom)),
            'prenom' => ucwords(strtolower(trim($this->prenom))),
            'nom_soundex' => app(PatientDeduplicationService::class)->metaphone($this->nom),
            'prenom_soundex' => app(PatientDeduplicationService::class)->metaphone($this->prenom),
            'date_naissance' => $this->date_naissance ?: null,
            'lieu_naissance' => $this->lieu_naissance ?: null,
            'sexe' => $this->sexe,
            'nationalite' => $this->nationalite,
            'telephone' => $this->telephone ?: null,
            'adresse' => $this->adresse ?: null,
            'province' => $this->province ?: null,
            'territoire' => $this->territoire ?: null,
            'profession' => $this->profession ?: null,
            'situation_matrimoniale' => $this->situation_matrimoniale,
            'niveau_instruction' => $this->niveau_instruction,
            'contact_urgence_nom' => $this->contact_urgence_nom ?: null,
            'contact_urgence_telephone' => $this->contact_urgence_telephone ?: null,
            'contact_urgence_lien' => $this->contact_urgence_lien ?: null,
            'type_prise_en_charge' => $this->type_prise_en_charge,
            'assurance_nom' => $this->assurance_nom ?: null,
            'assurance_numero' => $this->assurance_numero ?: null,
        ]);

        session()->flash('success', 'Patient ' . $patient->nom_complet . ' créé — Dossier n° ' . $patient->dossier_number);
        $this->redirect(route('patients.show', $patient), navigate: true);
    }

    public function render()
    {
        return view('livewire.patients.patient-create');
    }
}