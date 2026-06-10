<?php
namespace App\Livewire\Pharmacie;
use App\Models\Medicament;
use App\Models\StockMedicament;
use Illuminate\Support\Facades\DB;
use Livewire\Component;
class MedicamentForm extends Component
{
    public bool $showForm = false;
    public string $denomination_commune = '';
    public string $nom_commercial = '';
    public string $forme = 'comprime';
    public string $dosage = '';
    public string $unite_dispensation = 'comprimé';
    public string $classe_therapeutique = '';
    public bool $necessite_ordonnance = true;
    public float $prix_unitaire_vente = 0;
    public float $prix_unitaire_achat = 0;
    public int $quantite_alerte = 10;
    public ?string $date_peremption = null;
    public string $lot = '';

    protected function rules(): array
    {
        return [
            'denomination_commune' => 'required|string|max:255',
            'forme' => 'required',
            'dosage' => 'required|string|max:100',
            'unite_dispensation' => 'required|string|max:50',
            'prix_unitaire_vente' => 'required|numeric|min:0',
            'quantite_alerte' => 'required|integer|min:0',
        ];
    }

    public function save(): void
    {
        $this->validate();
        DB::transaction(function () {
            $medicament = Medicament::create([
                'establishment_id' => auth()->user()->establishment_id,
                'denomination_commune' => $this->denomination_commune,
                'nom_commercial' => $this->nom_commercial ?: null,
                'forme' => $this->forme,
                'dosage' => $this->dosage,
                'unite_dispensation' => $this->unite_dispensation,
                'classe_therapeutique' => $this->classe_therapeutique ?: null,
                'necessite_ordonnance' => $this->necessite_ordonnance,
            ]);
            StockMedicament::create([
                'medicament_id' => $medicament->id,
                'establishment_id' => auth()->user()->establishment_id,
                'quantite_disponible' => 0,
                'quantite_alerte' => $this->quantite_alerte,
                'prix_unitaire_vente' => $this->prix_unitaire_vente,
                'prix_unitaire_achat' => $this->prix_unitaire_achat,
                'date_peremption' => $this->date_peremption ?: null,
                'lot' => $this->lot ?: null,
            ]);
        });
        $this->reset(['denomination_commune', 'nom_commercial', 'dosage',
                      'classe_therapeutique', 'prix_unitaire_vente', 'prix_unitaire_achat',
                      'date_peremption', 'lot']);
        $this->showForm = false;
        session()->flash('success', 'Médicament ajouté au stock.');
        $this->dispatch('medicament-ajout');
    }

    public function render()
    {
        return view('livewire.pharmacie.medicament-form');
    }
}