<?php

namespace Tests\Feature;

use App\Models\Establishment;
use App\Models\User;
use App\Services\RapportSnisService;
use App\Services\SystemeSanteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Le canevas du SNIS n'est pas le même selon l'échelon.
 *
 * Un centre de santé n'hospitalise pas et n'a pas de banque du sang : lui
 * présenter ces rubriques à zéro, c'est lui faire croire qu'il a oublié de
 * les remplir. Un bureau central de zone ne soigne personne du tout.
 *
 * Ce qui est vérifié ici : que le rapport ne montre que les rubriques de son
 * canevas, qu'il numérote ses sections sans trou, qu'il nomme les sections
 * du canevas papier qu'il ne sait pas remplir, et que le choix du canevas
 * reste entre les mains de la direction.
 */
class SystemeSanteSnisTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Establishment $etab;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->admin = User::where('email', 'admin@dpi-rdc.local')->firstOrFail();
        $this->etab = Establishment::firstOrFail();
        $this->actingAs($this->admin);
    }

    protected function rapport(): array
    {
        return app(RapportSnisService::class)->rapport(2026, 7, $this->etab->id);
    }

    protected function choisir(string $systeme): void
    {
        app(SystemeSanteService::class)->definir($systeme);
    }

    // ═══════════════════════════════════════════════════════════
    // Ce que chaque canevas attend
    // ═══════════════════════════════════════════════════════════

    public function test_par_defaut_letablissement_suit_le_canevas_de_lhopital_general(): void
    {
        // C'est le cas de cette installation, et le canevas le plus complet :
        // mieux vaut une rubrique de trop qu'une rubrique cachée.
        $this->assertSame('hgr', app(SystemeSanteService::class)->systeme());

        $rapport = $this->rapport();

        // Toutes celles de son canevas — le Programme élargi de vaccination
        // n'en fait pas partie : c'est une section du centre de santé.
        foreach (SystemeSanteService::SYSTEMES['hgr']['rubriques'] as $rubrique) {
            $this->assertArrayHasKey($rubrique, $rapport);
        }

        $this->assertArrayNotHasKey('vaccination', $rapport);
    }

    public function test_un_centre_de_sante_ne_remonte_ni_hospitalisation_ni_banque_du_sang(): void
    {
        $this->choisir('cs');

        $rapport = $this->rapport();

        $this->assertArrayNotHasKey('hospitalisation', $rapport);
        $this->assertArrayNotHasKey('sang', $rapport);

        // Mais il remonte bien ce qui le concerne.
        $this->assertArrayHasKey('consultations', $rapport);
        $this->assertArrayHasKey('maternite', $rapport);
        $this->assertArrayHasKey('pharmacie', $rapport);
    }

    public function test_le_bureau_central_de_zone_ne_remonte_que_les_intrants(): void
    {
        // Le bureau ne soigne personne : il coordonne.
        $this->choisir('bcz');

        $rapport = $this->rapport();

        $this->assertArrayHasKey('pharmacie', $rapport);
        $this->assertArrayNotHasKey('consultations', $rapport);
        $this->assertArrayNotHasKey('morbidite', $rapport);
        $this->assertArrayNotHasKey('deces', $rapport);
    }

    public function test_lhopital_secondaire_garde_le_socle_de_lhopital_general(): void
    {
        $this->choisir('hst');

        $rapport = $this->rapport();

        $this->assertArrayHasKey('hospitalisation', $rapport);
        $this->assertArrayHasKey('sang', $rapport);
        // Il ne remonte pas la planification familiale, qui est une activité
        // de zone : elle ne figure donc pas dans ses sections à compléter.
        // La planification familiale est désormais produite : elle ne
        // figure plus parmi les sections à reprendre du registre.
        $this->assertNotContains(
            '3. Planification familiale — nouvelles acceptantes par méthode',
            $rapport['non_suivi']
        );
    }

    // ═══════════════════════════════════════════════════════════
    // La numérotation des sections
    // ═══════════════════════════════════════════════════════════

    public function test_les_sections_se_numerotent_sans_trou(): void
    {
        $this->choisir('cs');

        $rapport = $this->rapport();
        $numeros = app(RapportSnisService::class)->numeros($rapport);

        // Le centre de santé saute l'hospitalisation et la banque du sang :
        // ses neuf sections restantes vont de 1 à 9, sans numéro manquant.
        $this->assertSame([
            'consultations' => 1,
            'morbidite' => 2,
            'maternite' => 3,
            'planification_familiale' => 4,
            'vaccination' => 5,
            'laboratoire' => 6,
            'nutrition' => 7,
            'pharmacie' => 8,
            'deces' => 9,
        ], $numeros);
    }

    public function test_lhopital_general_numerote_ses_dix_sections(): void
    {
        $numeros = app(RapportSnisService::class)->numeros($this->rapport());

        $this->assertSame(10, $numeros['deces']);
        $this->assertSame(5, $numeros['planification_familiale']);
        $this->assertSame(7, $numeros['sang']);
        $this->assertSame(8, $numeros['nutrition']);
        // Le PEV est une section du centre de santé : l'hôpital ne l'a pas.
        $this->assertArrayNotHasKey('vaccination', $numeros);
    }

    // ═══════════════════════════════════════════════════════════
    // Ce que l'application ne sait pas remplir
    // ═══════════════════════════════════════════════════════════

    public function test_les_sections_non_suivies_sont_celles_du_canevas_retenu(): void
    {
        $this->choisir('cs');
        $cs = $this->rapport()['non_suivi'];

        $this->assertContains('10. Sites de soins communautaires', $cs);
        $this->assertContains(
            '8.1 Consultation préscolaire (CPS) — vitamine A, déparasitage, ANJE, MII',
            $cs
        );

        $this->choisir('hgr');
        $hgr = $this->rapport()['non_suivi'];

        // L'hôpital n'a ni sites communautaires ni consultation préscolaire :
        // il a la notification des cas et le bloc.
        $this->assertNotContains('10. Sites de soins communautaires', $hgr);
        $this->assertContains(
            '6. Notification des cas et urgences — maladies à déclaration obligatoire',
            $hgr
        );
    }

    public function test_le_bureau_de_zone_annonce_ses_propres_sections(): void
    {
        $this->choisir('bcz');

        $this->assertContains(
            '5. Suivi des épidémies et du SNIS',
            $this->rapport()['non_suivi']
        );
    }

    // ═══════════════════════════════════════════════════════════
    // Les écrans et l'export
    // ═══════════════════════════════════════════════════════════

    public function test_lecran_annonce_le_canevas_suivi(): void
    {
        $this->choisir('cs');

        $this->get(route('snis.index', ['annee' => 2026, 'mois' => 7]))
            ->assertOk()
            ->assertSee('Centre de santé')
            ->assertSee('Canevas CS')
            ->assertDontSee('Banque du sang');
    }

    public function test_lecran_imprimable_ne_montre_pas_les_rubriques_absentes(): void
    {
        $this->choisir('cs');

        $this->get(route('snis.imprimer', ['annee' => 2026, 'mois' => 7]))
            ->assertOk()
            ->assertSee('Canevas CS')
            ->assertSee('1. CONSULTATIONS CURATIVES')
            ->assertDontSee('HOSPITALISATION')
            ->assertDontSee('BANQUE DU SANG');
    }

    public function test_le_tableur_suit_le_canevas_et_le_nomme(): void
    {
        $this->choisir('cs');

        $contenu = $this->get(route('snis.csv', ['annee' => 2026, 'mois' => 7]))
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('Centre de santé', $contenu);
        $this->assertStringContainsString('1. CONSULTATIONS CURATIVES', $contenu);
        // Neuf sections, pas dix : les décès ferment la marche.
        $this->assertStringContainsString('9. DÉCÈS', $contenu);
        $this->assertStringNotContainsString('HOSPITALISATION', $contenu);
        $this->assertStringNotContainsString('TRANSFUSION SANGUINE', $contenu);
    }

    // ═══════════════════════════════════════════════════════════
    // Qui décide du canevas
    // ═══════════════════════════════════════════════════════════

    public function test_la_direction_change_le_canevas_depuis_lecran(): void
    {
        $this->post(route('snis.systeme'), ['systeme' => 'cs', 'annee' => 2026, 'mois' => 7])
            ->assertRedirect(route('snis.index', ['annee' => 2026, 'mois' => 7]))
            ->assertSessionHas('success');

        $this->assertSame('cs', app(SystemeSanteService::class)->systeme());
    }

    public function test_un_agent_administratif_lit_le_rapport_mais_ne_choisit_pas_le_canevas(): void
    {
        $agent = User::factory()->create(['establishment_id' => $this->etab->id]);
        $agent->assignRole('agent_admin');
        $this->actingAs($agent);

        // Il remonte le rapport, donc il le voit.
        $this->get(route('snis.index', ['annee' => 2026, 'mois' => 7]))->assertOk();

        // Mais le canevas engage l'établissement pour tous les mois à venir.
        $this->post(route('snis.systeme'), ['systeme' => 'cs'])->assertForbidden();

        $this->assertSame('hgr', app(SystemeSanteService::class)->systeme());
    }

    public function test_un_canevas_inconnu_est_refuse(): void
    {
        $this->post(route('snis.systeme'), ['systeme' => 'clinique-privee'])
            ->assertSessionHasErrors('systeme');

        $this->assertSame('hgr', app(SystemeSanteService::class)->systeme());
    }

    public function test_le_service_refuse_un_systeme_inconnu(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        app(SystemeSanteService::class)->definir('dispensaire');
    }
}
