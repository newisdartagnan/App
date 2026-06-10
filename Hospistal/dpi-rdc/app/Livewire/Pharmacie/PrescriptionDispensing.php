<?php
namespace App\Livewire\Pharmacie;
use App\Models\Dispensation;
use App\Models\LignePrescription;
use App\Models\Prescription;
use App\Models\StockMedicament;
use Illuminate\Support\Facades\DB;
use Livewire\Component;
class PrescriptionDispensing extends Component
{
    public Prescription $prescription;
    public array $quantites = [];
    public array $erreurs = [];
    public bool $dispensationComplete = false;

    public function mount(Prescription $prescription): void
    {
        $this->prescription = $prescription->load(['lignes.medicament.stock', 'patient']);
        foreach ($this->prescription->lignes as $ligne) {
            $this->quantites[$ligne->id] = $ligne->quantiteRestante();
        }
    }

    public function dispenser(): void
    {
        $this->erreurs = [];
        foreach ($this->prescription->lignes as $ligne) {
            $qte = $this->quantites[$ligne->id] ?? 0;
            if ($qte <= 0) continue;
            $stock = $ligne->medicament->stock;
            if (!$stock || $stock->quantite_disponible < $qte) {
                $this->erreurs[$ligne->id] =
                    "Stock insuffisant pour {$ligne->medicament->denomination_commune} " .
                    "(disponible : " . ($stock?->quantite_disponible ?? 0) . " {$ligne->medicament->unite_dispensation})";
            }
        }
        if (!empty($this->erreurs)) return;

        DB::transaction(function () {
            foreach ($this->prescription->lignes as $ligne) {
                $qte = $this->quantites[$ligne->id] ?? 0;
                if ($qte <= 0) continue;
                $stock = $ligne->medicament->stock;
                Dispensation::create([
                    'ligne_prescription_id' => $ligne->id,
                    'pharmacien_id' => auth()->id(),
                    'date_dispensation' => now(),
                    'quantite_dispensee' => $qte,
                    'lot' => $stock->lot,
                    'prix_applique' => $stock->prix_unitaire_vente * $qte,
                ]);
                $stock->decrement('quantite_disponible', $qte);
                $ligne->increment('quantite_dispensee', $qte);
            }
            $this->prescription->update(['statut' => 'dispensee']);
        });

        $this->prescription->refresh()->load(['lignes.medicament.stock']);
        $this->dispensationComplete = true;
        session()->flash('success', 'Médicaments dispensés avec succès.');
    }

    public function render()
    {
        return view('livewire.pharmacie.prescription-dispensing');
    }
}