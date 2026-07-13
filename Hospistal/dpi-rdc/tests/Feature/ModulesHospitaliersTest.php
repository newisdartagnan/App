<?php

namespace Tests\Feature;

use App\Livewire\Caisse\FactureShow;
use App\Livewire\PatientSearch;
use App\Livewire\Pharmacie\MedicamentForm;
use App\Livewire\Pharmacie\StockDashboard;
use App\Models\ActeClinique;
use App\Models\Establishment;
use App\Models\ExamenLaboratoire;
use App\Models\Facture;
use App\Models\Medicament;
use App\Models\Patient;
use App\Models\TypeExamen;
use App\Models\User;
use App\Models\Visit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ModulesHospitaliersTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Patient $patient;

    protected Visit $visit;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();

        $this->user = User::where('email', 'admin@dpi-rdc.local')->firstOrFail();
        $this->actingAs($this->user);

        $etab = Establishment::firstOrFail();
        $this->patient = Patient::create([
            'establishment_id' => $etab->id,
            'dossier_number' => 'TST-2026-000800',
            'nom' => 'ILUNGA',
            'prenom' => 'Sarah',
            'sexe' => 'F',
            'type_prise_en_charge' => 'prive',
        ]);
        $this->visit = Visit::create([
            'patient_id' => $this->patient->id,
            'establishment_id' => $etab->id,
            'user_id' => $this->user->id,
            'type' => 'consultation_externe',
            'statut' => 'en_cours',
            'date_entree' => now(),
            'motif_consultation' => 'Suivi',
        ]);
    }

    public function test_maternite_acte_facture_et_realisation(): void
    {
        // La page maternité s'affiche (régression : table actes_cliniques)
        $this->get(route('maternite.index'))->assertOk();

        $this->post(route('maternite.store'), [
            'visit_id' => $this->visit->id,
            'domaine' => 'maternite',
            'libelle' => 'Accouchement voie basse',
            'prix' => 200000,
            'facturer' => '1',
        ])->assertRedirect();

        $acte = ActeClinique::where('visit_id', $this->visit->id)->firstOrFail();
        $this->assertSame('maternite', $acte->domaine);
        $this->assertSame('facture', $acte->statut);
        $this->assertNotNull($acte->facture_id);

        // Paiement au guichet puis compte-rendu
        Livewire::test(FactureShow::class, ['facture' => $acte->facture])
            ->call('validerPaiement')
            ->assertHasNoErrors();

        $this->post(route('actes.realiser', $acte), [
            'compte_rendu' => 'Accouchement eutocique, nouveau-né bien portant.',
        ])->assertRedirect();

        $this->assertSame('realise', $acte->fresh()->statut);
    }

    public function test_bloc_operatoire_acte(): void
    {
        $this->get(route('bloc.index'))->assertOk();

        $this->post(route('bloc.store'), [
            'visit_id' => $this->visit->id,
            'domaine' => 'chirurgie',
            'libelle' => 'Petite chirurgie',
            'prix' => 150000,
        ])->assertRedirect();

        $acte = ActeClinique::where('domaine', 'chirurgie')->firstOrFail();
        $this->assertSame('planifie', $acte->statut);

        $this->post(route('actes.facturer', $acte))->assertRedirect();
        $this->assertNotNull($acte->fresh()->facture_id);
    }

    public function test_imagerie_prescription_et_facture(): void
    {
        $this->get(route('imagerie.index'))->assertOk();

        $types = TypeExamen::where('code', 'like', 'IMG-%')->limit(1)->pluck('id')->all();
        $this->assertNotEmpty($types);

        $this->post(route('labo.store'), [
            'visit_id' => $this->visit->id,
            'domaine' => 'imagerie',
            'types' => $types,
        ])->assertRedirect();

        $examen = ExamenLaboratoire::where('domaine', 'imagerie')->firstOrFail();
        $this->assertNotNull($examen->facture_id);
        $this->assertSame('imagerie', $examen->facture->lignes->first()->type);
    }

    public function test_ajout_medicament_avec_stock_initial(): void
    {
        Livewire::test(MedicamentForm::class)
            ->set('showForm', true)
            ->set('denomination_commune', 'Ibuprofène')
            ->set('dosage', '400 mg')
            ->set('unite_dispensation', 'comprimé')
            ->set('prix_unitaire_vente', 700)
            ->set('quantite_initiale', 300)
            ->set('lot', 'LOT-TEST-01')
            ->call('save')
            ->assertHasNoErrors();

        $med = Medicament::where('denomination_commune', 'Ibuprofène')->firstOrFail();
        $this->assertSame(300.0, (float) $med->stock->quantite_disponible);
        $this->assertDatabaseHas('mouvements_stock', [
            'medicament_id' => $med->id,
            'type' => 'entree',
        ]);
    }

    public function test_entree_stock_sur_medicament_existant(): void
    {
        $med = Medicament::where('denomination_commune', 'Paracétamol')->firstOrFail();
        $avant = (float) $med->stock->quantite_disponible;

        Livewire::test(StockDashboard::class)
            ->call('ouvrirEntree', $med->id)
            ->set('entreeQuantite', 500)
            ->set('entreeLot', 'LOT-2026-02')
            ->call('enregistrerEntree')
            ->assertHasNoErrors();

        $this->assertSame($avant + 500, (float) $med->stock->fresh()->quantite_disponible);
        $this->assertDatabaseHas('mouvements_stock', [
            'medicament_id' => $med->id,
            'type' => 'entree',
            'quantite_avant' => $avant,
            'quantite_apres' => $avant + 500,
        ]);
    }

    public function test_recherche_patient_livewire(): void
    {
        Livewire::test(PatientSearch::class)
            ->set('query', 'ILUNGA')
            ->assertSee('ILUNGA')
            ->assertSee('TST-2026-000800');
    }

    public function test_facturation_patient_type_autre(): void
    {
        $this->patient->update(['type_prise_en_charge' => 'autre']);

        $facture = app(\App\Services\FacturationService::class)
            ->creerFactureConsultation($this->visit->fresh());

        $this->assertInstanceOf(Facture::class, $facture);
        $this->assertSame('autre', $facture->type_prise_en_charge);
    }
}
