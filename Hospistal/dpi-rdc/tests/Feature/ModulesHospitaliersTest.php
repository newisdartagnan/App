<?php

namespace Tests\Feature;

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

        // Paiement au guichet (formulaire classique) puis compte-rendu
        $this->post(route('caisse.encaisser', $acte->facture), [
            'montant' => 200000,
            'devise' => 'CDF',
            'mode_paiement' => 'especes',
        ])->assertSessionHas('success');
        $this->assertSame('payee', $acte->facture->fresh()->statut);

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
        // Formulaire classique (POST) — indépendant de Livewire/JavaScript
        $this->post(route('pharmacie.medicaments.store'), [
            'denomination_commune' => 'Ibuprofène',
            'forme' => 'comprime',
            'dosage' => '400 mg',
            'unite_dispensation' => 'comprimé',
            'prix_unitaire_vente' => 700,
            'quantite_alerte' => 10,
            'quantite_initiale' => 300,
            'lot' => 'LOT-TEST-01',
        ])->assertSessionHas('success');

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

        // Entrée de stock par formulaire classique (POST)
        $this->post(route('pharmacie.stock.mouvement', $med), [
            'type' => 'entree',
            'quantite' => 500,
            'lot' => 'LOT-2026-02',
        ])->assertSessionHas('success');

        $this->assertSame($avant + 500, (float) $med->stock->fresh()->quantite_disponible);
        $this->assertDatabaseHas('mouvements_stock', [
            'medicament_id' => $med->id,
            'type' => 'entree',
            'quantite_avant' => $avant,
            'quantite_apres' => $avant + 500,
        ]);
    }

    public function test_tiers_payant_applique_automatiquement_pour_patient_assure(): void
    {
        // Patient enregistré avec type assurance + nom d'assurance (comme au
        // formulaire d'accueil) mais sans lien patient_assurances préexistant
        $this->patient->update([
            'type_prise_en_charge' => 'assurance',
            'assurance_nom' => 'SONAS',
            'assurance_numero' => 'POL-12345',
        ]);

        $facture = app(\App\Services\FacturationService::class)
            ->creerFactureConsultation($this->visit->fresh());

        // L'assureur est créé, le lien établi, et 80 % pris en charge
        $this->assertDatabaseHas('assurances', ['nom' => 'SONAS']);
        $this->assertDatabaseHas('patient_assurances', [
            'patient_id' => $this->patient->id,
            'numero_police' => 'POL-12345',
            'est_actif' => true,
        ]);

        $facture->refresh();
        $this->assertSame(1, $facture->lignesTiersPayant()->count());
        $tp = $facture->lignesTiersPayant()->first();
        $this->assertSame(80.0, (float) $tp->taux_applique);
        $this->assertSame(12000.0, (float) $tp->part_assurance);
        $this->assertSame(3000.0, (float) $tp->part_patient);

        // Les totaux de la facture reflètent la prise en charge :
        // le patient assuré ne paie que sa part au guichet
        $this->assertSame(12000.0, (float) $facture->assurance_part);
        $this->assertSame(3000.0, (float) $facture->patient_part);
        $this->assertSame(3000.0, $facture->soldeRestant());
    }

    public function test_recherche_patient_json(): void
    {
        $this->getJson(route('patients.recherche', ['q' => 'ilu']))
            ->assertOk()
            ->assertJsonPath('patients.0.dossier', 'TST-2026-000800')
            ->assertJsonPath('patients.0.nom_complet', 'ILUNGA Sarah');

        // Moins de 2 caractères : pas de résultats
        $this->getJson(route('patients.recherche', ['q' => 'i']))
            ->assertOk()
            ->assertJsonCount(0, 'patients');
    }

    public function test_maj_assurance_depuis_fiche_patient(): void
    {
        // L'agent renseigne l'assurance sur la fiche patient (formulaire POST)
        $this->post(route('patients.assurance', $this->patient), [
            'assurance_nom' => 'Rawsur',
            'assurance_numero' => 'RW-889',
        ])->assertSessionHas('success');

        $this->patient->refresh();
        $this->assertSame('assurance', $this->patient->type_prise_en_charge);
        $this->assertSame('Rawsur', $this->patient->assurance_nom);
        $this->assertDatabaseHas('patient_assurances', [
            'patient_id' => $this->patient->id,
            'est_actif' => true,
        ]);

        // La facture suivante applique bien la prise en charge
        $facture = app(\App\Services\FacturationService::class)
            ->creerFactureConsultation($this->visit->fresh());
        $this->assertSame(12000.0, (float) $facture->assurance_part);
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
