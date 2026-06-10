<?php
namespace App\Livewire\Patients;

use App\Models\Patient;
use Livewire\Component;
use Livewire\WithPagination;

class PatientList extends Component
{
    use WithPagination;

    public string $search = '';
    public string $sexe = '';
    public int $perPage = 20;

    protected $queryString = ['search', 'sexe'];

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        $patients = Patient::query()
            ->when($this->search, function ($q) {
                $q->where(function ($q) {
                    $q->whereRaw('LOWER(nom) LIKE ?', ['%' . strtolower($this->search) . '%'])
                      ->orWhereRaw('LOWER(prenom) LIKE ?', ['%' . strtolower($this->search) . '%'])
                      ->orWhere('dossier_number', 'LIKE', '%' . $this->search . '%');
                });
            })
            ->when($this->sexe, fn($q) => $q->where('sexe', $this->sexe))
            ->where('merge_status', '!=', 'merged')
            ->orderBy('nom')
            ->paginate($this->perPage);

        return view('livewire.patients.patient-list', compact('patients'));
    }
}