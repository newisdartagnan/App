<?php

namespace Tests\Feature;

use App\Models\ActePlanificationFamiliale;
use App\Models\Establishment;
use App\Models\Patient;
use App\Models\User;
use App\Models\Vaccination;
use App\Services\PreventionService;
use App\Services\RapportSnisService;
use App\Services\SystemeSanteService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * La planification familiale et le Programme élargi de vaccination.
 *
 * Deux registres qui vivaient sur papier, et deux sections que le rapport
 * déclarait franchement ne pas savoir remplir.
 *
 * Ce qui est vérifié ici : que la PF compte des actes et non des femmes,
 * que le sexe s'accorde avec la méthode, qu'une dose ne se donne qu'une
 * fois, que le carnet dit ce qui manque, et que les deux sections sortent
 * au rapport dans la forme du canevas.
 */
class PreventionTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Establishment $etab;

    protected Carbon $mois;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->admin = User::where('email', 'admin@dpi-rdc.local')->firstOrFail();
        $this->etab = Establishment::firstOrFail();
        $this->actingAs($this->admin);

        $this->mois = Carbon::create(2026, 7, 1)->startOfMonth();
    }

    protected function client(string $nom, int $ageAns = 28, string $sexe = 'F'): Patient
    {
        return Patient::create([
            'establishment_id' => $this->etab->id,
            'dossier_number' => 'PRV-'.$nom.'-'.random_int(1000, 9999),
            'nom' => $nom, 'prenom' => 'Test', 'sexe' => $sexe,
            'date_naissance' => $this->mois->copy()->subYears($ageAns)->toDateString(),
            'type_prise_en_charge' => 'prive',
        ]);
    }

    protected function enfant(string $nom, int $ageMois = 12): Patient
    {
        return Patient::create([
            'establishment_id' => $this->etab->id,
            'dossier_number' => 'PEV-'.$nom.'-'.random_int(1000, 9999),
            'nom' => $nom, 'prenom' => 'Test', 'sexe' => 'M',
            'date_naissance' => $this->mois->copy()->subMonths($ageMois)->toDateString(),
            'type_prise_en_charge' => 'prive',
        ]);
    }

    protected function service(): PreventionService
    {
        return app(PreventionService::class);
    }

    protected function rapport(): array
    {
        return app(RapportSnisService::class)
            ->rapport($this->mois->year, $this->mois->month, $this->etab->id);
    }

    // ═══════════════════════════════════════════════════════════
    // Planification familiale
    // ═══════════════════════════════════════════════════════════

    public function test_un_acte_pf_senregistre_avec_son_canal_et_sa_tranche(): void
    {
        $acte = $this->service()->enregistrerActePf($this->client('JEUNE', 17), [
            'methode' => 'implanon',
            'canal' => 'dbc',
            'date_acte' => $this->mois->copy()->addDays(3)->toDateString(),
        ]);

        $this->assertSame('dbc', $acte->canal);
        $this->assertSame('quinze_19', $acte->tranche());
        $this->assertSame('nouvelle', $acte->type_acceptation);
    }

    public function test_une_methode_masculine_ne_se_compte_pas_chez_une_femme(): void
    {
        // Le canevas ventile par sexe : un préservatif masculin compté chez
        // une femme déplace une ligne entière.
        $this->expectException(\InvalidArgumentException::class);

        $this->service()->enregistrerActePf($this->client('DAME'), ['methode' => 'vasectomie']);
    }

    public function test_une_methode_feminine_ne_se_compte_pas_chez_un_homme(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service()->enregistrerActePf(
            $this->client('MONSIEUR', 30, 'M'),
            ['methode' => 'implanon'],
        );
    }

    public function test_un_homme_recoit_bien_une_methode_masculine(): void
    {
        $acte = $this->service()->enregistrerActePf(
            $this->client('VOLONTAIRE', 40, 'M'),
            ['methode' => 'preservatif_masculin'],
        );

        $this->assertSame('preservatif_masculin', $acte->methode);
    }

    public function test_un_acte_sans_methode_est_refuse(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service()->enregistrerActePf($this->client('VIDE'), ['type_acte' => 'methode']);
    }

    public function test_un_conseil_post_partum_na_pas_besoin_de_methode(): void
    {
        $acte = $this->service()->enregistrerActePf($this->client('CONSEIL'), [
            'type_acte' => 'conseil_post_partum',
        ]);

        $this->assertNull($acte->methode);
        $this->assertSame('conseil_post_partum', $acte->type_acte);
    }

    public function test_le_rapport_ventile_la_pf_par_methode_canal_et_tranche(): void
    {
        foreach ([['A', 17, 'ess'], ['B', 22, 'ess'], ['C', 30, 'dbc']] as [$nom, $age, $canal]) {
            $this->service()->enregistrerActePf($this->client($nom, $age), [
                'methode' => 'dmpa_im',
                'canal' => $canal,
                'date_acte' => $this->mois->copy()->addDays(5)->toDateString(),
            ]);
        }

        $ligne = $this->rapport()['planification_familiale']['lignes']['dmpa_im']['ventilation'];

        $this->assertSame(1, $ligne['canaux']['ess']['quinze_19']);
        $this->assertSame(1, $ligne['canaux']['ess']['vingt_24']);
        $this->assertSame(1, $ligne['canaux']['dbc']['vingt_cinq_plus']);
        $this->assertSame(0, $ligne['canaux']['dbc']['dix_14']);
        $this->assertSame(3, $ligne['total']);
    }

    public function test_une_cliente_qui_revient_compte_chaque_fois(): void
    {
        // Le canevas compte des actes, pas des femmes : c'est voulu, ce
        // qu'on mesure est la continuité de l'approvisionnement.
        $cliente = $this->client('FIDELE');

        foreach ([3, 10, 24] as $jour) {
            $this->service()->enregistrerActePf($cliente, [
                'methode' => 'pilule_combinee',
                'type_acceptation' => $jour === 3 ? 'nouvelle' : 'renouvellement',
                'date_acte' => $this->mois->copy()->addDays($jour)->toDateString(),
            ]);
        }

        $pf = $this->rapport()['planification_familiale'];

        $this->assertSame(3, $pf['total']);
        $this->assertSame(1, $pf['nouvelles']);
        $this->assertSame(2, $pf['renouvellements']);
    }

    public function test_le_post_partum_se_compte_a_part_et_par_canal(): void
    {
        $this->service()->enregistrerActePf($this->client('ACCOUCHEE'), [
            'methode' => 'implanon',
            'post_partum' => true,
            'date_acte' => $this->mois->copy()->addDays(4)->toDateString(),
        ]);

        $this->service()->enregistrerActePf($this->client('CONSEILLEE'), [
            'type_acte' => 'conseil_post_partum',
            'date_acte' => $this->mois->copy()->addDays(4)->toDateString(),
        ]);

        $postPartum = $this->rapport()['planification_familiale']['post_partum'];

        $this->assertSame(1, $postPartum['avec_methode']['ess']);
        $this->assertSame(0, $postPartum['avec_methode']['dbc']);
        $this->assertSame(1, $postPartum['conseillees']['ess']);
    }

    public function test_une_methode_non_moderne_ne_compte_pas_au_post_partum(): void
    {
        // Le canevas demande « une méthode contraceptive moderne » : le
        // collier du cycle n'en est pas une.
        $this->service()->enregistrerActePf($this->client('COLLIER'), [
            'methode' => 'collier_cycle',
            'post_partum' => true,
            'date_acte' => $this->mois->copy()->addDays(4)->toDateString(),
        ]);

        $this->assertSame(
            0,
            $this->rapport()['planification_familiale']['post_partum']['avec_methode']['ess']
        );
    }

    public function test_une_cliente_sans_date_de_naissance_est_comptee_a_part(): void
    {
        $sansAge = Patient::create([
            'establishment_id' => $this->etab->id,
            'dossier_number' => 'PRV-SANSAGE',
            'nom' => 'SANSAGE', 'prenom' => 'Test', 'sexe' => 'F',
            'type_prise_en_charge' => 'prive',
        ]);

        $this->service()->enregistrerActePf($sansAge, [
            'methode' => 'jadelle',
            'date_acte' => $this->mois->copy()->addDays(6)->toDateString(),
        ]);

        $ligne = $this->rapport()['planification_familiale']['lignes']['jadelle']['ventilation'];

        $this->assertSame(1, $ligne['hors_tranche']);
        $this->assertSame(1, $ligne['total']);
    }

    // ═══════════════════════════════════════════════════════════
    // Vaccination
    // ═══════════════════════════════════════════════════════════

    public function test_une_dose_senregistre_avec_sa_strategie(): void
    {
        $dose = $this->service()->vacciner($this->enfant('BEBE', 2), [
            'antigene' => 'bcg',
            'strategie' => 'mobile',
            'date_vaccination' => $this->mois->copy()->addDays(2)->toDateString(),
        ]);

        $this->assertSame('bcg', $dose->antigene);
        $this->assertSame('mobile', $dose->strategie);
    }

    public function test_une_dose_ne_se_donne_quune_fois(): void
    {
        // Le deuxième enregistrement gonflerait la couverture vaccinale.
        $enfant = $this->enfant('DOUBLE', 3);
        $this->service()->vacciner($enfant, ['antigene' => 'bcg']);

        $this->expectException(\RuntimeException::class);
        $this->service()->vacciner($enfant, ['antigene' => 'bcg']);
    }

    public function test_un_antigene_inconnu_est_refuse(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service()->vacciner($this->enfant('INCONNU'), ['antigene' => 'vaccin_imaginaire']);
    }

    public function test_le_carnet_dit_ce_qui_manque(): void
    {
        $enfant = $this->enfant('CARNET', 12);

        foreach (['bcg', 'vpo_1', 'vpo_2'] as $antigene) {
            $this->service()->vacciner($enfant, ['antigene' => $antigene]);
        }

        $carnet = $this->service()->carnet($enfant);

        $this->assertSame(3, $carnet['doses_recues']);
        $this->assertFalse($carnet['complet']);
        $this->assertContains('VPO 3', $carnet['manquants_pour_etre_complet']);
        $this->assertTrue($carnet['lignes']['bcg']['recue']);
        $this->assertFalse($carnet['lignes']['vpo_3']['recue']);
    }

    public function test_le_carnet_signale_le_retard_sans_condamner(): void
    {
        // Un rattrapage se fait : l'écran dit « en retard » et non
        // « manquant », pour appeler à le faire.
        $enfant = $this->enfant('RETARD', 24);

        $carnet = $this->service()->carnet($enfant);

        $this->assertTrue($carnet['lignes']['bcg']['en_retard']);
        $this->assertGreaterThan(0, $carnet['en_retard']);
    }

    public function test_un_enfant_ayant_tout_recu_est_complet(): void
    {
        $enfant = $this->enfant('COMPLET', 12);

        foreach (Vaccination::SCHEMA_COMPLET as $antigene) {
            $this->service()->vacciner($enfant, [
                'antigene' => $antigene,
                'date_vaccination' => $this->mois->copy()->addDays(5)->toDateString(),
            ]);
        }

        $this->assertTrue($this->service()->carnet($enfant)['complet']);
    }

    public function test_le_rapport_ventile_les_doses_par_antigene_et_strategie(): void
    {
        app(SystemeSanteService::class)->definir('cs');

        foreach ([['A', 'fixe'], ['B', 'fixe'], ['C', 'avance']] as [$nom, $strategie]) {
            $this->service()->vacciner($this->enfant($nom, 3), [
                'antigene' => 'bcg',
                'strategie' => $strategie,
                'date_vaccination' => $this->mois->copy()->addDays(4)->toDateString(),
            ]);
        }

        $ligne = $this->rapport()['vaccination']['lignes']['bcg'];

        $this->assertSame(2, $ligne['strategies']['fixe']);
        $this->assertSame(1, $ligne['strategies']['avance']);
        $this->assertSame(0, $ligne['strategies']['mobile']);
        $this->assertSame(3, $ligne['total']);
    }

    public function test_le_hpv_a_son_propre_tableau_par_tranche(): void
    {
        app(SystemeSanteService::class)->definir('cs');

        $this->service()->vacciner($this->client('FILLE11', 11), [
            'antigene' => 'hpv',
            'date_vaccination' => $this->mois->copy()->addDays(3)->toDateString(),
        ]);
        $this->service()->vacciner($this->client('FILLE15', 15), [
            'antigene' => 'hpv',
            'date_vaccination' => $this->mois->copy()->addDays(3)->toDateString(),
        ]);

        $pev = $this->rapport()['vaccination'];

        $this->assertSame(2, $pev['hpv']['total']);
        $this->assertSame(1, $pev['hpv']['lignes']['neuf_13']['total']);
        $this->assertSame(1, $pev['hpv']['lignes']['quatorze_plus']['total']);
        // Le HPV ne se mélange pas aux antigènes du nourrisson.
        $this->assertArrayNotHasKey('hpv', $pev['lignes']);
    }

    public function test_un_enfant_est_compte_ecv_le_mois_ou_il_termine(): void
    {
        app(SystemeSanteService::class)->definir('cs');

        $enfant = $this->enfant('ECV', 12);
        $schema = Vaccination::SCHEMA_COMPLET;
        $derniere = array_pop($schema);

        // Tout sauf la dernière dose, le mois précédent.
        foreach ($schema as $antigene) {
            $this->service()->vacciner($enfant, [
                'antigene' => $antigene,
                'date_vaccination' => $this->mois->copy()->subDays(10)->toDateString(),
            ]);
        }

        $this->assertSame(0, $this->rapport()['vaccination']['enfants_completement_vaccines']);

        // La dernière dose tombe ce mois-ci : c'est elle qui le rend complet.
        $this->service()->vacciner($enfant, [
            'antigene' => $derniere,
            'date_vaccination' => $this->mois->copy()->addDays(8)->toDateString(),
        ]);

        $this->assertSame(1, $this->rapport()['vaccination']['enfants_completement_vaccines']);
    }

    public function test_un_enfant_deja_complet_nest_pas_recompte_le_mois_suivant(): void
    {
        // Sinon le même enfant serait compté douze fois dans l'année.
        app(SystemeSanteService::class)->definir('cs');

        $enfant = $this->enfant('DEJA', 12);

        foreach (Vaccination::SCHEMA_COMPLET as $antigene) {
            $this->service()->vacciner($enfant, [
                'antigene' => $antigene,
                'date_vaccination' => $this->mois->copy()->subMonth()->addDays(5)->toDateString(),
            ]);
        }

        $this->assertSame(0, $this->rapport()['vaccination']['enfants_completement_vaccines']);
    }

    // ═══════════════════════════════════════════════════════════
    // Ce que chaque canevas remonte
    // ═══════════════════════════════════════════════════════════

    public function test_lhopital_remonte_la_pf_mais_pas_la_vaccination(): void
    {
        // Le PEV est une section du centre de santé : le canevas de
        // l'hôpital ne la contient pas.
        $rapport = $this->rapport();

        $this->assertArrayHasKey('planification_familiale', $rapport);
        $this->assertArrayNotHasKey('vaccination', $rapport);
    }

    public function test_le_centre_de_sante_remonte_les_deux(): void
    {
        app(SystemeSanteService::class)->definir('cs');

        $rapport = $this->rapport();

        $this->assertArrayHasKey('planification_familiale', $rapport);
        $this->assertArrayHasKey('vaccination', $rapport);
    }

    public function test_le_bureau_de_zone_ne_remonte_ni_lune_ni_lautre(): void
    {
        app(SystemeSanteService::class)->definir('bcz');

        $rapport = $this->rapport();

        $this->assertArrayNotHasKey('planification_familiale', $rapport);
        $this->assertArrayNotHasKey('vaccination', $rapport);
    }

    public function test_les_deux_sections_quittent_les_rubriques_non_suivies(): void
    {
        // Elles y figuraient à juste titre : l'application ne savait pas les
        // remplir. Maintenant qu'elle le sait, les lignes doivent partir.
        foreach ($this->rapport()['non_suivi'] as $rubrique) {
            $this->assertStringNotContainsString('Planification familiale —', $rubrique);
        }

        app(SystemeSanteService::class)->definir('cs');

        foreach ($this->rapport()['non_suivi'] as $rubrique) {
            $this->assertStringNotContainsString('vaccination (PEV)', $rubrique);
        }
    }

    public function test_la_consultation_prescolaire_reste_declaree_non_suivie(): void
    {
        // La vaccination est produite, mais la CPS elle-même — vitamine A,
        // déparasitage, ANJE, moustiquaires — ne l'est pas. Le dire à
        // moitié serait pire que de ne rien dire.
        app(SystemeSanteService::class)->definir('cs');

        $this->assertContains(
            '8.1 Consultation préscolaire (CPS) — vitamine A, déparasitage, ANJE, MII',
            $this->rapport()['non_suivi']
        );
    }

    // ═══════════════════════════════════════════════════════════
    // Les écrans
    // ═══════════════════════════════════════════════════════════

    public function test_lecran_de_prevention_offre_le_carnet_et_la_pf(): void
    {
        $enfant = $this->enfant('ECRAN', 6);

        $this->get(route('prevention.patient', $enfant))
            ->assertOk()
            ->assertSee('Planification familiale')
            ->assertSee('Canal');
    }

    public function test_le_carnet_saffiche_au_centre_de_sante(): void
    {
        app(SystemeSanteService::class)->definir('cs');
        $enfant = $this->enfant('CARNETECRAN', 6);

        $this->get(route('prevention.patient', $enfant))
            ->assertOk()
            ->assertSee('Carnet de vaccination')
            ->assertSee('DTC-HepB-Hib 1')
            ->assertSee('Enregistrer une dose');
    }

    public function test_une_dose_se_saisit_depuis_lecran(): void
    {
        app(SystemeSanteService::class)->definir('cs');
        $enfant = $this->enfant('SAISIE', 3);

        $this->post(route('prevention.vaccination.store', $enfant), [
            'antigene' => 'bcg',
            'strategie' => 'avance',
            'lot' => 'LOT-2026-07',
        ])->assertRedirect(route('prevention.patient', $enfant));

        $dose = Vaccination::where('patient_id', $enfant->id)->firstOrFail();

        $this->assertSame('avance', $dose->strategie);
        $this->assertSame('LOT-2026-07', $dose->lot);
    }

    public function test_lecran_refuse_une_dose_en_double(): void
    {
        app(SystemeSanteService::class)->definir('cs');
        $enfant = $this->enfant('DOUBLON', 3);
        $this->service()->vacciner($enfant, ['antigene' => 'bcg']);

        $this->post(route('prevention.vaccination.store', $enfant), [
            'antigene' => 'bcg',
            'strategie' => 'fixe',
        ])->assertSessionHasErrors('antigene');

        $this->assertSame(1, Vaccination::where('patient_id', $enfant->id)->count());
    }

    public function test_un_acte_pf_se_saisit_depuis_lecran(): void
    {
        $cliente = $this->client('SAISIEPF');

        $this->post(route('prevention.pf.store', $cliente), [
            'type_acte' => 'methode',
            'methode' => 'jadelle',
            'type_acceptation' => 'nouvelle',
            'canal' => 'dbc',
        ])->assertRedirect(route('prevention.patient', $cliente));

        $this->assertSame('dbc', ActePlanificationFamiliale::where('patient_id', $cliente->id)
            ->firstOrFail()->canal);
    }

    public function test_lecran_refuse_une_methode_qui_ne_va_pas_avec_le_sexe(): void
    {
        $cliente = $this->client('REFUSPF');

        $this->post(route('prevention.pf.store', $cliente), [
            'type_acte' => 'methode',
            'methode' => 'vasectomie',
            'type_acceptation' => 'nouvelle',
            'canal' => 'ess',
        ])->assertSessionHasErrors('methode');
    }

    public function test_les_deux_registres_repondent(): void
    {
        $this->get(route('prevention.pf'))->assertOk()->assertSee('Planification familiale');
        $this->get(route('prevention.pev'))->assertOk()->assertSee('Vaccination');
    }

    public function test_le_registre_pev_previent_quand_le_canevas_ne_le_remonte_pas(): void
    {
        // L'hôpital vaccine parfois, mais ce n'est pas sa section : le dire
        // évite qu'on cherche le chiffre dans le rapport.
        $this->get(route('prevention.pev'))
            ->assertOk()
            ->assertSee('ne remonte pas la vaccination');
    }

    public function test_les_registres_sont_reserves_aux_soignants(): void
    {
        $caissier = User::factory()->create(['establishment_id' => $this->etab->id]);
        $caissier->assignRole('caissier');
        $this->actingAs($caissier);

        $this->get(route('prevention.pf'))->assertForbidden();
        $this->get(route('prevention.pev'))->assertForbidden();
        $this->get(route('prevention.patient', $this->client('INTERDIT')))->assertForbidden();
    }

    // ═══════════════════════════════════════════════════════════
    // Le rapport et son export
    // ═══════════════════════════════════════════════════════════

    public function test_les_sections_apparaissent_sur_lecran_du_rapport(): void
    {
        app(SystemeSanteService::class)->definir('cs');

        $this->service()->enregistrerActePf($this->client('RAPPORT'), [
            'methode' => 'implanon',
            'date_acte' => $this->mois->copy()->addDays(3)->toDateString(),
        ]);

        $this->get(route('snis.index', ['annee' => 2026, 'mois' => 7]))
            ->assertOk()
            ->assertSee('Planification familiale')
            ->assertSee('Implanon')
            ->assertSee('Programme élargi')
            ->assertSee('DTC-HepB-Hib 3')
            ->assertSee('Enfants complètement vaccinés');
    }

    public function test_le_tableur_remonte_les_deux_sections(): void
    {
        app(SystemeSanteService::class)->definir('cs');

        $this->service()->enregistrerActePf($this->client('CSV'), [
            'methode' => 'dmpa_im',
            'date_acte' => $this->mois->copy()->addDays(3)->toDateString(),
        ]);

        $contenu = $this->get(route('snis.csv', ['annee' => 2026, 'mois' => 7]))
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('PLANIFICATION FAMILIALE', $contenu);
        $this->assertStringContainsString('VACCINATION', $contenu);
        $this->assertStringContainsString('ESS 10 – 14 ans', $contenu);
        // La définition de l'ECV voyage avec le chiffre.
        $this->assertStringContainsString('Schéma retenu pour l\'ECV', $contenu);
    }
}
