<?php



namespace App\Livewire\Patients;



use App\Models\Establishment;

use App\Models\Patient;

use App\Services\DossierNumberService;

use App\Services\PatientDeduplicationService;

use Illuminate\Support\Facades\Log;

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



    protected function messages(): array

    {

        return [

            'nom.required' => 'Le nom est obligatoire.',

            'prenom.required' => 'Le prénom est obligatoire.',

            'date_naissance.before' => 'La date de naissance doit être antérieure à aujourd\'hui.',

        ];

    }



    public function updatedNom(): void

    {

        $this->confirmedNoDuplicate = false;

        $this->refreshDuplicateWarning();

    }



    public function updatedPrenom(): void

    {

        $this->confirmedNoDuplicate = false;

        $this->refreshDuplicateWarning();

    }



    public function updatedSexe(): void

    {

        $this->confirmedNoDuplicate = false;

        $this->refreshDuplicateWarning();

    }



    public function updatedDateNaissance(): void

    {

        $this->confirmedNoDuplicate = false;

        $this->refreshDuplicateWarning();

    }



    public function refreshDuplicateWarning(): void

    {

        if (strlen(trim($this->nom)) < 2 || strlen(trim($this->prenom)) < 2) {

            $this->duplicates = [];

            $this->showDuplicateWarning = false;



            return;

        }



        $service = app(PatientDeduplicationService::class);

        $found = $service->findDuplicates([

            'nom' => $this->nom,

            'prenom' => $this->prenom,

            'date_naissance' => $this->date_naissance ?: null,

            'sexe' => $this->sexe,

            'lieu_naissance' => $this->lieu_naissance ?: null,

            'nom_soundex' => $service->metaphone($this->nom),

            'prenom_soundex' => $service->metaphone($this->prenom),

        ]);



        $this->duplicates = $found->map(fn ($p) => [

            'id' => $p->id,

            'nom_complet' => $p->nom.' '.$p->prenom,

            'dossier_number' => $p->dossier_number,

            'date_naissance' => $p->date_naissance?->format('d/m/Y'),

            'score' => (int) round($p->duplicate_score * 100),

        ])->toArray();



        $this->showDuplicateWarning = count($this->duplicates) > 0;

        if (! $this->showDuplicateWarning) {

            $this->confirmedNoDuplicate = false;

        }

    }



    public function confirmNoDuplicate(): void

    {

        $this->confirmedNoDuplicate = true;

        $this->showDuplicateWarning = false;

    }



    public function save(): void

    {

        $this->nom = trim($this->nom);

        $this->prenom = trim($this->prenom);



        $this->validate();

        $this->refreshDuplicateWarning();



        if ($this->showDuplicateWarning && ! $this->confirmedNoDuplicate) {

            $this->addError('nom', 'Des patients similaires existent — confirmez qu\'il ne s\'agit pas d\'un doublon.');



            return;

        }



        $establishment = $this->resolveEstablishment();

        if (! $establishment) {

            $this->addError('nom', 'Aucun établissement configuré pour votre compte.');



            return;

        }



        try {

            $dedup = app(PatientDeduplicationService::class);



            $patient = Patient::create([

                'establishment_id' => $establishment->id,

                'dossier_number' => app(DossierNumberService::class)->generate($establishment->code),

                'nom' => strtoupper($this->nom),

                'prenom' => ucwords(strtolower($this->prenom)),

                'nom_soundex' => $dedup->metaphone($this->nom),

                'prenom_soundex' => $dedup->metaphone($this->prenom),

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

                'merge_status' => 'original',

            ]);



            Log::info('Patient créé', ['id' => $patient->id, 'dossier' => $patient->dossier_number]);



            session()->flash('success', 'Patient '.$patient->nom_complet.' créé — Dossier n° '.$patient->dossier_number);



            $this->redirectRoute('patients.show', $patient, navigate: false);

        } catch (\Throwable $e) {

            Log::error('Échec création patient', [

                'error' => $e->getMessage(),

                'nom' => $this->nom,

                'prenom' => $this->prenom,

            ]);

            $this->addError('nom', 'Enregistrement impossible : '.$e->getMessage());

        }

    }



    protected function resolveEstablishment(): ?Establishment

    {

        $user = auth()->user();



        if ($user?->establishment) {

            return $user->establishment;

        }



        return Establishment::where('is_active', true)->first();

    }



    public function render()

    {

        return view('livewire.patients.patient-create');

    }

}


