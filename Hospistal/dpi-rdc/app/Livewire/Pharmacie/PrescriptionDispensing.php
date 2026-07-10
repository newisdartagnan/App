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
        if (! in_array($this->prescription->statut, ['en_attente', 'partiellement_dispensee'], true)) {
            $this->erreurs['general'] = 'Ordonnance non disponible pour dispensation (paiement guichet requis).';
            return;
        }

        $bonValide = \App\Models\BonSortie::where('prescription_id', $this->prescription->id)
            ->where('statut', 'emis')
            ->where('expire_at', '>', now())
            ->exists();

        if (! $bonValide) {
            $this->erreurs['general'] = 'Bon pharmacie invalide ou expiré — vérifier le paiement au guichet.';
            return;
        }

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

            \App\Models\BonSortie::where('prescription_id', $this->prescription->id)
                ->where('statut', 'emis')
                ->update(['statut' => 'utilise', 'utilise_at' => now()]);
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