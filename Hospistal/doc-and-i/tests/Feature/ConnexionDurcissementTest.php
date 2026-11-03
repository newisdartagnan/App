<?php

namespace Tests\Feature;

use App\Models\CodeConnexion;
use App\Models\User;
use App\Services\CodeConnexionService;
use App\Sms\EnvoyeurSms;
use Database\Seeders\RolesSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\Support\EnvoyeurSmsFactice;
use Tests\TestCase;

/**
 * Constats de la revue de sécurité du premier lot, chacun verrouillé par un
 * test : limites de SMS, essais cumulés, compteurs séparés, pilote SMS.
 */
class ConnexionDurcissementTest extends TestCase
{
    use RefreshDatabase;

    private EnvoyeurSmsFactice $sms;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesSeeder::class);
        $this->sms = new EnvoyeurSmsFactice;
        $this->app->instance(EnvoyeurSms::class, $this->sms);
    }

    public function test_la_base_refuse_deux_codes_actifs_pour_un_meme_numero(): void
    {
        $actif = ['telephone' => '+243812345678', 'empreinte' => str_repeat('a', 64), 'expire_le' => now()->addMinutes(10)];
        CodeConnexion::create($actif);

        $this->expectException(QueryException::class);
        CodeConnexion::create($actif);
    }

    public function test_redemander_un_code_ne_redonne_pas_d_essais_au_dela_du_plafond_quotidien(): void
    {
        $faux = fn () => $this->post(route('connexion.verifier'), ['code' => $this->sms->dernierCode() === '000000' ? '111111' : '000000']);

        $this->post(route('connexion.demander'), ['telephone' => '0812345678']);
        for ($i = 0; $i < 5; $i++) {
            $faux();
        }
        $this->post(route('connexion.demander'), ['telephone' => '0812345678']);
        for ($i = 0; $i < 5; $i++) {
            $faux();
        }

        // Dix codes faux sur deux codes : le numéro est bloqué pour la journée.
        $this->post(route('connexion.demander'), ['telephone' => '0812345678'])
            ->assertSessionHasErrors('telephone');
        $this->assertCount(2, $this->sms->envoyes);

        // Même un code émis avant le blocage ne connecte plus.
        $this->assertTrue(app(CodeConnexionService::class)->estBloque('+243812345678'));
        $this->assertSame(0, User::count());
    }

    public function test_le_patient_bloque_est_oriente_vers_le_support(): void
    {
        $this->post(route('connexion.demander'), ['telephone' => '0812345678']);
        for ($i = 0; $i < 10; $i++) {
            $this->post(route('connexion.verifier'), ['code' => 'abcdef']);
        }

        $this->post(route('connexion.verifier'), ['code' => '123456'])
            ->assertSessionHasErrors(['code' => 'Trop de codes incorrects pour ce numéro. Réessayez demain ou contactez le support.']);
    }

    public function test_les_demandes_sont_aussi_plafonnees_par_jour_et_par_numero(): void
    {
        config(['doc-and-i.code_connexion.demandes_par_numero' => 100, 'doc-and-i.code_connexion.demandes_par_numero_par_jour' => 4]);

        for ($i = 0; $i < 5; $i++) {
            $this->post(route('connexion.demander'), ['telephone' => '0812345678']);
        }

        $this->assertCount(4, $this->sms->envoyes);
    }

    public function test_la_limite_par_ip_s_applique_a_des_numeros_differents(): void
    {
        config(['doc-and-i.code_connexion.demandes_par_ip' => 2]);

        $this->post(route('connexion.demander'), ['telephone' => '0811111111']);
        $this->post(route('connexion.demander'), ['telephone' => '0822222222']);
        $this->post(route('connexion.demander'), ['telephone' => '0833333333'])->assertSessionHasErrors('telephone');

        $this->assertCount(2, $this->sms->envoyes);
    }

    public function test_le_budget_global_de_sms_protege_le_credit_d_envoi(): void
    {
        config(['doc-and-i.code_connexion.sms_par_heure' => 2]);

        $this->post(route('connexion.demander'), ['telephone' => '0811111111']);
        $this->post(route('connexion.demander'), ['telephone' => '0822222222']);
        $this->post(route('connexion.demander'), ['telephone' => '0833333333'])->assertSessionHasErrors('telephone');

        $this->assertCount(2, $this->sms->envoyes);
    }

    public function test_une_adresse_ipv6_est_comptee_par_bloc_de_64(): void
    {
        $this->assertSame(
            CodeConnexionService::cleIp('2c0f:f2a0:1234:5678::1'),
            CodeConnexionService::cleIp('2c0f:f2a0:1234:5678:ffff:eeee:dddd:cccc')
        );
        $this->assertNotSame(
            CodeConnexionService::cleIp('2c0f:f2a0:1234:5678::1'),
            CodeConnexionService::cleIp('2c0f:f2a0:1234:5679::1')
        );
        $this->assertSame('41.243.10.20', CodeConnexionService::cleIp('41.243.10.20'));
    }

    public function test_les_verifications_n_epuisent_pas_le_compteur_des_demandes(): void
    {
        $this->post(route('connexion.demander'), ['telephone' => '0812345678']);

        // Avant correction, les trois routes partageaient un compteur par IP :
        // 30 vérifications bloquaient ensuite toute demande de code.
        for ($i = 0; $i < 30; $i++) {
            $this->post(route('connexion.verifier'), ['code' => 'x']);
        }

        $this->post(route('connexion.demander'), ['telephone' => '0899999999'])->assertStatus(302);
    }

    public function test_si_le_role_ne_peut_etre_pose_ni_compte_ni_code_ne_sont_consommes(): void
    {
        $this->post(route('connexion.demander'), ['telephone' => '0812345678']);
        \Spatie\Permission\Models\Role::query()->delete();
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $this->post(route('connexion.verifier'), ['code' => $this->sms->dernierCode()])->assertStatus(500);

        $this->assertSame(0, User::count());
        $this->assertNull(CodeConnexion::sole()->utilise_le);

        // Les rôles revenus, le même code connecte et le compte a bien son rôle.
        $this->seed(RolesSeeder::class);
        $this->post(route('connexion.verifier'), ['code' => $this->sms->dernierCode()])->assertRedirect(route('espace'));
        $this->assertTrue(User::sole()->hasRole('patient'));
    }

    public function test_le_pilote_sms_journal_est_refuse_en_production(): void
    {
        $this->app->forgetInstance(EnvoyeurSms::class);
        $this->app->detectEnvironment(fn () => 'production');
        config(['doc-and-i.sms.driver' => 'log']);

        $this->expectException(RuntimeException::class);
        $this->app->make(EnvoyeurSms::class);
    }

    public function test_un_pilote_sms_non_defini_est_signale(): void
    {
        $this->app->forgetInstance(EnvoyeurSms::class);
        config(['doc-and-i.sms.driver' => null]);

        $this->expectException(\InvalidArgumentException::class);
        $this->app->make(EnvoyeurSms::class);
    }

    public function test_le_journal_de_developpement_masque_le_numero(): void
    {
        $this->app->forgetInstance(EnvoyeurSms::class);
        Log::spy();

        $this->app->make(EnvoyeurSms::class)->envoyer('+243812345678', 'test');

        Log::shouldHaveReceived('info')->withArgs(fn ($m) => ! str_contains($m, '812345678') && str_contains($m, '+243 81'));
    }
}
