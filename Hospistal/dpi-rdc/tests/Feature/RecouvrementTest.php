<?php

namespace Tests\Feature;

use App\Models\Assurance;
use App\Models\Establishment;
use App\Models\Facture;
use App\Models\FactureConvention;
use App\Models\Patient;
use App\Models\PatientAssurance;
use App\Models\RejetConvention;
use App\Models\Service;
use App\Models\User;
use App\Models\Visit;
use App\Services\AvoirService;
use App\Services\ConventionService;
use App\Services\FacturationService;
use App\Services\RecouvrementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Le recouvrement : ce qui se passe après l'émission du bordereau, et les
 * chiffres que la direction demande.
 *
 * Ce qui est vérifié ici : que le dépôt fait courir le délai de paiement ;
 * qu'un rejet sort de ce que l'assureur doit tant qu'il n'est pas levé ;
 * que la balance âgée range chaque créance dans sa tranche ; que le taux de
 * recouvrement, le délai moyen de paiement et le taux de rejet suivent leur
 * définition ; que les bordereaux échus sans relance remontent ; et que le
 * suivi reste réservé à la facturation et à la direction.
 */
class RecouvrementTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $chef;

    protected Establishment $etab;

    protected Assurance $sonas;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->admin = User::where('email', 'admin@dpi-rdc.local')->firstOrFail();
        $this->etab = Establishment::firstOrFail();
        $this->chef = User::factory()->create(['establishment_id' => $this->etab->id]);
        $this->chef->assignRole('chef_facturation');
        $this->actingAs($this->admin);

        $this->sonas = Assurance::firstOrCreate(['code' => 'SONAS'], [
            'nom' => 'SONAS', 'taux_couverture' => 80, 'est_actif' => true,
            'delai_reglement_jours' => 30, 'mode_reglement' => 'virement', 'periodicite_facturation' => 'mensuelle',
        ]);
    }

    protected function patient(bool $assure, string $suffixe): Patient
    {
        $patient = Patient::create([
            'establishment_id' => $this->etab->id,
            'dossier_number' => 'PAT-2026-0'.$suffixe,
            'nom' => 'NGALULA', 'postnom' => 'MBUYI', 'prenom' => 'Grâce'.$suffixe,
            'sexe' => 'F',
            'date_naissance' => now()->subYears(33)->toDateString(),
            'type_prise_en_charge' => $assure ? 'assurance' : 'prive',
        ]);

        if ($assure) {
            PatientAssurance::create([
                'patient_id' => $patient->id, 'assurance_id' => $this->sonas->id,
                'numero_police' => 'SN-'.$suffixe, 'date_debut' => now()->subYear()->toDateString(),
                'annee_courante' => (int) now()->format('Y'), 'est_actif' => true,
            ]);
        }

        return $patient;
    }

    protected function factureSejour(Patient $patient): Facture
    {
        $visite = Visit::create([
            'patient_id' => $patient->id,
            'establishment_id' => $this->etab->id,
            'user_id' => $this->admin->id,
            'type' => 'hospitalisation',
            'statut' => 'en_cours',
            'date_entree' => now()->subDays(2),
            'service_id' => Service::where('is_active', true)->first()?->id,
            'motif_consultation' => 'Paludisme',
        ]);

        return app(FacturationService::class)->creerFactureHospitalisation($visite);
    }

    protected function bordereau(): FactureConvention
    {
        return app(ConventionService::class)
            ->emettre($this->sonas, now()->subMonth()->toDateString(), now()->toDateString());
    }

    protected function tableau(): array
    {
        return app(RecouvrementService::class)->tableau(now()->startOfMonth(), now()->endOfMonth());
    }

    // ═══════════════════════════════════════════════════════════
    // Dépôt et échéance
    // ═══════════════════════════════════════════════════════════

    public function test_le_depot_fait_courir_le_delai_de_paiement(): void
    {
        $facture = $this->factureSejour($this->patient(true, '1001'));
        $bordereau = $this->bordereau();

        // Sans dépôt, l'échéance part de l'émission : rien n'est encore échu.
        $this->assertSame('non_echu', app(RecouvrementService::class)->bordereauxOuverts()->first()['tranche']);

        $this->post(route('recouvrement.depot', $bordereau), [
            'date_depot' => now()->subDays(45)->toDateString(),
            'accuse_reception' => 'AR-SONAS-0912',
        ])->assertSessionHas('success', fn ($m) => str_contains($m, now()->subDays(15)->format('d/m/Y')));

        $ouvert = app(RecouvrementService::class)->bordereauxOuverts()->first();
        $this->assertSame(15, $ouvert['retard']);
        $this->assertSame('0_30', $ouvert['tranche']);

        $balance = $this->tableau()['balanceConventions'];
        $this->assertEqualsWithDelta((float) $facture->assurance_part, $balance['total']['0_30'], 0.01);
    }

    // ═══════════════════════════════════════════════════════════
    // Rejets
    // ═══════════════════════════════════════════════════════════

    public function test_un_rejet_sort_du_du_tant_quil_nest_pas_leve(): void
    {
        $facture = $this->factureSejour($this->patient(true, '1002'));
        $bordereau = $this->bordereau();
        $ligne = $bordereau->lignes->first();
        $du = $bordereau->resteDu();

        $this->post(route('recouvrement.rejet', $bordereau), [
            'ligne_id' => $ligne->id,
            'motif_categorie' => 'bon_absent',
            'motif' => 'Bon de prise en charge non joint au dossier.',
            'date_rejet' => now()->toDateString(),
        ])->assertSessionHas('success');

        $rejet = RejetConvention::firstOrFail();
        $this->assertSame($facture->id, $rejet->facture_id);
        $this->assertEqualsWithDelta($du, (float) $rejet->montant, 0.01);
        $this->assertEquals(0.0, $bordereau->fresh()->resteDu());
        // Entièrement rejeté, il n'a rien reçu : il n'est pas « réglé ».
        $this->assertSame('emise', $bordereau->fresh()->statut);

        $ligneAssureur = $this->tableau()['parAssureur']->firstWhere('assurance.id', $this->sonas->id);
        $this->assertEquals(100.0, $ligneAssureur['taux_rejet_valeur']);
        $this->assertSame(1, $ligneAssureur['rejetees']);

        // La pièce est redéposée, l'assureur accepte : le rejet est levé.
        $this->post(route('recouvrement.rejet.traiter', $rejet), [
            'statut' => 'leve', 'suite_donnee' => 'Bon redéposé le '.now()->format('d/m/Y').'.',
        ])->assertSessionHas('success');

        $this->assertEqualsWithDelta($du, $bordereau->fresh()->resteDu(), 0.01);
        $this->assertSame($this->admin->id, $rejet->fresh()->traite_par);
    }

    public function test_un_rejet_abandonne_reste_perdu(): void
    {
        $this->factureSejour($this->patient(true, '1003'));
        $bordereau = $this->bordereau();
        $ligne = $bordereau->lignes->first();

        $this->post(route('recouvrement.rejet', $bordereau), [
            'ligne_id' => $ligne->id, 'motif_categorie' => 'hors_delai',
            'montant' => 10000, 'date_rejet' => now()->toDateString(),
        ]);
        $rejet = RejetConvention::firstOrFail();
        $du = $bordereau->fresh()->resteDu();

        $this->post(route('recouvrement.rejet.traiter', $rejet), [
            'statut' => 'abandonne', 'suite_donnee' => 'Délai contractuel dépassé, non contestable.',
        ]);

        $this->assertEqualsWithDelta($du, $bordereau->fresh()->resteDu(), 0.01);
        $this->assertSame('abandonne', $rejet->fresh()->statut);
    }

    public function test_on_ne_rejette_pas_plus_que_la_part_de_la_facture(): void
    {
        $this->factureSejour($this->patient(true, '1004'));
        $bordereau = $this->bordereau();

        $this->post(route('recouvrement.rejet', $bordereau), [
            'ligne_id' => $bordereau->lignes->first()->id, 'motif_categorie' => 'autre',
            'montant' => 999999999, 'date_rejet' => now()->toDateString(),
        ])->assertSessionHas('error');

        $this->assertSame(0, RejetConvention::count());
    }

    // ═══════════════════════════════════════════════════════════
    // Indicateurs
    // ═══════════════════════════════════════════════════════════

    public function test_taux_de_recouvrement_et_delai_moyen_de_paiement(): void
    {
        $facture = $this->factureSejour($this->patient(false, '1005'));
        $moitie = round((float) $facture->total_ttc / 2, 2);
        app(FacturationService::class)->validerPaiement($facture->fresh(), $moitie, 'CDF', 'especes');

        $tableau = $this->tableau();
        $ind = $tableau['indicateurs'];

        $this->assertEqualsWithDelta((float) $facture->total_ttc, $ind['facture'], 0.01);
        $this->assertEqualsWithDelta($moitie, $ind['encaisse']['guichet'], 0.01);
        $this->assertEquals(50.0, $ind['taux_recouvrement']);
        $this->assertEqualsWithDelta($moitie, $ind['creance_patients'], 0.01);
        $this->assertSame((int) round(0.5 * $tableau['jours']), $ind['dso']);
    }

    public function test_les_avoirs_reduisent_ce_qui_a_ete_facture(): void
    {
        $facture = $this->factureSejour($this->patient(false, '1006'));
        app(AvoirService::class)->emettre($facture, 'doublon', 'Émise deux fois.');

        $this->assertEquals(0.0, $this->tableau()['indicateurs']['facture']);
    }

    public function test_la_part_assureur_hors_bordereau_est_une_creance_a_presenter(): void
    {
        $facture = $this->factureSejour($this->patient(true, '1007'));

        $ind = $this->tableau()['indicateurs'];
        $this->assertEqualsWithDelta((float) $facture->assurance_part, $ind['creance_a_facturer'], 0.01);
        $this->assertEquals(0.0, $ind['creance_conventions']);

        $this->bordereau();

        $ind = $this->tableau()['indicateurs'];
        $this->assertEquals(0.0, $ind['creance_a_facturer']);
        $this->assertEqualsWithDelta((float) $facture->assurance_part, $ind['creance_conventions'], 0.01);
    }

    public function test_la_balance_des_patients_vieillit_avec_la_facture(): void
    {
        $facture = $this->factureSejour($this->patient(false, '1008'));

        $this->assertEqualsWithDelta((float) $facture->patient_part, $this->tableau()['balancePatients']['0_30'], 0.01);

        $this->travel(45)->days();

        $balance = $this->tableau()['balancePatients'];
        $this->assertEquals(0.0, $balance['0_30']);
        $this->assertEqualsWithDelta((float) $facture->patient_part, $balance['31_60'], 0.01);
        $this->assertSame($facture->patient_id, $this->tableau()['topDebiteurs']->first()['patient']->id);
    }

    // ═══════════════════════════════════════════════════════════
    // Relances
    // ═══════════════════════════════════════════════════════════

    public function test_un_bordereau_echu_sans_relance_remonte(): void
    {
        $this->factureSejour($this->patient(true, '1009'));
        $bordereau = $this->bordereau();
        $bordereau->update(['date_depot' => now()->subDays(40)->toDateString()]);

        $this->assertSame($bordereau->id, $this->tableau()['relancesAFaire']->first()['bordereau']->id);

        $this->post(route('recouvrement.relance', $bordereau), [
            'date_relance' => now()->toDateString(),
            'mode' => 'telephone',
            'commentaire' => 'Service financier : paiement promis sous huit jours.',
            'prochaine_action' => now()->addDays(8)->toDateString(),
        ])->assertSessionHas('success');

        $this->assertTrue($this->tableau()['relancesAFaire']->isEmpty());
        $this->assertSame('telephone',
            $this->tableau()['parAssureur']->firstWhere('assurance.id', $this->sonas->id)['derniere_relance']->mode);

        // Huit jours plus tard, la prochaine action est due.
        $this->travel(8)->days();
        $this->assertFalse($this->tableau()['relancesAFaire']->isEmpty());
    }

    // ═══════════════════════════════════════════════════════════
    // Écrans et droits
    // ═══════════════════════════════════════════════════════════

    public function test_le_chef_de_facturation_lit_le_tableau_et_le_bordereau(): void
    {
        $this->factureSejour($this->patient(true, '1010'));
        $bordereau = $this->bordereau();
        $this->actingAs($this->chef);

        $this->get(route('recouvrement.index'))
            ->assertOk()
            ->assertSee('Taux de recouvrement')
            ->assertSee('Balance âgée')
            ->assertSee('SONAS');

        $this->get(route('conventions.show', $bordereau))
            ->assertOk()
            ->assertSee('Dépôt chez l\'assureur', false)
            ->assertSee('Rejets')
            ->assertSee('Relances');

        $this->get(route('dashboard'))->assertOk()->assertSee('Tableau de recouvrement');
    }

    public function test_le_caissier_ne_suit_pas_les_creances(): void
    {
        $this->factureSejour($this->patient(true, '1011'));
        $bordereau = $this->bordereau();

        $caissier = User::factory()->create(['establishment_id' => $this->etab->id]);
        $caissier->assignRole('caissier');
        $this->actingAs($caissier);

        $this->get(route('recouvrement.index'))->assertForbidden();
        $this->post(route('recouvrement.rejet', $bordereau), [
            'ligne_id' => $bordereau->lignes->first()->id, 'motif_categorie' => 'autre', 'date_rejet' => now()->toDateString(),
        ])->assertForbidden();
        $this->post(route('recouvrement.relance', $bordereau), [
            'date_relance' => now()->toDateString(), 'mode' => 'telephone',
        ])->assertForbidden();
    }
}
