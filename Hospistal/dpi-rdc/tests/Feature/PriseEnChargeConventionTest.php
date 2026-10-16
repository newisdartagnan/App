<?php

namespace Tests\Feature;

use App\Models\Assurance;
use App\Models\BonPriseEnCharge;
use App\Models\Establishment;
use App\Models\ExamenLaboratoire;
use App\Models\Facture;
use App\Models\Patient;
use App\Models\PatientAssurance;
use App\Models\ResultatExamen;
use App\Models\Service;
use App\Models\TarifConvention;
use App\Models\TypeExamen;
use App\Models\User;
use App\Models\Visit;
use App\Services\AvoirService;
use App\Services\ConventionService;
use App\Services\FacturationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Ce qui conditionne le tiers payant : un contrat en cours, un bon de prise
 * en charge quand la convention l'exige, et le prix qu'elle a négocié.
 *
 * Ce qui est vérifié ici : qu'une convention échue ou pas encore en vigueur
 * ne se facture pas ; qu'un ayant droit radié non plus ; qu'une convention
 * exigeant un bon ne paie que sur bon valide, dans la limite de son
 * montant ; que la facture et le bordereau citent le bon ; que les prix
 * négociés et le coefficient remplacent le prix public ; et que le guichet
 * lit en clair pourquoi le tiers payant ne s'est pas appliqué.
 */
class PriseEnChargeConventionTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Establishment $etab;

    protected Patient $patient;

    protected Visit $sejour;

    protected Assurance $snel;

    protected PatientAssurance $contrat;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->admin = User::where('email', 'admin@dpi-rdc.local')->firstOrFail();
        $this->etab = Establishment::firstOrFail();
        $this->actingAs($this->admin);

        $this->snel = Assurance::create([
            'nom' => 'SNEL', 'code' => 'SNEL', 'taux_couverture' => 100, 'est_actif' => true,
            'delai_reglement_jours' => 60, 'mode_reglement' => 'virement', 'periodicite_facturation' => 'mensuelle',
        ]);

        $this->patient = Patient::create([
            'establishment_id' => $this->etab->id,
            'dossier_number' => 'PAT-2026-009900',
            'nom' => 'TSHIBANGU', 'postnom' => 'KALALA', 'prenom' => 'Jean',
            'sexe' => 'M',
            'date_naissance' => now()->subYears(44)->toDateString(),
            'type_prise_en_charge' => 'assurance',
            'assurance_nom' => 'SNEL',
        ]);

        $this->contrat = PatientAssurance::create([
            'patient_id' => $this->patient->id,
            'assurance_id' => $this->snel->id,
            'numero_police' => 'SNEL-0457',
            'date_debut' => now()->subYear()->toDateString(),
            'annee_courante' => (int) now()->format('Y'),
            'est_actif' => true,
        ]);

        $this->sejour = Visit::create([
            'patient_id' => $this->patient->id,
            'establishment_id' => $this->etab->id,
            'user_id' => $this->admin->id,
            'type' => 'hospitalisation',
            'statut' => 'en_cours',
            'date_entree' => now()->subDays(2),
            'service_id' => Service::where('is_active', true)->first()?->id,
            'motif_consultation' => 'Hypertension mal contrôlée',
        ]);
    }

    protected function factureSejour(): Facture
    {
        return app(FacturationService::class)->creerFactureHospitalisation($this->sejour);
    }

    protected function bon(array $remplacements = []): BonPriseEnCharge
    {
        return BonPriseEnCharge::create(array_merge([
            'numero' => 'BPC-'.random_int(1000, 9999),
            'patient_id' => $this->patient->id,
            'assurance_id' => $this->snel->id,
            'date_emission' => now()->subDay()->toDateString(),
            'date_expiration' => now()->addDays(14)->toDateString(),
            'devise' => 'CDF',
            'statut' => 'valide',
            'saisi_par' => $this->admin->id,
        ], $remplacements));
    }

    // ═══════════════════════════════════════════════════════════
    // Les dates du contrat
    // ═══════════════════════════════════════════════════════════

    public function test_une_convention_en_cours_couvre(): void
    {
        $this->snel->update(['contrat_debut' => now()->subYear(), 'contrat_fin' => now()->addMonths(6)]);

        $facture = $this->factureSejour();

        $this->assertEqualsWithDelta((float) $facture->total_ttc, (float) $facture->assurance_part, 0.01);
    }

    public function test_une_convention_echue_ne_se_facture_plus(): void
    {
        $this->snel->update(['contrat_debut' => now()->subYears(2), 'contrat_fin' => now()->subDay()]);

        $facture = $this->factureSejour();

        $this->assertEquals(0, (float) $facture->assurance_part);
        $this->assertEqualsWithDelta((float) $facture->total_ttc, (float) $facture->patient_part, 0.01);

        // Le guichet lit pourquoi, et ce qu'il peut faire.
        $this->get(route('caisse.show', $facture))
            ->assertOk()
            ->assertSee('a expiré le '.now()->subDay()->format('d/m/Y'));
    }

    public function test_une_convention_pas_encore_en_vigueur_non_plus(): void
    {
        $this->snel->update(['contrat_debut' => now()->addWeek()]);

        $this->assertEquals(0, (float) $this->factureSejour()->assurance_part);
        $this->assertStringContainsString('ne prend effet que le',
            app(FacturationService::class)->priseEnCharge($this->patient)['motif']);
    }

    public function test_un_ayant_droit_radie_nest_plus_couvert(): void
    {
        $this->contrat->update(['date_fin' => now()->subMonth()->toDateString()]);

        $this->assertEquals(0, (float) $this->factureSejour()->assurance_part);
        $this->assertStringContainsString('a pris fin le',
            app(FacturationService::class)->priseEnCharge($this->patient)['motif']);
    }

    // ═══════════════════════════════════════════════════════════
    // Le bon de prise en charge
    // ═══════════════════════════════════════════════════════════

    public function test_sans_bon_valide_la_convention_qui_lexige_ne_paie_pas(): void
    {
        $this->snel->update(['exige_bon_prise_en_charge' => true]);
        $this->bon(['date_expiration' => now()->subDay()->toDateString()]);

        $facture = $this->factureSejour();

        $this->assertEquals(0, (float) $facture->assurance_part);
        $this->assertNull($facture->bon_prise_en_charge_id);

        $this->get(route('caisse.show', $facture))
            ->assertOk()
            ->assertSee('exige un bon de prise en charge')
            ->assertSee('Saisir le bon');
    }

    public function test_avec_un_bon_valide_la_facture_le_cite(): void
    {
        $this->snel->update(['exige_bon_prise_en_charge' => true]);
        $bon = $this->bon(['numero' => 'BPC-2026-0457']);

        $facture = $this->factureSejour();

        $this->assertSame($bon->id, $facture->bon_prise_en_charge_id);
        $this->assertGreaterThan(0, (float) $facture->assurance_part);

        $this->get(route('caisse.show', $facture))->assertOk()->assertSee('BPC-2026-0457');
        $this->get(route('caisse.imprimer', $facture))->assertOk()->assertSee('BPC-2026-0457');
    }

    public function test_le_bon_limite_ce_que_lassureur_paie(): void
    {
        $this->snel->update(['exige_bon_prise_en_charge' => true]);
        $bon = $this->bon(['montant_autorise' => 50000]);

        $facture = $this->factureSejour();

        $this->assertEqualsWithDelta(50000, (float) $facture->assurance_part, 0.01);
        $this->assertEqualsWithDelta((float) $facture->total_ttc - 50000, (float) $facture->patient_part, 0.01);
        $this->assertEqualsWithDelta(50000, (float) $bon->fresh()->montant_consomme, 0.01);
        $this->assertSame('Épuisé', $bon->fresh()->etat());

        // L'avoir rend au bon ce que l'assureur ne paie plus.
        app(AvoirService::class)->emettre($facture, 'erreur_tarif', 'Séjour facturé deux fois.');
        $this->assertEquals(0, (float) $bon->fresh()->montant_consomme);
    }

    public function test_un_bon_annule_nouvre_plus_rien(): void
    {
        $this->snel->update(['exige_bon_prise_en_charge' => true]);
        $bon = $this->bon();

        $this->post(route('bpc.annuler', $bon), ['motif' => 'Saisi sur le mauvais patient'])
            ->assertSessionHas('success');

        $this->assertSame('annule', $bon->fresh()->statut);
        $this->assertEquals(0, (float) $this->factureSejour()->assurance_part);
    }

    public function test_le_guichet_saisit_le_bon_depuis_le_dossier(): void
    {
        $this->snel->update(['exige_bon_prise_en_charge' => true]);

        $caissier = User::factory()->create(['establishment_id' => $this->etab->id]);
        $caissier->assignRole('caissier');
        $this->actingAs($caissier);

        $this->get(route('bpc.index', $this->patient))
            ->assertOk()
            ->assertSee('exige un bon de prise en charge');

        $this->post(route('bpc.store', $this->patient), [
            'assurance_id' => $this->snel->id,
            'numero' => 'SNEL/RH/2026/118',
            'date_emission' => now()->toDateString(),
            'date_expiration' => now()->addMonth()->toDateString(),
            'objet' => 'Hospitalisation cardiologie',
        ])->assertSessionHas('success', fn ($m) => str_contains($m, 'Le tiers payant s\'appliquera'));

        // Le même numéro ne s'enregistre pas deux fois pour la même convention.
        $this->post(route('bpc.store', $this->patient), [
            'assurance_id' => $this->snel->id,
            'numero' => 'SNEL/RH/2026/118',
            'date_emission' => now()->toDateString(),
            'date_expiration' => now()->addMonth()->toDateString(),
        ])->assertSessionHasErrors('numero');

        $this->assertGreaterThan(0, (float) $this->factureSejour()->assurance_part);
    }

    public function test_le_bordereau_cite_le_bon(): void
    {
        $this->snel->update(['exige_bon_prise_en_charge' => true]);
        $this->bon(['numero' => 'BPC-BORD-77']);
        $this->factureSejour();

        $bordereau = app(ConventionService::class)
            ->emettre($this->snel, now()->subDay()->toDateString(), now()->toDateString());

        $this->get(route('conventions.imprimer', $bordereau))->assertOk()->assertSee('BPC-BORD-77');
    }

    // ═══════════════════════════════════════════════════════════
    // Le tarif de la convention
    // ═══════════════════════════════════════════════════════════

    public function test_le_prix_negocie_de_la_journee_remplace_le_prix_public(): void
    {
        TarifConvention::create(['assurance_id' => $this->snel->id, 'type' => 'hospitalisation', 'prix' => 50000]);

        $facture = $this->factureSejour();
        $ligne = $facture->lignes()->where('type', 'hospitalisation')->firstOrFail();

        $this->assertEquals(50000, (float) $ligne->prix_unitaire);
        $this->assertEqualsWithDelta(50000 * (float) $ligne->quantite, (float) $facture->total_ttc, 0.01);
    }

    public function test_le_coefficient_majore_les_prestations_sans_prix_negocie(): void
    {
        $this->snel->update(['coefficient_tarif' => 1.2]);

        $examen = ExamenLaboratoire::create([
            'numero_bon' => 'LAB-CV-'.random_int(1000, 9999),
            'patient_id' => $this->patient->id,
            'visit_id' => $this->sejour->id,
            'prescripteur_id' => $this->admin->id,
            'date_prescription' => now(),
            'statut' => 'prescrit',
            'domaine' => 'labo',
        ]);
        [$premier, $second] = TypeExamen::where('domaine', 'labo')->where('prix', '>', 0)->get()
            ->filter(fn ($t) => count($t->valeurs_reference['parametres'] ?? []) <= 1)
            ->take(2)->values()->all();
        foreach ([$premier, $second] as $type) {
            ResultatExamen::create(['examen_id' => $examen->id, 'type_examen_id' => $type->id]);
        }

        // Le second examen a un prix négocié : il l'emporte sur le coefficient.
        TarifConvention::create([
            'assurance_id' => $this->snel->id, 'type' => 'examen_labo',
            'reference_id' => $second->id, 'reference_libelle' => $second->libelle, 'prix' => 1234,
        ]);

        $facture = app(FacturationService::class)->creerFactureExamen($examen);

        $this->assertEqualsWithDelta((float) $premier->prix * 1.2,
            (float) $facture->lignes()->where('reference_id', $premier->id)->value('prix_unitaire'), 0.01);
        $this->assertEquals(1234, (float) $facture->lignes()->where('reference_id', $second->id)->value('prix_unitaire'));
    }

    public function test_sans_tiers_payant_le_patient_paie_le_prix_public(): void
    {
        TarifConvention::create(['assurance_id' => $this->snel->id, 'type' => 'hospitalisation', 'prix' => 50000]);
        $this->snel->update(['contrat_fin' => now()->subDay()]);

        $ligne = $this->factureSejour()->lignes()->where('type', 'hospitalisation')->firstOrFail();

        $this->assertEquals((float) config('dpi.tarifs_cdf.hospitalisation_jour', 35000), (float) $ligne->prix_unitaire);
    }

    public function test_la_facturation_regle_le_contrat_et_le_tarif_depuis_lecran(): void
    {
        $this->post(route('assurances.update', $this->snel), [
            'nom' => 'SNEL', 'code' => 'SNEL', 'taux_couverture' => 100, 'ticket_moderateur' => 0,
            'delai_reglement_jours' => 60, 'mode_reglement' => 'virement', 'periodicite_facturation' => 'mensuelle',
            'contrat_debut' => '2026-01-01', 'contrat_fin' => '2026-12-31',
            'exige_bon_prise_en_charge' => '1',
            'coefficient_pct' => 20,
        ])->assertSessionHas('success');

        $snel = $this->snel->fresh();
        $this->assertTrue($snel->exige_bon_prise_en_charge);
        $this->assertSame('2026-12-31', $snel->contrat_fin->toDateString());
        $this->assertEquals(1.2, (float) $snel->coefficient_tarif);
        $this->assertSame('Prix public + 20 %', $snel->libelleCoefficient());

        $this->post(route('assurances.tarifs', $snel), ['prestation' => 'hospitalisation:', 'prix' => 50000])
            ->assertSessionHas('success');
        // Une seconde saisie remplace la première au lieu de s'y ajouter.
        $this->post(route('assurances.tarifs', $snel), ['prestation' => 'hospitalisation:', 'prix' => 52000])
            ->assertSessionHas('success');

        $this->assertSame(1, TarifConvention::where('assurance_id', $snel->id)->count());
        $this->assertEquals(52000, (float) TarifConvention::where('assurance_id', $snel->id)->value('prix'));

        $this->get(route('assurances.show', $snel))
            ->assertOk()
            ->assertSee('Journée d\'hospitalisation (toutes)')
            ->assertSee('Prix public + 20 %');

        $this->get(route('assurances.index'))->assertOk()->assertSee('31/12/2026');
    }
}
