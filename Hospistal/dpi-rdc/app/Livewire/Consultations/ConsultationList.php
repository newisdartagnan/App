<?php
namespace App\Livewire\Consultations;

use App\Models\Visit;
use Livewire\Component;
use Livewire\WithPagination;

class ConsultationList extends Component
{
    use WithPagination;

    public string $search = '';
    public string $statut = '';
    public string $date = '';

    public function updatingSearch(): void { $this->resetPage(); }

    public function render()
    {
        $visits = Visit::with(['patient', 'user'])
            ->when($this->search, function ($q) {
                $q->whereHas('patient', function ($q) {
                    $q->whereRaw('LOWER(nom) LIKE ?', ['%' . strtolower($this->search) . '%'])
                      ->orWhereRaw('LOWER(prenom) LIKE ?', ['%' . strtolower($this->search) . '%'])
                      ->orWhere('dossier_number', 'like', '%' . $this->search . '%');
                });
            })
            ->when($this->statut, fn($q) => $q->where('statut', $this->statut))
            ->when($this->date, fn($q) => $q->whereDate('date_entree', $this->date))
            ->whereIn('type', ['consultation_externe', 'urgence'])
            ->orderByDesc('date_entree')
            ->paginate(20);

        return view('livewire.consultations.consultation-list', compact('visits'));
    }
}