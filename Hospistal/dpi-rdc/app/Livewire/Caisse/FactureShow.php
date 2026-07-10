<?php
namespace App\Livewire\Caisse;

use App\Models\BonSortie;
use App\Models\ExamenLaboratoire;
use App\Models\Facture;
use App\Models\Prescription;
use App\Services\FacturationService;
use Livewire\Component;

class FactureShow extends Component
{
    public Facture $facture;
    public float $montantRecu = 0;
    public string $devise = 'CDF';
    public string $modePaiement = 'especes';
    public string $reference = '';
    public bool $paiementEffectue = false;
    public ?BonSortie $bonSortie = null;
    public bool $showBonSortie = false;

    public function mount(Facture $facture): void
    {
        $this->facture = $facture->load([
            'patient', 'lignes', 'paiements',
            'lignesTiersPayant.assurance',
            'bonsSortie.prescription',
        ]);
        $this->montantRecu = (float) $this->facture->patient_part;
        $this->devise = 'CDF';
    }

    protected function rules(): array
    {
        return [
            'montantRecu' => 'required|numeric|min:0.01',
            'devise' => 'required|in:CDF,USD',
            'modePaiement' => 'required|in:especes,mobile_money,virement,cheque',
        ];
    }

    public function validerPaiement(): void
    {
        $this->validate();

        if ($this->facture->statut === 'payee') {
            $this->addError('montantRecu', 'Cette facture est déjà payée.');
            return;
        }

        $service = app(FacturationService::class);

        $prescription = null;
        $examen = null;
        if ($this->facture->prescription_id) {
            $prescription = Prescription::find($this->facture->prescription_id);
        } else {
            $examen = ExamenLaboratoire::where('facture_id', $this->facture->id)->first();
        }

        $result = $service->validerPaiement(
            $this->facture,
            $this->montantRecu,
            $this->devise,
            $this->modePaiement,
            $this->reference ?: null,
            $prescription,
            $examen
        );

        $this->facture = $result['facture'];
        $this->bonSortie = $result['bon_sortie'];
        $this->paiementEffectue = true;

        if ($this->bonSortie) {
            $this->showBonSortie = true;
        }
    }

    public function marquerBonImprime(): void
    {
        if ($this->bonSortie) {
            $this->bonSortie->update(['imprime' => true]);
        }
    }

    public function render()
    {
        return view('livewire.caisse.facture-show');
    }
}