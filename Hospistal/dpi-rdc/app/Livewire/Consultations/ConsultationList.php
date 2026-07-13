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
        $base = fn () => Visit::with(['patient', 'user', 'consultations'])
            ->when($this->search, function ($q) {
                $q->whereHas('patient', function ($q) {
                    $q->whereRaw('LOWER(nom) LIKE ?', ['%' . strtolower($this->search) . '%'])
                      ->orWhereRaw('LOWER(prenom) LIKE ?', ['%' . strtolower($this->search) . '%'])
                      ->orWhere('dossier_number', 'like', '%' . $this->search . '%');
                });
            })
            ->whereIn('type', ['consultation_externe', 'urgence']);

        // File d'attente du médecin : visites payées, pas encore consultées
        $fileAttente = $base()
            ->where('statut', 'en_cours')
            ->whereDoesntHave('consultations')
            ->orderByRaw("CASE WHEN type = 'urgence' THEN 0 ELSE 1 END")
            ->orderBy('date_entree')
            ->get();

        // Envoyés à la caisse, paiement non encore validé
        $enAttentePaiement = $base()
            ->where('statut', 'en_attente')
            ->with('factures')
            ->orderBy('date_entree')
            ->get();

        // Historique des consultations réalisées
        $visits = $base()
            ->whereHas('consultations')
            ->when($this->statut, fn ($q) => $q->where('statut', $this->statut))
            ->when($this->date, fn ($q) => $q->whereDate('date_entree', $this->date))
            ->orderByDesc('date_entree')
            ->paginate(20);

        return view('livewire.consultations.consultation-list',
            compact('visits', 'fileAttente', 'enAttentePaiement'));
    }
}
