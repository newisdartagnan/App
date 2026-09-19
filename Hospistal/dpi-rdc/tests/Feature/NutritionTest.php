<?php

namespace Tests\Feature;

use App\Models\Establishment;
use App\Models\MesureAnthropometrique;
use App\Models\Patient;
use App\Models\User;
use App\Services\NutritionService;
use App\Services\RapportSnisService;
use App\Services\SystemeSanteService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * L'anthropométrie et la prise en charge nutritionnelle.
 *
 * C'était la dernière grande rubrique des canevas que l'application ne
 * savait pas remplir. Elle se tenait sur des fiches cartonnées et se
 * recomptait à la main le 30 du mois.
 *
 * Ce qui est vérifié ici : que la mesure classe correctement — et surtout
 * qu'elle ne classe pas ce qu'elle ne peut pas savoir —, que l'admission
 * refuse les mélanges d'unités, que la décharge alimente le canevas, et que
 * le rapport ventile comme le formulaire l'exige.
 */
class NutritionTest extends TestCase
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

    protected function enfant(string $nom, int $ageMois = 18, string $sexe = 'F'): Patient
    {
        return Patient::create([
            'establishment_id' => $this->etab->id,
            'dossier_number' => 'NUT-'.$nom.'-'.random_int(1000, 9999),
            'nom' => $nom, 'prenom' => 'Test', 'sexe' => $sexe,
            'date_naissance' => $this->mois->copy()->subMonths($ageMois)->toDateString(),
            'type_prise_en_charge' => 'prive',
        ]);
    }

    protected function mesure(Patient $patient, array $donnees = []): MesureAnthropometrique
    {
        return app(NutritionService::class)->mesurer($patient, array_merge([
            'mesure_a' => $this->mois->copy()->addDays(3),
            'oedemes' => 'aucun',
        ], $donnees));
    }

    protected function service(): NutritionService
    {
        return app(NutritionService::class);
    }

    // ═══════════════════════════════════════════════════════════
    // Ce que la mesure établit
    // ═══════════════════════════════════════════════════════════

    public function test_un_bras_sous_115_mm_signe_la_malnutrition_severe(): void
    {
        $mesure = $this->mesure($this->enfant('BRAS'), ['perimetre_brachial_mm' => 112]);

        $this->assertSame('severe', $mesure->etatNutritionnel());
        $this->assertSame('pt_pb', $mesure->critereDadmission());
    }

    public function test_un_bras_entre_115_et_125_signe_la_malnutrition_moderee(): void
    {
        $mesure = $this->mesure($this->enfant('MODERE'), ['perimetre_brachial_mm' => 120]);

        $this->assertSame('modere', $mesure->etatNutritionnel());
        // Le modéré n'est pas une entrée thérapeutique : il va en
        // supplémentation, pas à l'UNTA.
        $this->assertNull($mesure->critereDadmission());
    }

    public function test_les_oedemes_priment_sur_la_balance(): void
    {
        // Un enfant œdémateux est sévèrement malnutri même si le bras et le
        // poids semblent corrects : l'eau pèse.
        $mesure = $this->mesure($this->enfant('OEDEME'), [
            'perimetre_brachial_mm' => 140,
            'oedemes' => 'deux_plus',
        ]);

        $this->assertSame('severe', $mesure->etatNutritionnel());
        $this->assertSame('oedemes', $mesure->critereDadmission());
    }

    public function test_le_score_pt_saisi_classe_comme_le_bras(): void
    {
        $severe = $this->mesure($this->enfant('ZSEVERE'), ['z_score_pt' => -3.4]);
        $modere = $this->mesure($this->enfant('ZMODERE'), ['z_score_pt' => -2.5]);
        $normal = $this->mesure($this->enfant('ZNORMAL'), ['z_score_pt' => -0.4]);

        $this->assertSame('severe', $severe->etatNutritionnel());
        $this->assertSame('modere', $modere->etatNutritionnel());
        $this->assertSame('normal', $normal->etatNutritionnel());
    }

    public function test_le_plus_grave_des_criteres_lemporte(): void
    {
        // Bras acceptable, score effondré : c'est le score qui compte.
        $mesure = $this->mesure($this->enfant('MIXTE'), [
            'perimetre_brachial_mm' => 130,
            'z_score_pt' => -3.6,
        ]);

        $this->assertSame('severe', $mesure->etatNutritionnel());
    }

    public function test_une_mesure_sans_critere_ne_rassure_pas_a_tort(): void
    {
        // Un poids et une taille seuls ne disent rien sans l'abaque :
        // l'application se tait plutôt que de déclarer l'enfant bien portant.
        $mesure = $this->mesure($this->enfant('MUET'), [
            'poids_kg' => 9.4,
            'taille_cm' => 78,
        ]);

        $this->assertSame('inconnu', $mesure->etatNutritionnel());
        $this->assertSame('Non évaluable', $mesure->libelleEtat());
    }

    public function test_limc_se_calcule_a_lenregistrement(): void
    {
        $mesure = $this->mesure($this->enfant('IMC'), ['poids_kg' => 9, 'taille_cm' => 75]);

        // 9 / 0,75² = 16,0
        $this->assertSame('16.00', (string) $mesure->imc);
    }

    // ═══════════════════════════════════════════════════════════
    // L'orientation proposée
    // ═══════════════════════════════════════════════════════════

    public function test_lorientation_propose_lunta_et_laisse_la_complication_au_soignant(): void
    {
        $mesure = $this->mesure($this->enfant('ORIENTE'), ['perimetre_brachial_mm' => 110]);

        $orientation = $this->service()->orientation($mesure);

        $this->assertSame('unta', $orientation['unite']);
        $this->assertSame('pt_pb', $orientation['critere']);
        // La complication ne se lit sur aucun ruban : l'écran le dit.
        $this->assertStringContainsString('UNTI', $orientation['message']);
    }

    public function test_un_etat_modere_est_oriente_vers_la_supplementation(): void
    {
        $mesure = $this->mesure($this->enfant('SUPPL'), ['perimetre_brachial_mm' => 118]);

        $this->assertSame('uns', $this->service()->orientation($mesure)['unite']);
    }

    public function test_un_etat_normal_nappelle_aucune_admission(): void
    {
        $mesure = $this->mesure($this->enfant('SAIN'), ['perimetre_brachial_mm' => 145]);

        $this->assertNull($this->service()->orientation($mesure)['unite']);
    }

    // ═══════════════════════════════════════════════════════════
    // L'admission
    // ═══════════════════════════════════════════════════════════

    public function test_un_enfant_sadmet_a_lunta(): void
    {
        $enfant = $this->enfant('ADMIS');

        $suivi = $this->service()->admettre($enfant, 'unta', 'pt_pb', [
            'date_admission' => $this->mois->copy()->addDays(4)->toDateString(),
        ]);

        $this->assertSame('unta', $suivi->unite);
        $this->assertTrue($suivi->estEnCours());
        $this->assertFalse($suivi->avec_complication);
    }

    public function test_ladmission_a_lunti_porte_toujours_la_complication(): void
    {
        // Le canevas ne connaît à l'UNTI aucune ligne sans complication :
        // c'est ce qui la distingue de l'ambulatoire.
        $suivi = $this->service()->admettre($this->enfant('INTENSIF'), 'unti', 'oedemes');

        $this->assertTrue($suivi->avec_complication);
    }

    public function test_un_motif_de_supplementation_est_refuse_a_lunta(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service()->admettre($this->enfant('MELANGE'), 'unta', 'mam_depistee');
    }

    public function test_un_motif_therapeutique_est_refuse_a_luns(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service()->admettre($this->enfant('MELANGE2'), 'uns', 'oedemes');
    }

    public function test_un_patient_nest_pas_suivi_deux_fois_en_meme_temps(): void
    {
        // Sinon le rapport compterait deux entrées et deux issues pour le
        // même enfant.
        $enfant = $this->enfant('DOUBLE');
        $this->service()->admettre($enfant, 'unta', 'pt_pb');

        $this->expectException(\RuntimeException::class);
        $this->service()->admettre($enfant, 'unta', 'oedemes');
    }

    public function test_une_unite_inconnue_est_refusee(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service()->admettre($this->enfant('INCONNU'), 'urgence', 'pt_pb');
    }

    // ═══════════════════════════════════════════════════════════
    // La décharge
    // ═══════════════════════════════════════════════════════════

    public function test_un_suivi_se_decharge(): void
    {
        $suivi = $this->service()->admettre($this->enfant('SORTI'), 'unta', 'pt_pb', [
            'date_admission' => $this->mois->copy()->addDays(2)->toDateString(),
        ]);

        $clos = $this->service()->decharger($suivi, 'gueri', $this->mois->copy()->addDays(20)->toDateString());

        $this->assertFalse($clos->estEnCours());
        $this->assertSame('Guéri', $clos->libelleIssue());
    }

    public function test_une_issue_dune_autre_unite_est_refusee(): void
    {
        // L'UNTA réfère vers le haut, l'UNTI contre-réfère vers le bas :
        // les confondre ferait un rapport faux dans les deux sens.
        $suivi = $this->service()->admettre($this->enfant('ISSUE'), 'unta', 'pt_pb');

        $this->expectException(\InvalidArgumentException::class);
        $this->service()->decharger($suivi, 'contre_refere_unta');
    }

    public function test_un_suivi_clos_ne_se_referme_pas(): void
    {
        $suivi = $this->service()->admettre($this->enfant('DEJACLOS'), 'unta', 'pt_pb');
        $this->service()->decharger($suivi, 'gueri');

        $this->expectException(\RuntimeException::class);
        $this->service()->decharger($suivi->fresh(), 'abandon');
    }

    // ═══════════════════════════════════════════════════════════
    // Le canevas
    // ═══════════════════════════════════════════════════════════

    protected function rapport(): array
    {
        return app(RapportSnisService::class)
            ->rapport($this->mois->year, $this->mois->month, $this->etab->id);
    }

    public function test_lhopital_remonte_lunti_et_pas_lunta(): void
    {
        $nutrition = $this->rapport()['nutrition'];

        $this->assertArrayHasKey('unti', $nutrition['unites']);
        $this->assertArrayNotHasKey('unta', $nutrition['unites']);
        $this->assertArrayNotHasKey('uns', $nutrition['unites']);
    }

    public function test_le_centre_de_sante_remonte_lunta_et_luns(): void
    {
        app(SystemeSanteService::class)->definir('cs');

        $nutrition = $this->rapport()['nutrition'];

        $this->assertArrayHasKey('unta', $nutrition['unites']);
        $this->assertArrayHasKey('uns', $nutrition['unites']);
        $this->assertArrayNotHasKey('unti', $nutrition['unites']);
    }

    public function test_les_entrees_se_ventilent_par_sexe_et_par_tranche(): void
    {
        app(SystemeSanteService::class)->definir('cs');

        // 6-23 mois : une fille, un garçon. 24-59 mois : une fille.
        foreach ([['F1', 18, 'F'], ['G1', 20, 'M'], ['F2', 40, 'F']] as [$nom, $age, $sexe]) {
            $this->service()->admettre($this->enfant($nom, $age, $sexe), 'unta', 'pt_pb', [
                'date_admission' => $this->mois->copy()->addDays(5)->toDateString(),
            ]);
        }

        $ligne = $this->rapport()['nutrition']['unites']['unta']['entrees']['lignes']['pt_pb']['ventilation'];

        $this->assertSame(1, $ligne['tranches']['six_23_mois']['f']);
        $this->assertSame(1, $ligne['tranches']['six_23_mois']['m']);
        $this->assertSame(1, $ligne['tranches']['vingt_quatre_59_mois']['f']);
        $this->assertSame(0, $ligne['tranches']['cinq_ans_plus']['f']);
        $this->assertSame(3, $ligne['total']);
    }

    public function test_un_nourrisson_de_moins_de_six_mois_est_compte_a_part(): void
    {
        // Le canevas nutritionnel commence à six mois : avant, c'est
        // l'allaitement. On ne le range pas de force dans une case.
        app(SystemeSanteService::class)->definir('cs');

        $this->service()->admettre($this->enfant('NOURRISSON', 3), 'unta', 'oedemes', [
            'date_admission' => $this->mois->copy()->addDays(5)->toDateString(),
        ]);

        $ligne = $this->rapport()['nutrition']['unites']['unta']['entrees']['lignes']['oedemes']['ventilation'];

        $this->assertSame(1, $ligne['hors_tranche']);
        $this->assertSame(0, $ligne['tranches']['six_23_mois']['f']);
    }

    public function test_les_issues_du_mois_et_le_taux_de_guerison(): void
    {
        app(SystemeSanteService::class)->definir('cs');

        foreach ([['G1', 'gueri'], ['G2', 'gueri'], ['A1', 'abandon'], ['D1', 'deces']] as [$nom, $issue]) {
            $suivi = $this->service()->admettre($this->enfant($nom), 'unta', 'pt_pb', [
                'date_admission' => $this->mois->copy()->addDays(2)->toDateString(),
            ]);

            $this->service()->decharger($suivi, $issue, $this->mois->copy()->addDays(20)->toDateString());
        }

        $issues = $this->rapport()['nutrition']['unites']['unta']['issues'];

        $this->assertSame(4, $issues['total']);
        $this->assertSame(2, $issues['lignes']['gueri']['ventilation']['total']);
        $this->assertSame(1, $issues['lignes']['deces']['ventilation']['total']);
        $this->assertSame(50.0, $issues['taux_guerison']);
    }

    public function test_le_report_du_mois_precedent_est_compte(): void
    {
        // Le canevas demande les admissions déjà ouvertes au premier du
        // mois : elles ne se retrouvent nulle part ailleurs.
        app(SystemeSanteService::class)->definir('cs');

        $this->service()->admettre($this->enfant('ANCIEN'), 'unta', 'pt_pb', [
            'date_admission' => $this->mois->copy()->subDays(10)->toDateString(),
        ]);

        $report = $this->rapport()['nutrition']['unites']['unta']['report'];

        $this->assertSame(1, $report['total']);
    }

    public function test_un_suivi_clos_avant_le_mois_nest_plus_reporte(): void
    {
        app(SystemeSanteService::class)->definir('cs');

        $suivi = $this->service()->admettre($this->enfant('PARTI'), 'unta', 'pt_pb', [
            'date_admission' => $this->mois->copy()->subDays(40)->toDateString(),
        ]);
        $this->service()->decharger($suivi, 'gueri', $this->mois->copy()->subDays(5)->toDateString());

        $this->assertSame(0, $this->rapport()['nutrition']['unites']['unta']['report']['total']);
    }

    public function test_le_depistage_du_mois_est_compte(): void
    {
        $this->mesure($this->enfant('D1'), ['perimetre_brachial_mm' => 110]);
        $this->mesure($this->enfant('D2'), ['perimetre_brachial_mm' => 118]);
        $this->mesure($this->enfant('D3'), ['perimetre_brachial_mm' => 145]);

        $mesures = $this->rapport()['nutrition']['mesures'];

        $this->assertSame(3, $mesures['total']);
        $this->assertSame(1, $mesures['severes']);
        $this->assertSame(1, $mesures['moderes']);
    }

    public function test_les_groupes_specifiques_de_luns_se_comptent(): void
    {
        app(SystemeSanteService::class)->definir('cs');

        $this->service()->admettre($this->enfant('ENCEINTE', 300), 'uns', 'mam_depistee', [
            'date_admission' => $this->mois->copy()->addDays(3)->toDateString(),
            'groupe_specifique' => 'femme_enceinte',
        ]);

        $groupes = $this->rapport()['nutrition']['groupes_specifiques'];

        $this->assertSame(1, $groupes['femme_enceinte']['nouveaux']);
        $this->assertSame(0, $groupes['pvvih']['nouveaux']);
    }

    // ═══════════════════════════════════════════════════════════
    // Les écrans
    // ═══════════════════════════════════════════════════════════

    public function test_le_registre_repond_et_liste_les_suivis(): void
    {
        $enfant = $this->enfant('REGISTRE');
        $this->service()->admettre($enfant, 'unti', 'oedemes');

        $this->get(route('nutrition.index'))
            ->assertOk()
            ->assertSee('Registre nutritionnel')
            ->assertSee('UNTI')
            ->assertSee($enfant->nom);
    }

    public function test_lecran_du_patient_offre_la_mesure_et_ladmission(): void
    {
        $enfant = $this->enfant('ECRAN');

        $this->get(route('nutrition.patient', $enfant))
            ->assertOk()
            ->assertSee('Périmètre brachial')
            ->assertSee('Œdèmes bilatéraux')
            ->assertSee('Admettre en nutrition')
            // L'écran doit dire d'où vient le score, sinon on croira qu'il
            // est calculé.
            ->assertSee('Lu sur l\'abaque', false);
    }

    public function test_une_mesure_se_saisit_depuis_lecran(): void
    {
        $enfant = $this->enfant('SAISIE');

        $this->post(route('nutrition.mesure', $enfant), [
            'poids_kg' => '7.4',
            'taille_cm' => '76',
            'perimetre_brachial_mm' => '111',
            'oedemes' => 'aucun',
        ])->assertRedirect(route('nutrition.patient', $enfant));

        $mesure = MesureAnthropometrique::where('patient_id', $enfant->id)->firstOrFail();

        $this->assertSame(111, $mesure->perimetre_brachial_mm);
        $this->assertSame('severe', $mesure->etatNutritionnel());
    }

    public function test_une_mesure_entierement_vide_est_refusee(): void
    {
        $enfant = $this->enfant('VIDE');

        $this->post(route('nutrition.mesure', $enfant), ['oedemes' => 'aucun'])
            ->assertSessionHasErrors('poids_kg');

        $this->assertSame(0, MesureAnthropometrique::where('patient_id', $enfant->id)->count());
    }

    public function test_un_perimetre_brachial_en_centimetres_est_refuse(): void
    {
        // Onze virgule deux au lieu de cent douze : l'erreur classique du
        // ruban, et elle ferait passer un enfant sévère pour normal.
        $enfant = $this->enfant('UNITE');

        $this->post(route('nutrition.mesure', $enfant), [
            'perimetre_brachial_mm' => '11.2',
            'oedemes' => 'aucun',
        ])->assertSessionHasErrors('perimetre_brachial_mm');
    }

    public function test_ladmission_se_fait_depuis_lecran(): void
    {
        $enfant = $this->enfant('ADMISSION');

        $this->post(route('nutrition.admission', $enfant), [
            'unite' => 'unti',
            'critere_admission' => 'pt_pb',
        ])->assertRedirect(route('nutrition.patient', $enfant));

        $this->assertNotNull($this->service()->suiviEnCours($enfant));
    }

    public function test_lecran_refuse_un_melange_dunite_et_de_motif(): void
    {
        $enfant = $this->enfant('REFUS');

        $this->post(route('nutrition.admission', $enfant), [
            'unite' => 'unti',
            'critere_admission' => 'mam_depistee',
        ])->assertSessionHasErrors('unite');
    }

    public function test_la_decharge_se_fait_depuis_lecran(): void
    {
        $enfant = $this->enfant('DECHARGE');
        $suivi = $this->service()->admettre($enfant, 'unti', 'oedemes', [
            'date_admission' => now()->subDays(10)->toDateString(),
        ]);

        $this->post(route('nutrition.decharger', $suivi), ['issue' => 'contre_refere_unta'])
            ->assertRedirect(route('nutrition.patient', $enfant));

        $this->assertFalse($suivi->fresh()->estEnCours());
    }

    public function test_une_sortie_anterieure_a_ladmission_est_refusee(): void
    {
        $enfant = $this->enfant('DATES');
        $suivi = $this->service()->admettre($enfant, 'unti', 'oedemes', [
            'date_admission' => now()->subDays(3)->toDateString(),
        ]);

        $this->post(route('nutrition.decharger', $suivi), [
            'issue' => 'gueri',
            'date_sortie' => now()->subDays(10)->toDateString(),
        ])->assertSessionHasErrors('date_sortie');
    }

    // ═══════════════════════════════════════════════════════════
    // Qui y a droit
    // ═══════════════════════════════════════════════════════════

    public function test_le_registre_est_reserve_aux_soignants(): void
    {
        $caissier = User::factory()->create(['establishment_id' => $this->etab->id]);
        $caissier->assignRole('caissier');
        $this->actingAs($caissier);

        $this->get(route('nutrition.index'))->assertForbidden();
        $this->get(route('nutrition.patient', $this->enfant('INTERDIT')))->assertForbidden();
    }

    public function test_un_infirmier_mesure_et_admet(): void
    {
        // C'est lui qui pèse et qui tient l'UNTA : lui refuser l'écriture
        // reviendrait à garder les fiches cartonnées.
        $infirmier = User::factory()->create(['establishment_id' => $this->etab->id]);
        $infirmier->assignRole('infirmier');
        $enfant = $this->enfant('INFIRMIER');
        $this->actingAs($infirmier);

        $this->get(route('nutrition.patient', $enfant))->assertOk();

        $this->post(route('nutrition.mesure', $enfant), [
            'perimetre_brachial_mm' => '108',
            'oedemes' => 'aucun',
        ])->assertRedirect();

        $this->assertSame(1, MesureAnthropometrique::where('patient_id', $enfant->id)->count());
    }

    // ═══════════════════════════════════════════════════════════
    // Le rapport et son export
    // ═══════════════════════════════════════════════════════════

    public function test_la_nutrition_apparait_sur_lecran_du_rapport(): void
    {
        $this->service()->admettre($this->enfant('RAPPORT'), 'unti', 'oedemes', [
            'date_admission' => $this->mois->copy()->addDays(3)->toDateString(),
        ]);

        $this->get(route('snis.index', ['annee' => 2026, 'mois' => 7]))
            ->assertOk()
            ->assertSee('Prise en charge nutritionnelle')
            ->assertSee('UNTI')
            ->assertSee('Admissions début du mois');
    }

    public function test_le_tableur_remonte_la_nutrition(): void
    {
        $this->service()->admettre($this->enfant('CSV'), 'unti', 'pt_pb', [
            'date_admission' => $this->mois->copy()->addDays(3)->toDateString(),
        ]);

        $contenu = $this->get(route('snis.csv', ['annee' => 2026, 'mois' => 7]))
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('PRISE EN CHARGE NUTRITIONNELLE', $contenu);
        $this->assertStringContainsString('UNTI', $contenu);
        $this->assertStringContainsString('F 6-23 m', $contenu);
    }

    public function test_la_nutrition_nest_plus_declaree_non_suivie(): void
    {
        // Elle l'était jusqu'ici, à juste titre : l'application ne savait
        // pas la remplir. Maintenant qu'elle le sait, la ligne doit partir.
        $rapport = $this->rapport();

        foreach ($rapport['non_suivi'] as $rubrique) {
            $this->assertStringNotContainsString('UNTI', $rubrique);
        }

        app(SystemeSanteService::class)->definir('cs');

        foreach ($this->rapport()['non_suivi'] as $rubrique) {
            $this->assertStringNotContainsString('UNTA', $rubrique);
        }
    }
}
