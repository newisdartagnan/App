<?php

namespace App\Livewire;

use App\Models\Patient;
use Livewire\Component;
use Livewire\WithPagination;

class PatientSearch extends Component
{
    use WithPagination;

    public string $query = '';

    protected $queryString = ['query'];

    public function updatedQuery(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        $patients = collect();

        if (strlen($this->query) >= 2) {
            $patients = Patient::query()
                ->where(function ($q) {
                    $term = '%'.$this->query.'%';
                    $q->where('nom', 'ilike', $term)
                        ->orWhere('prenom', 'ilike', $term)
                        ->orWhere('dossier_number', 'ilike', $term)
                        ->orWhere('telephone', 'ilike', $term);
                })
                ->orderBy('nom')
                ->paginate(20);
        }

        return view('livewire.patient-search', compact('patients'));
    }
}
