<?php

namespace Tests\Feature;

use App\Models\Consultation;
use App\Models\Establishment;
use App\Models\Medicament;
use App\Models\Officine;
use App\Models\Patient;
use App\Models\Prescription;
use App\Models\Service;
use App\Models\StockMedicament;
use App\Models\User;
use App\Models\Visit;
use App\Services\FacturationService;
use App\Services\OfficineService;
use App\Services\PharmacieService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Le circuit du médicament, du cabinet au comptoir.
 *
 * Le médecin pose une posologie ; le système en tire la quantité, puis le
 * conditionnement réellement délivré. La caisse facture ce qui sortira du
 * tiroir. L'officine du lieu de soins sert — jamais le dépôt central, qui
 * réapprovisionne les officines sur réquisition.
 */
class CircuitPharmacieTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Establishment $etab;

    protected Patient $patient;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->admin = User::where('email', 'admin@dpi-rdc.local')->firstOrFail();
        $this->etab = Establishment::firstOrFail();
        $this->actingAs($this->admin);

        $this->patient = Patient::create([
            'establishment_id' => $this->etab->id,
            'dossier_number' => 'PAT-2026-008800',
            'nom' => 'MASUDI', 'postnom' => 'Kalala', 'prenom' => 'Jean',
            'sexe' => 'M',
            'date_naissance' => now()->subYears(40)->toDateString(),
            'type_prise_en_charge' => 'prive',
        ]);
    }

    protected function consultation(string $type = 'consultation_externe', ?Service $service = null): Consultation
    {
        $visite = Visit::create([
            'patient_id' => $this->patient->id,
            'establishment_id' => $this->etab->id,
            'user_id' => $this->admin->id,
            'type' => $type,
            'statut' => 'en_cours',
            'date_entree' => now(),
            'service_id' => $service?->id,
            'motif_consultation' => 'Fièvre',
        ]);

        return Consultation::create([
            'visit_id' => $visite->id,
            'medecin_id' => $this->admin->id,
            'date_consultation' => now(),
            'motif' => 'Fièvre',
        ]);
    }

    protected function medicament(string $nom): Medicament
    {
        return Medicament::where('denomination_commune', $nom)->firstOrFail();
    }

    // ═══════════════════════════════════════════════════════════
    // Le produit tel qu'il se lit et se conditionne
    // ═══════════════════════════════════════════════════════════

    public function test_le_medicament_annonce_sa_voie_et_son_conditionnement(): void
    {
        $paracetamol = $this->medicament('Paracétamol');

        $this->assertSame('orale', $paracetamol->voie_administration);
        $this->assertSame('plaquette de 10 comprimés', $paracetamol->libelleConditionnement());
        $this->assertSame(
            'Paracétamol 500 mg (417) — voie orale / plaquette de 10 comprimés',
            $paracetamol->libelleComplet(417)
        );

        $ceftriaxone = $this->medicament('Ceftriaxone');
        $this->assertSame('injectable', $ceftriaxone->voie_administration);
        $this->assertSame('flacon', $ceftriaxone->libelleConditionnement());
    }

    public function test_le_prix_est_celui_de_lunite_et_non_de_la_boite(): void
    {
        // Une plaquette de dix comprimés à 500 CDF fait 50 CDF le comprimé.
        $this->assertSame(
            50.0,
            (float) $this->medicament('Paracétamol')->stock->prix_unitaire_vente
        );

        // Un flacon se vend à l'unité : son prix ne bouge pas.
        $this->assertSame(
            8000.0,
            (float) $this->medicament('Ceftriaxone')->stock->prix_unitaire_vente
        );
    }

    public function test_la_quantite_decoule_du_schema_posologique(): void
    {
        // Deux comprimés, trois fois par jour, cinq jours.
        $this->assertSame(30.0, Medicament::quantiteTheorique(2, 3, 5));
        $this->assertSame(7.5, Medicament::quantiteTheorique(0.5, 3, 5));
    }

    public function test_une_plaquette_ne_se_coupe_pas_en_deux(): void
    {
        $paracetamol = $this->medicament('Paracétamol');

        // Quinze comprimés se servent en deux plaquettes de dix.
        $this->assertSame(
            ['unites' => 20.0, 'conditionnements' => 2, 'majoration' => 5.0],
            $paracetamol->conditionnementPour(15)
        );

        // Vingt comprimés tombent juste : aucune majoration.
        $this->assertSame(
            ['unites' => 20.0, 'conditionnements' => 2, 'majoration' => 0.0],
            $paracetamol->conditionnementPour(20)
        );

        // Un flacon se sert à l'unité.
        $this->assertSame(
            ['unites' => 3.0, 'conditionnements' => 3, 'majoration' => 0.0],
            $this->medicament('Ceftriaxone')->conditionnementPour(3)
        );
    }

    // ═══════════════════════════════════════════════════════════
    // De l'ordonnance à la facture
    // ═══════════════════════════════════════════════════════════

    protected function prescrire(Consultation $consultation, array $lignes): Prescription
    {
        $this->post(route('prescriptions.store', $consultation), ['lignes' => $lignes])
            ->assertRedirect();

        // Plusieurs ordonnances peuvent naître dans la même seconde : on la
        // retrouve par sa consultation, jamais par sa date.
        return Prescription::where('consultation_id', $consultation->id)
            ->with('lignes.medicament', 'officine')
            ->latest('created_at')
            ->firstOrFail();
    }

    public function test_le_medecin_pose_la_posologie_le_systeme_compte(): void
    {
        $consultation = $this->consultation();

        $prescription = $this->prescrire($consultation, [
            ['medicament_id' => $this->medicament('Paracétamol')->id,
                'dose' => 1, 'frequence' => 3, 'duree_jours' => 5],
        ]);

        $ligne = $prescription->lignes->first();

        $this->assertSame(15.0, (float) $ligne->quantite_totale);
        $this->assertSame(20.0, (float) $ligne->quantite_facturee);
        $this->assertSame(2, $ligne->conditionnements);
        $this->assertSame('2 plaquettes de 10 comprimés', $ligne->libelleConditionnement());
        $this->assertTrue($ligne->estMajoree());
        $this->assertSame('1 comprimé, 3×/jour, 5 jours', $ligne->posologie());
    }

    public function test_la_caisse_facture_le_conditionnement_reellement_servi(): void
    {
        $consultation = $this->consultation();

        $prescription = $this->prescrire($consultation, [
            ['medicament_id' => $this->medicament('Paracétamol')->id,
                'dose' => 1, 'frequence' => 3, 'duree_jours' => 5],
            ['medicament_id' => $this->medicament('Ceftriaxone')->id,
                'dose' => 1, 'frequence' => 1, 'duree_jours' => 3],
        ]);

        $facture = app(FacturationService::class)->creerFacturePrescription($prescription);

        // 20 comprimés à 50 CDF + 3 flacons à 8 000 CDF.
        $this->assertSame(25000.0, (float) $facture->total_ttc);

        $ligneParacetamol = $facture->lignes->firstWhere('libelle', 'like', 'Paracétamol%')
            ?? $facture->lignes->first(fn ($l) => str_starts_with($l->libelle, 'Paracétamol'));

        $this->assertSame(20.0, (float) $ligneParacetamol->quantite);
        $this->assertSame(50.0, (float) $ligneParacetamol->prix_unitaire);
        $this->assertStringContainsString('2 plaquettes de 10 comprimés', $ligneParacetamol->libelle);
    }

    // ═══════════════════════════════════════════════════════════
    // Produits achetés à l'extérieur
    // ═══════════════════════════════════════════════════════════

    public function test_un_produit_absent_du_depot_part_sur_une_ordonnance_externe(): void
    {
        $consultation = $this->consultation();

        $prescription = $this->prescrire($consultation, [
            ['medicament_id' => $this->medicament('Paracétamol')->id,
                'dose' => 1, 'frequence' => 3, 'duree_jours' => 5],
            ['libelle_externe' => 'Insuline glargine 100 UI/ml',
                'dose' => 10, 'frequence' => 1, 'duree_jours' => 30],
        ]);

        $externe = $prescription->lignes->firstWhere('est_externe', true);

        $this->assertNotNull($externe);
        $this->assertNull($externe->medicament_id);
        $this->assertSame('Insuline glargine 100 UI/ml', $externe->designation());
        // Rien à délivrer ici : l'hôpital ne l'a pas.
        $this->assertSame(0.0, $externe->quantiteADelivrer());

        // La facturation interne l'ignore : seul le paracétamol est facturé.
        $facture = app(FacturationService::class)->creerFacturePrescription($prescription);
        $this->assertSame(1, $facture->lignes->count());
        $this->assertSame(1000.0, (float) $facture->total_ttc);
    }

    public function test_lordonnance_externe_simprime_sans_prix(): void
    {
        $consultation = $this->consultation();

        $prescription = $this->prescrire($consultation, [
            ['medicament_id' => $this->medicament('Paracétamol')->id,
                'dose' => 1, 'frequence' => 3, 'duree_jours' => 5],
            ['libelle_externe' => 'Insuline glargine 100 UI/ml',
                'dose' => 10, 'frequence' => 1, 'duree_jours' => 30],
        ]);

        $this->get(route('prescriptions.ordonnance', ['prescription' => $prescription, 'type' => 'externe']))
            ->assertOk()
            ->assertSee('Ordonnance externe')
            ->assertSee('Insuline glargine 100 UI/ml')
            // Ni le produit de l'officine, ni la moindre colonne de prix.
            ->assertDontSee('Paracétamol')
            ->assertDontSee('Quantité à délivrer');

        $this->get(route('prescriptions.ordonnance', $prescription))
            ->assertOk()
            ->assertSee('Paracétamol')
            ->assertSee('2 plaquettes de 10 comprimés')
            ->assertDontSee('Insuline glargine');
    }

    // ═══════════════════════════════════════════════════════════
    // Le circuit des officines
    // ═══════════════════════════════════════════════════════════

    public function test_lofficine_qui_sert_depend_du_lieu_de_soins(): void
    {
        $ambulatoire = $this->prescrire($this->consultation('consultation_externe'), [
            ['medicament_id' => $this->medicament('Paracétamol')->id, 'dose' => 1, 'frequence' => 2, 'duree_jours' => 3],
        ]);
        $this->assertSame('Officine ambulatoire', $ambulatoire->officine->nom);

        $urgences = $this->prescrire($this->consultation('urgence'), [
            ['medicament_id' => $this->medicament('Paracétamol')->id, 'dose' => 1, 'frequence' => 2, 'duree_jours' => 3],
        ]);
        $this->assertSame('Officine Urgences', $urgences->officine->nom);

        $dialyse = Service::where('type', 'dialyse')->firstOrFail();
        $hospitalise = $this->prescrire($this->consultation('hospitalisation', $dialyse), [
            ['medicament_id' => $this->medicament('Paracétamol')->id, 'dose' => 1, 'frequence' => 2, 'duree_jours' => 3],
        ]);
        $this->assertSame('Officine Dialyse / Néphrologie', $hospitalise->officine->nom);

        // Dans tous les cas, jamais le dépôt central.
        foreach ([$ambulatoire, $urgences, $hospitalise] as $prescription) {
            $this->assertFalse($prescription->officine->estDepotCentral());
        }
    }

    public function test_la_dispensation_sort_du_stock_de_lofficine_qui_sert(): void
    {
        $urgences = Officine::where('nom', 'Officine Urgences')->firstOrFail();
        $ambulatoire = Officine::where('type', 'ambulatoire')->firstOrFail();
        $paracetamol = $this->medicament('Paracétamol');

        $avantUrgences = app(OfficineService::class)->quantiteDisponible($urgences, $paracetamol->id);
        $avantAmbulatoire = app(OfficineService::class)->quantiteDisponible($ambulatoire, $paracetamol->id);

        $prescription = $this->prescrire($this->consultation('urgence'), [
            ['medicament_id' => $paracetamol->id, 'dose' => 1, 'frequence' => 3, 'duree_jours' => 5],
        ]);

        // Passage réel à la caisse : le règlement émet le bon pharmacie sans
        // lequel l'officine ne délivre pas.
        $facture = app(FacturationService::class)->creerFacturePrescription($prescription);
        app(FacturationService::class)->validerPaiement(
            $facture,
            (float) $facture->patient_part,
            'CDF',
            'especes',
            null,
            $prescription
        );

        $prescription->refresh();
        $ligne = $prescription->lignes->first();
        $erreurs = app(PharmacieService::class)->dispenser(
            $prescription->fresh(['lignes.medicament.stocks', 'consultation.visit', 'officine']),
            [$ligne->id => 20]
        );

        $this->assertSame([], $erreurs);

        // Les vingt comprimés sortent des urgences, pas de l'ambulatoire.
        $this->assertSame(
            $avantUrgences - 20,
            app(OfficineService::class)->quantiteDisponible($urgences, $paracetamol->id)
        );
        $this->assertSame(
            $avantAmbulatoire,
            app(OfficineService::class)->quantiteDisponible($ambulatoire, $paracetamol->id)
        );
    }

    public function test_le_depot_central_ne_delivre_jamais_a_un_patient(): void
    {
        $depot = Officine::where('type', 'depot_central')->firstOrFail();

        $this->assertFalse($depot->delivreAuxPatients());

        $prescription = $this->prescrire($this->consultation(), [
            ['medicament_id' => $this->medicament('Paracétamol')->id, 'dose' => 1, 'frequence' => 3, 'duree_jours' => 5],
        ]);

        // Même forcée sur le dépôt, la dispensation est refusée.
        $prescription->update(['officine_id' => $depot->id, 'statut' => 'en_attente']);

        $erreurs = app(PharmacieService::class)->dispenser(
            $prescription->fresh(['lignes.medicament.stocks', 'consultation.visit', 'officine']),
            [$prescription->lignes->first()->id => 20]
        );

        $this->assertArrayHasKey('general', $erreurs);
        $this->assertStringContainsString('ne délivre pas aux patients', $erreurs['general']);
    }

    public function test_une_rupture_en_officine_renvoie_vers_la_requisition(): void
    {
        $urgences = Officine::where('nom', 'Officine Urgences')->firstOrFail();
        $paracetamol = $this->medicament('Paracétamol');

        // Comptoir vide aux urgences.
        StockMedicament::where('officine_id', $urgences->id)
            ->where('medicament_id', $paracetamol->id)
            ->update(['quantite_disponible' => 0]);

        $prescription = $this->prescrire($this->consultation('urgence'), [
            ['medicament_id' => $paracetamol->id, 'dose' => 1, 'frequence' => 3, 'duree_jours' => 5],
        ]);

        $facture = app(FacturationService::class)->creerFacturePrescription($prescription);
        app(FacturationService::class)->validerPaiement(
            $facture,
            (float) $facture->patient_part,
            'CDF',
            'especes',
            null,
            $prescription
        );

        $prescription->refresh();
        $ligne = $prescription->lignes->first();
        $erreurs = app(PharmacieService::class)->dispenser(
            $prescription->fresh(['lignes.medicament.stocks', 'consultation.visit', 'officine']),
            [$ligne->id => 20]
        );

        $this->assertStringContainsString('Stock insuffisant', $erreurs[$ligne->id]);
        $this->assertStringContainsString('réquisition au dépôt central', $erreurs[$ligne->id]);
    }

    public function test_lofficine_se_reapprovisionne_par_requisition_au_depot(): void
    {
        $urgences = Officine::where('nom', 'Officine Urgences')->firstOrFail();
        $depot = Officine::where('type', 'depot_central')->firstOrFail();
        $paracetamol = $this->medicament('Paracétamol');
        $service = app(OfficineService::class);

        $avantOfficine = $service->quantiteDisponible($urgences, $paracetamol->id);
        $avantDepot = $service->quantiteDisponible($depot, $paracetamol->id);

        $requisition = $service->creerRequisition($urgences, [$paracetamol->id => 200], 'Rupture au comptoir');
        $this->assertSame('envoyee', $requisition->statut);

        $ligne = $requisition->fresh('lignes')->lignes->first();
        $this->assertSame([], $service->servirRequisition($requisition->fresh('lignes'), [$ligne->id => 200]));

        $this->assertSame('servie', $requisition->fresh()->statut);
        $this->assertSame($avantOfficine + 200, $service->quantiteDisponible($urgences, $paracetamol->id));
        $this->assertSame($avantDepot - 200, $service->quantiteDisponible($depot, $paracetamol->id));
    }

    public function test_chaque_officine_ouvre_avec_une_dotation(): void
    {
        foreach (Officine::where('type', 'service')->get() as $officine) {
            $this->assertGreaterThan(
                0,
                StockMedicament::where('officine_id', $officine->id)->count(),
                "L'officine {$officine->nom} n'a aucun stock de départ."
            );
        }
    }
}
