<?php

namespace Tests\Feature;

use App\Models\CommandeFournisseur;
use App\Models\CompteTresorerie;
use App\Models\Depense;
use App\Models\Establishment;
use App\Models\Facture;
use App\Models\Fournisseur;
use App\Models\InventaireStock;
use App\Models\Medicament;
use App\Models\MouvementStock;
use App\Models\Officine;
use App\Models\Paiement;
use App\Models\Patient;
use App\Models\ReceptionStock;
use App\Models\RubriqueDepense;
use App\Models\StockMedicament;
use App\Models\User;
use App\Services\GrandDepotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Grand dépôt (commande → réception partielle → stock, inventaire,
 * destruction, fiche de stock, propositions) et gestion financière
 * (dépense saisie → approuvée → payée, paiement fournisseur, trésorerie,
 * transferts, budget, clôture).
 */
class GrandDepotEtFinancesTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Officine $depot;

    protected Medicament $produit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->admin = User::where('email', 'admin@dpi-rdc.local')->firstOrFail();
        $this->actingAs($this->admin);
        $this->depot = Officine::where('type', 'depot_central')->firstOrFail();
        $this->produit = Medicament::create([
            'establishment_id' => Establishment::value('id'), 'denomination_commune' => 'Amoxicilline TEST',
            'dosage' => '500mg', 'forme' => 'gelule', 'est_actif' => true,
        ]);
    }

    protected function pharmacien(): User
    {
        $u = User::factory()->create(['establishment_id' => Establishment::value('id')]);
        $u->assignRole('pharmacien');

        return $u;
    }

    public function test_commande_reception_partielle_puis_complete(): void
    {
        $this->actingAs($this->pharmacien());
        $this->post(route('grand-depot.fournisseurs.store'), ['nom' => 'Pharmakina', 'telephone' => '0810000000'])->assertSessionHas('success');
        $f = Fournisseur::where('nom', 'Pharmakina')->firstOrFail();

        $this->get(route('grand-depot.commandes.create'))->assertOk();
        $this->post(route('grand-depot.commandes.store'), [
            'fournisseur_id' => $f->id, 'devise' => 'CDF',
            'lignes' => [['medicament_id' => $this->produit->id, 'quantite' => 100, 'prix_unitaire' => 200], ['medicament_id' => '', 'quantite' => '']],
        ])->assertRedirect();
        $commande = CommandeFournisseur::firstOrFail();
        $this->assertSame('brouillon', $commande->statut);
        $this->assertEquals(20000, $commande->fresh('lignes')->montant());

        $this->post(route('grand-depot.commandes.envoyer', $commande))->assertSessionHas('success');
        $ligne = $commande->lignes()->first();

        $this->post(route('grand-depot.commandes.recevoir', $commande), [
            'date_reception' => today()->toDateString(), 'numero_bon_livraison' => 'BL-1',
            'lignes' => [['ligne_commande_id' => $ligne->id, 'medicament_id' => $this->produit->id, 'quantite' => 60, 'lot' => 'L1', 'date_peremption' => now()->addYear()->toDateString(), 'prix_unitaire_achat' => 200]],
        ])->assertRedirect();
        $this->assertSame('partiellement_recue', $commande->fresh()->statut);
        $this->assertEquals(60, StockMedicament::where('officine_id', $this->depot->id)->where('medicament_id', $this->produit->id)->sum('quantite_disponible'));
        $this->assertEquals(12000, (float) ReceptionStock::first()->montant_total);

        $this->post(route('grand-depot.commandes.recevoir', $commande), [
            'date_reception' => today()->toDateString(),
            'lignes' => [['ligne_commande_id' => $ligne->id, 'medicament_id' => $this->produit->id, 'quantite' => 40, 'lot' => 'L2']],
        ]);
        $this->assertSame('recue', $commande->fresh()->statut);
        $this->assertEquals(100, StockMedicament::where('officine_id', $this->depot->id)->where('medicament_id', $this->produit->id)->sum('quantite_disponible'));
        $this->assertSame(2, MouvementStock::where('medicament_id', $this->produit->id)->where('type', 'entree')->count());

        $this->get(route('grand-depot.tableau'))->assertOk()->assertSee('Amoxicilline TEST')->assertSee('Valeur du stock');
        $this->get(route('grand-depot.fiche', $this->produit))->assertOk()->assertSee('Entrée')->assertSee('L1');
        $this->post(route('grand-depot.commandes.annuler', $commande))->assertSessionHas('error');
    }

    public function test_inventaire_ecart_et_destruction_des_perimes(): void
    {
        $service = app(GrandDepotService::class);
        $service->entrerEnStock($this->depot, $this->produit->id, 50, 'LOT-A', now()->addMonths(6)->toDateString(), 100, null, 'Initial', 'Test');
        $service->entrerEnStock($this->depot, $this->produit->id, 10, 'LOT-P', now()->subDay()->toDateString(), 100, null, 'Initial', 'Test');

        $this->post(route('grand-depot.inventaires.demarrer'))->assertRedirect();
        $inv = InventaireStock::firstOrFail();
        $this->post(route('grand-depot.inventaires.demarrer'))->assertSessionHas('error');
        $ligneA = $inv->lignes()->where('lot', 'LOT-A')->first();

        $this->post(route('grand-depot.inventaires.saisir', $inv), ['comptes' => [$ligneA->id => 47], 'motifs' => [$ligneA->id => 'casse'], 'action' => 'valider'])
            ->assertSessionHas('success');
        $this->assertSame('valide', $inv->fresh()->statut);
        $this->assertEquals(47, (float) StockMedicament::where('lot', 'LOT-A')->value('quantite_disponible'));
        $this->assertTrue(MouvementStock::where('type', 'ajustement_inventaire')->where('reference', 'like', '%casse%')->exists());

        $this->get(route('grand-depot.destructions'))->assertOk()->assertSee('LOT-P')->assertSee('(périmé)');
        $stockP = StockMedicament::where('lot', 'LOT-P')->first();
        $this->post(route('grand-depot.destructions.store'), ['motif' => 'Produits périmés', 'quantites' => [$stockP->id => 10]])->assertRedirect();
        $this->assertEquals(0, (float) $stockP->fresh()->quantite_disponible);
        $this->get(route('grand-depot.destructions.show', \App\Models\DestructionStock::first()))->assertOk()->assertSee('Procès-verbal de destruction')->assertSee('1 000,00');
    }

    public function test_propositions_de_commande_selon_la_consommation(): void
    {
        $service = app(GrandDepotService::class);
        $service->entrerEnStock($this->depot, $this->produit->id, 30, 'L', null, 100, null, 'Init', 'Test');
        // 90 sorties en trois mois : CMM 30 → objectif 3 mois = 90, stock 30 → 60 à commander.
        MouvementStock::create(['medicament_id' => $this->produit->id, 'establishment_id' => Establishment::value('id'), 'officine_id' => $this->depot->id,
            'user_id' => $this->admin->id, 'type' => 'transfert_sortie', 'quantite' => 90, 'quantite_avant' => 120, 'quantite_apres' => 30, 'created_at' => now()->subMonth()]);

        $s = $service->situation($this->depot)->first(fn ($x) => $x->medicament->id === $this->produit->id);
        $this->assertEquals(30, $s->cmm);
        $this->assertEquals(1.0, $s->mois_stock);
        $this->assertSame(60, $s->a_commander);

        Fournisseur::create(['nom' => 'Fournisseur test', 'est_actif' => true]);
        $this->get(route('grand-depot.commandes.create', ['propositions' => 1]))->assertOk()->assertSee('value="60"', false);
    }

    public function test_droits_du_grand_depot(): void
    {
        $caissier = User::factory()->create(['establishment_id' => Establishment::value('id')]);
        $caissier->assignRole('caissier');
        $this->actingAs($caissier)->get(route('grand-depot.tableau'))->assertForbidden();
        $this->actingAs($this->pharmacien())->get(route('grand-depot.tableau'))->assertOk();
    }

    public function test_circuit_d_une_depense_et_tresorerie(): void
    {
        $caisse = CompteTresorerie::where('type', 'caisse')->firstOrFail();
        $banque = CompteTresorerie::where('type', 'banque')->firstOrFail();
        $patient = Patient::create(['establishment_id' => Establishment::value('id'), 'dossier_number' => 'PAT-FIN-1', 'nom' => 'X', 'prenom' => 'Y', 'sexe' => 'M', 'type_prise_en_charge' => 'prive']);
        $facture = Facture::create(['patient_id' => $patient->id, 'establishment_id' => Establishment::value('id'), 'numero_facture' => 'FAC-FIN-1',
            'date_facture' => now(), 'statut' => 'payee', 'devise' => 'CDF', 'total_ht' => 100000, 'total_ttc' => 100000, 'patient_part' => 100000]);
        Paiement::create(['facture_id' => $facture->id, 'caissier_id' => $this->admin->id, 'date_paiement' => now(), 'montant' => 100000,
            'devise' => 'CDF', 'taux_change' => 1, 'montant_cdf' => 100000, 'mode_paiement' => 'especes']);

        $this->get(route('finance.tableau'))->assertOk()->assertSee('100 000 FC')->assertSee('Caisse principale');

        // Saisie par un comptable (chef de facturation), approbation par la direction.
        $chef = User::factory()->create(['establishment_id' => Establishment::value('id')]);
        $chef->assignRole('chef_facturation');
        $rubrique = RubriqueDepense::where('code', 'ENE')->first();
        $this->actingAs($chef)->post(route('finance.depenses.store'), [
            'date_depense' => today()->toDateString(), 'rubrique_id' => $rubrique->id, 'libelle' => 'Carburant groupe', 'montant' => 30000, 'devise' => 'CDF',
        ])->assertRedirect(route('finance.depenses'));
        $depense = Depense::firstOrFail();
        $this->assertSame('en_attente', $depense->statut);
        $this->actingAs($chef)->post(route('finance.depenses.approuver', $depense))->assertForbidden();

        $directeur = User::factory()->create(['establishment_id' => Establishment::value('id')]);
        $directeur->assignRole('directeur');
        $this->actingAs($directeur)->post(route('finance.depenses.approuver', $depense))->assertSessionHas('success');
        $this->actingAs($chef)->post(route('finance.depenses.payer', $depense), ['compte_tresorerie_id' => $caisse->id])->assertSessionHas('success');
        $this->assertSame('payee', $depense->fresh()->statut);

        $this->actingAs($directeur);
        $this->assertSame(70000.0, app(\App\Services\FinanceService::class)->solde($caisse));

        // Versement à la banque, puis on ne peut pas sortir plus que le solde.
        $this->post(route('finance.transferts.store'), ['compte_source_id' => $caisse->id, 'compte_destination_id' => $banque->id, 'montant_cdf' => 50000, 'date_transfert' => today()->toDateString()])
            ->assertSessionHas('success');
        $this->post(route('finance.transferts.store'), ['compte_source_id' => $caisse->id, 'compte_destination_id' => $banque->id, 'montant_cdf' => 50000, 'date_transfert' => today()->toDateString()])
            ->assertSessionHas('error');
        $this->get(route('finance.journal', $caisse))->assertOk()->assertSee('Carburant groupe')->assertSee('Transfert vers Banque')->assertSee('20 000');

        $this->post(route('finance.budget.store'), ['annee' => now()->year, 'prevus' => [$rubrique->id => 60000]])->assertSessionHas('success');
        $this->get(route('finance.budget'))->assertOk()->assertSee('50 %');
        $this->get(route('finance.resultat'))->assertOk()->assertSee('70 000 FC');
        $this->get(route('finance.depenses', ['format' => 'csv']))->assertOk();
    }

    public function test_paiement_d_un_fournisseur_et_cloture_du_mois(): void
    {
        $reception = app(GrandDepotService::class)->recevoir($this->depot, ['date_reception' => today()->toDateString()],
            [['medicament_id' => $this->produit->id, 'quantite' => 10, 'prix_unitaire_achat' => 500]]);
        $this->get(route('grand-depot.receptions.show', $reception))->assertOk()->assertSee('Payer le fournisseur');
        $this->get(route('finance.depenses.create', ['reception' => $reception->id]))->assertOk()->assertSee($reception->numero);

        $banque = CompteTresorerie::where('type', 'banque')->first();
        $banque->update(['solde_initial' => 100000]);
        $this->post(route('finance.depenses.store'), [
            'date_depense' => today()->toDateString(), 'rubrique_id' => RubriqueDepense::where('code', 'MED')->value('id'),
            'libelle' => 'Règlement', 'montant' => 5000, 'devise' => 'CDF', 'reception_id' => $reception->id,
        ]);
        $depense = Depense::first();
        $this->post(route('finance.depenses.approuver', $depense))->assertSessionHas('success'); // super admin peut approuver sa saisie
        $this->post(route('finance.depenses.payer', $depense), ['compte_tresorerie_id' => $banque->id])->assertSessionHas('success');
        $this->assertSame(0.0, $reception->fresh()->resteAPayer());

        // Clôture du mois précédent : plus aucune dépense datée de ce mois.
        $mois = now()->subMonth()->format('Y-m');
        $this->post(route('finance.cloturer'), ['mois' => $mois])->assertSessionHas('success');
        $this->post(route('finance.cloturer'), ['mois' => now()->format('Y-m')])->assertSessionHas('error');
        $this->post(route('finance.depenses.store'), [
            'date_depense' => now()->subMonth()->startOfMonth()->toDateString(), 'rubrique_id' => RubriqueDepense::value('id'),
            'libelle' => 'Trop tard', 'montant' => 10, 'devise' => 'CDF',
        ])->assertSessionHas('error');
    }

    public function test_une_requisition_se_sert_sur_plusieurs_lots_premier_perime_premier_sorti(): void
    {
        $service = app(GrandDepotService::class);
        $service->entrerEnStock($this->depot, $this->produit->id, 30, 'TARD', now()->addYear()->toDateString(), 100, null, 'Init', 'Test');
        $service->entrerEnStock($this->depot, $this->produit->id, 20, 'TOT', now()->addMonth()->toDateString(), 100, null, 'Init', 'Test');
        $officine = Officine::create(['nom' => 'Officine test', 'type' => 'ambulatoire', 'est_actif' => true]);

        app(\App\Services\OfficineService::class)->deplacerStock($this->produit, $this->depot, $officine, 35, 'REQ-T');

        $this->assertEquals(0, (float) StockMedicament::where('officine_id', $this->depot->id)->where('lot', 'TOT')->value('quantite_disponible'));
        $this->assertEquals(15, (float) StockMedicament::where('officine_id', $this->depot->id)->where('lot', 'TARD')->value('quantite_disponible'));
        $this->assertEquals(35, (float) StockMedicament::where('officine_id', $officine->id)->sum('quantite_disponible'));

        $this->expectException(\RuntimeException::class);
        app(\App\Services\OfficineService::class)->deplacerStock($this->produit, $this->depot, $officine, 100, 'REQ-T2');
    }
}
