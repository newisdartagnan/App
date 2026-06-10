<?php
namespace App\Livewire\Pharmacie;
use App\Models\Medicament;
use App\Models\StockMedicament;
use Livewire\Component;
use Livewire\WithPagination;
class StockDashboard extends Component
{
    use WithPagination;
    public string $search = '';
    public string $filtre = 'tous';
    public function updatingSearch(): void { $this->resetPage(); }
    public function render()
    {
        $query = Medicament::with('stock')
            ->where('est_actif', true)
            ->when($this->search, fn($q) =>
                $q->whereRaw('LOWER(denomination_commune) LIKE ?', ['%' . strtolower($this->search) . '%'])
                  ->orWhereRaw('LOWER(nom_commercial) LIKE ?', ['%' . strtolower($this->search) . '%'])
            );
        if ($this->filtre === 'alerte') {
            $query->whereHas('stock', fn($q) =>
                $q->whereColumn('quantite_disponible', '<=', 'quantite_alerte')
            );
        } elseif ($this->filtre === 'peremption') {
            $query->whereHas('stock', fn($q) =>
                $q->whereNotNull('date_peremption')
                  ->where('date_peremption', '<=', now()->addDays(30))
                  ->where('date_peremption', '>=', now())
            );
        } elseif ($this->filtre === 'rupture') {
            $query->whereHas('stock', fn($q) =>
                $q->where('quantite_disponible', '<=', 0)
            );
        }
        $medicaments = $query->orderBy('denomination_commune')->paginate(25);
        $stats = [
            'total' => Medicament::where('est_actif', true)->count(),
            'alerte' => StockMedicament::whereColumn('quantite_disponible', '<=', 'quantite_alerte')->count(),
            'rupture' => StockMedicament::where('quantite_disponible', '<=', 0)->count(),
            'peremption' => StockMedicament::whereNotNull('date_peremption')
                ->where('date_peremption', '<=', now()->addDays(30))
                ->where('date_peremption', '>=', now())
                ->count(),
        ];
        return view('livewire.pharmacie.stock-dashboard', compact('medicaments', 'stats'));
    }
}