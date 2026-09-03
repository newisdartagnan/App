<?php

namespace Tests\Feature;

use App\Http\Controllers\ApparenceController;
use App\Models\Establishment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Le thème, choisi par chacun.
 *
 * « Trop de blanc », disent ceux qui passent la journée devant l'écran — et
 * ils ont raison : un fond blanc pur sous un néon d'hôpital fatigue, et la
 * garde de nuit travaille dans une salle éteinte avec un écran qui éblouit.
 *
 * Le réglage suit l'agent et non la machine : un poste passe de main en main
 * toute la journée, et l'infirmière de nuit ne doit pas hériter de celui du
 * médecin du matin.
 */
class ApparenceThemesTest extends TestCase
{
    use RefreshDatabase;

    protected User $agent;

    protected Establishment $etab;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->etab = Establishment::firstOrFail();
        $this->agent = User::where('email', 'admin@dpi-rdc.local')->firstOrFail();
        $this->actingAs($this->agent);
    }

    // ═══════════════════════════════════════════════════════════
    // Choisir
    // ═══════════════════════════════════════════════════════════

    public function test_lecran_propose_les_quatre_themes_et_dit_a_quoi_ils_servent(): void
    {
        $page = $this->get(route('apparence.index'))->assertOk();

        // Un nom de thème ne dit rien : c'est la situation qui le justifie.
        $page->assertSee('Repos des yeux')
            ->assertSee('trop blanc')
            ->assertSee('Sombre')
            ->assertSee('garde de nuit')
            ->assertSee('Contraste élevé')
            ->assertSee('plein soleil');
    }

    public function test_le_theme_choisi_sapplique_a_toute_lapplication(): void
    {
        $this->post(route('apparence.enregistrer'), ['theme' => 'sombre'])->assertRedirect();

        $this->assertSame('sombre', $this->agent->fresh()->theme);

        // Une seule ligne dans la mise en page suffit : les six cent seize
        // classes des écrans désignent des variables, pas des couleurs.
        $this->get(route('patients.index'))
            ->assertOk()
            ->assertSee('data-theme="sombre"', false);
    }

    public function test_le_reglage_suit_lagent_et_non_le_poste(): void
    {
        $this->post(route('apparence.enregistrer'), ['theme' => 'repos']);

        $autre = User::factory()->create(['establishment_id' => $this->etab->id]);
        $autre->assignRole('infirmier');

        // Le collègue qui prend le poste garde le sien.
        $this->actingAs($autre)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('data-theme="clair"', false);
    }

    public function test_un_theme_invente_est_refuse(): void
    {
        $this->post(route('apparence.enregistrer'), ['theme' => 'arc_en_ciel'])
            ->assertSessionHasErrors('theme');

        $this->assertSame('clair', $this->agent->fresh()->theme);
    }

    public function test_un_theme_supprime_ne_casse_pas_lecran(): void
    {
        // Un réglage devenu inconnu — thème retiré d'une version à l'autre —
        // ne doit pas laisser l'application sans couleurs.
        $this->agent->forceFill(['theme' => 'theme_disparu'])->saveQuietly();

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSee('data-theme="clair"', false);
    }

    public function test_lecran_de_connexion_reste_dans_le_theme_dorigine(): void
    {
        $this->post(route('apparence.enregistrer'), ['theme' => 'sombre']);
        $this->post(route('logout'));

        // Personne n'est identifié : il n'y a pas de réglage à appliquer.
        $this->get(route('login'))
            ->assertOk()
            ->assertSee('data-theme="clair"', false);
    }

    public function test_le_choix_est_atteignable_depuis_nimporte_quel_ecran(): void
    {
        $this->get(route('patients.index'))
            ->assertOk()
            ->assertSee(route('apparence.index'), false);
    }

    // ═══════════════════════════════════════════════════════════
    // Ce que les thèmes ne doivent pas casser
    // ═══════════════════════════════════════════════════════════

    public function test_chaque_theme_redefinit_bien_les_couleurs(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));

        foreach (['repos', 'sombre', 'contraste'] as $theme) {
            $this->assertStringContainsString("[data-theme='{$theme}']", $css);
        }

        // Le levier : l'échelle des gris. Dans les écrans, les petits
        // numéros font les fonds et les grands font les textes — un thème
        // sombre inverse l'échelle au lieu de tout assombrir, sinon le
        // texte noir resterait noir sur un fond devenu noir.
        preg_match("/:root\[data-theme='sombre'\] \{(.*?)\n\}/s", $css, $trouve);
        $this->assertNotEmpty($trouve);
        foreach (['--color-white', '--color-gray-50', '--color-gray-800', '--color-gray-900'] as $variable) {
            $this->assertStringContainsString($variable, $trouve[1]);
        }
    }

    public function test_limpression_ne_suit_jamais_le_theme(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));

        // Un bulletin de sortie imprimé depuis un poste en mode sombre
        // sortirait en blanc sur fond noir : illisible, et une cartouche
        // d'encre par patient.
        preg_match_all('/@media print \{.*?\n\}/s', $css, $blocs);
        $impression = implode("\n", $blocs[0]);

        $this->assertStringContainsString("data-theme='sombre'", $impression);
        $this->assertStringContainsString('#fff', $impression);
    }

    public function test_les_champs_de_saisie_suivent_le_theme_sombre(): void
    {
        // Sans cela, ils restent blancs et éblouissent au milieu d'un écran
        // sombre — c'est précisément ce qu'on fuyait.
        $css = file_get_contents(resource_path('css/app.css'));

        $this->assertMatchesRegularExpression(
            "/:root\[data-theme='sombre'\] input,\s*\n:root\[data-theme='sombre'\] select,/",
            $css
        );
    }

    public function test_le_theme_ne_touche_pas_aux_ecrans(): void
    {
        // Tout passe par les variables : aucune vue n'a eu à changer, et
        // aucune ne doit porter de couleur en dur pour un thème donné.
        $vues = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('views')));

        // La mise en page et l'écran de connexion le portent : c'est leur
        // rôle, ils tiennent la balise <html>.
        $porteurs = ['app.blade.php', 'login.blade.php'];

        foreach ($vues as $fichier) {
            if (! str_ends_with((string) $fichier, '.blade.php')
                || in_array(basename((string) $fichier), $porteurs, true)) {
                continue;
            }

            $this->assertStringNotContainsString('data-theme=', file_get_contents((string) $fichier),
                'La vue '.basename((string) $fichier).' choisit un thème elle-même : '
                .'le réglage appartient à la mise en page.',
            );
        }
    }

    public function test_les_couleurs_dalerte_gardent_leur_force(): void
    {
        // Un thème qui rend une alerte discrète est un thème dangereux : le
        // rouge d'un dépistage positif doit rester un rouge.
        $css = file_get_contents(resource_path('css/app.css'));

        preg_match("/:root\[data-theme='sombre'\] \{(.*?)\n\}/s", $css, $sombre);

        $this->assertStringContainsString('--color-red-600', $sombre[1]);
        $this->assertStringContainsString('--color-amber-500', $sombre[1]);
    }

    public function test_la_liste_des_themes_reste_la_source_unique(): void
    {
        // L'écran, la validation et le rendu lisent la même liste : un thème
        // ajouté au CSS sans y figurer serait inatteignable.
        $this->assertSame(
            ['clair', 'repos', 'sombre', 'contraste'],
            array_keys(ApparenceController::THEMES)
        );

        foreach (ApparenceController::THEMES as $cle => $theme) {
            $this->assertNotEmpty($theme['nom']);
            $this->assertNotEmpty($theme['pourquoi'], "Le thème {$cle} ne dit pas à quoi il sert.");
            $this->assertCount(4, $theme['apercu']);
        }
    }
}
