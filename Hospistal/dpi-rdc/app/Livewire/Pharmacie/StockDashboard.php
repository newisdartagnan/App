<?php
namespace App\Livewire\Pharmacie;
use App\Models\Medicament;
use App\Models\MouvementStock;
use App\Models\StockMedicament;
use Illuminate\Support\Facades\DB;
use Livewire\Component;
use Livewire\WithPagination;
class StockDashboard extends Component
{
    use WithPagination;
    public string $search = '';
    public string $filtre = 'tous';

    // Entrée / ajustement de stock
    public ?string $entreeMedicamentId = null;
    public string $entreeType = 'entree';
    public $entreeQuantite = '';
    public string $entreeLot = '';
    public string $entreeReference = '';

    public function updatingSearch(): void { $this->resetPage(); }

    public function ouvrirEntree(string $medicamentId): void
    {
        $this->entreeMedicamentId = $medicamentId;
        $this->entreeType = 'entree';
        $this->entreeQuantite = '';
        $this->entreeLot = '';
        $this->entreeReference = '';
        $this->resetErrorBag();
    }

    public function annulerEntree(): void
    {
        $this->entreeMedicamentId = null;
        $this->resetErrorBag();
    }

    public function enregistrerEntree(): void
    {
        $this->validate([
            'entreeQuantite' => 'required|numeric|not_in:0',
            'entreeType' => 'required|in:entree,ajustement_inventaire,sortie_peremption',
        ], [
            'entreeQuantite.required' => 'Indiquez une quantité.',
            'entreeQuantite.not_in' => 'La quantité ne peut pas être nulle.',
        ]);

        $medicament = Medicament::with('stock')->findOrFail($this->entreeMedicamentId);

        DB::transaction(function () use ($medicament) {
            $stock = $medicament->stock ?: StockMedicament::create([
                'medicament_id' => $medicament->id,
                'establishment_id' => auth()->user()->establishment_id,
                'quantite_disponible' => 0,
                'quantite_alerte' => 10,
            ]);

            $avant = (float) $stock->quantite_disponible;
            $delta = (float) $this->entreeQuantite;

            // Une sortie péremption retire toujours du stock ; une entrée en ajoute toujours
            if ($this->entreeType === 'sortie_peremption') {
                $delta = -abs($delta);
            } elseif ($this->entreeType === 'entree') {
                $delta = abs($delta);
            }

            $apres = $avant + $delta;
            if ($apres < 0) {
                $this->addError('entreeQuantite', "Stock insuffisant (disponible : {$avant}).");

                return;
            }

            $stock->update([
                'quantite_disponible' => $apres,
                'lot' => $this->entreeLot ?: $stock->lot,
            ]);

            MouvementStock::create([
                'medicament_id' => $medicament->id,
                'establishment_id' => auth()->user()->establishment_id,
                'user_id' => auth()->id(),
                'type' => $this->entreeType,
                'quantite' => abs($delta),
                'quantite_avant' => $avant,
                'quantite_apres' => $apres,
                'reference' => $this->entreeReference ?: ($this->entreeLot ? "Lot {$this->entreeLot}" : null),
                'created_at' => now(),
            ]);

            $this->entreeMedicamentId = null;
            session()->flash('success', "Stock de {$medicament->denomination_commune} mis à jour : {$avant} → {$apres}.");
        });
    }

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