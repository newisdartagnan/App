<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** Le socle : accueil, en-têtes de sécurité, rôles, assets compilés. */
class SocleTest extends TestCase
{
    use RefreshDatabase;

    public function test_l_accueil_repond_et_propose_la_connexion(): void
    {
        $this->get(route('accueil'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Accueil'));
    }

    public function test_chaque_page_porte_les_en_tetes_de_securite(): void
    {
        $reponse = $this->get(route('accueil'));

        $reponse->assertHeader('X-Frame-Options', 'DENY');
        $reponse->assertHeader('X-Content-Type-Options', 'nosniff');
        $reponse->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $this->assertStringContainsString("frame-ancestors 'none'", $reponse->headers->get('Content-Security-Policy'));
        $this->assertStringContainsString("default-src 'self'", $reponse->headers->get('Content-Security-Policy'));
    }

    public function test_les_en_tetes_couvrent_aussi_les_pages_hors_application(): void
    {
        foreach (['/nexiste-pas', '/up'] as $adresse) {
            $reponse = $this->get($adresse);
            $reponse->assertHeader('X-Frame-Options', 'DENY');
            $this->assertNotNull($reponse->headers->get('Content-Security-Policy'), $adresse.' sans CSP');
        }
    }

    public function test_les_roles_du_cahier_des_charges_existent_et_le_seeder_se_rejoue(): void
    {
        $this->seed(RolesSeeder::class);
        $this->seed(RolesSeeder::class);

        $this->assertEqualsCanonicalizing(config('doc-and-i.roles'), Role::pluck('name')->all());
    }

    public function test_les_identifiants_ne_sont_pas_sequentiels(): void
    {
        $patient = User::factory()->create();

        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $patient->id);
    }

    public function test_la_marque_medihome_et_ses_icones_sont_en_place(): void
    {
        $manifeste = json_decode((string) file_get_contents(public_path('manifest.webmanifest')), true);

        $this->assertSame('MediHome', $manifeste['short_name']);
        foreach ($manifeste['icons'] as $icone) {
            $this->assertFileExists(public_path(ltrim($icone['src'], '/')), 'Icône manquante : '.$icone['src']);
        }
        $this->assertContains('maskable', array_column($manifeste['icons'], 'purpose'));
        $this->assertFileExists(public_path('images/medihome-logo.webp'));
        $this->assertFileExists(public_path('images/embleme-64.png'));

        $this->get(route('accueil'))
            ->assertSee('/images/favicon-48.png', false)
            ->assertSee('<title inertia>MediHome</title>', false);
    }

    public function test_les_assets_compiles_sont_versionnes(): void
    {
        $manifeste = json_decode((string) file_get_contents(public_path('build/manifest.json')), true);

        $this->assertIsArray($manifeste, 'public/build/manifest.json manque : lancer npm run build et le committer.');
        foreach (['resources/css/app.css', 'resources/js/app.js'] as $entree) {
            $this->assertFileExists(public_path('build/'.$manifeste[$entree]['file']));
        }
    }
}
