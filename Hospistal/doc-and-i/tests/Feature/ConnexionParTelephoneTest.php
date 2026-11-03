<?php

namespace Tests\Feature;

use App\Models\CodeConnexion;
use App\Models\User;
use App\Sms\EnvoyeurSms;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Support\EnvoyeurSmsFactice;
use Tests\TestCase;

/**
 * CPT-01 : le patient s'inscrit et se connecte avec son numéro de
 * téléphone et un code reçu par SMS.
 */
class ConnexionParTelephoneTest extends TestCase
{
    use RefreshDatabase;

    private EnvoyeurSmsFactice $sms;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesSeeder::class);
        $this->sms = new EnvoyeurSmsFactice;
        $this->app->instance(EnvoyeurSms::class, $this->sms);
        RateLimiter::clear('code-connexion:numero:+243812345678');
    }

    private function demanderCode(string $telephone = '0812345678'): \Illuminate\Testing\TestResponse
    {
        return $this->post(route('connexion.demander'), ['telephone' => $telephone]);
    }

    public function test_l_ecran_de_connexion_demande_le_numero(): void
    {
        $this->get(route('connexion'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Connexion/Telephone'));
    }

    public function test_un_code_part_par_sms_au_numero_normalise(): void
    {
        $this->demanderCode('081 234 56 78')->assertRedirect(route('connexion.code'));

        $this->assertCount(1, $this->sms->envoyes);
        $this->assertSame('+243812345678', $this->sms->envoyes[0]['telephone']);
        $this->assertMatchesRegularExpression('/\b\d{6}\b/', $this->sms->envoyes[0]['message']);
    }

    public function test_le_sms_ne_contient_que_le_code_et_aucune_donnee_personnelle(): void
    {
        $this->demanderCode();

        $message = $this->sms->envoyes[0]['message'];
        $this->assertStringStartsWith('Doc&I : votre code de connexion est', $message);
        $this->assertStringNotContainsString('243812345678', $message);
    }

    public function test_le_code_n_est_jamais_stocke_en_clair(): void
    {
        $this->demanderCode();
        $code = $this->sms->dernierCode();

        $ligne = CodeConnexion::sole();
        $this->assertNotSame($code, $ligne->empreinte);
        $this->assertStringNotContainsString($code, json_encode(CodeConnexion::query()->toBase()->get()));
    }

    public function test_un_numero_invalide_est_refuse_sans_sms(): void
    {
        $this->demanderCode('0212345678')->assertSessionHasErrors('telephone');

        $this->assertSame([], $this->sms->envoyes);
    }

    public function test_le_bon_code_inscrit_un_nouveau_patient(): void
    {
        $this->demanderCode();

        $this->post(route('connexion.verifier'), ['code' => $this->sms->dernierCode()])
            ->assertRedirect(route('espace'));

        $patient = User::sole();
        $this->assertSame('+243812345678', $patient->telephone);
        $this->assertNotNull($patient->telephone_verifie_le);
        $this->assertTrue($patient->hasRole('patient'));
        $this->assertAuthenticatedAs($patient);
    }

    public function test_un_patient_existant_se_reconnecte_sans_doublon(): void
    {
        $existant = User::factory()->create(['telephone' => '+243812345678']);

        $this->demanderCode('+243 812 345 678');
        $this->post(route('connexion.verifier'), ['code' => $this->sms->dernierCode()]);

        $this->assertSame(1, User::count());
        $this->assertAuthenticatedAs($existant);
    }

    public function test_un_mauvais_code_ne_connecte_pas(): void
    {
        $this->demanderCode();

        $this->post(route('connexion.verifier'), ['code' => $this->autreCode()])
            ->assertSessionHasErrors('code');

        $this->assertGuest();
        $this->assertSame(0, User::count());
    }

    public function test_cinq_essais_faux_invalident_le_code_meme_le_bon_ensuite(): void
    {
        $this->demanderCode();
        $bon = $this->sms->dernierCode();

        for ($i = 0; $i < 5; $i++) {
            $this->post(route('connexion.verifier'), ['code' => $this->autreCode()]);
        }

        $this->post(route('connexion.verifier'), ['code' => $bon])->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    public function test_un_code_expire_est_refuse(): void
    {
        $this->demanderCode();
        $code = $this->sms->dernierCode();

        $this->travel(11)->minutes();

        $this->post(route('connexion.verifier'), ['code' => $code])->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    public function test_un_code_ne_sert_qu_une_fois(): void
    {
        $this->demanderCode();
        $code = $this->sms->dernierCode();

        $this->post(route('connexion.verifier'), ['code' => $code]);
        $this->post(route('deconnexion'));

        // Renvoi du même formulaire (retour arrière) : la session ne retient
        // plus le numéro, et le code est déjà consommé.
        $this->withSession(['connexion.telephone' => '+243812345678'])
            ->post(route('connexion.verifier'), ['code' => $code])
            ->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    public function test_une_nouvelle_demande_invalide_le_code_precedent(): void
    {
        $this->demanderCode();
        $ancien = $this->sms->dernierCode();
        $this->demanderCode();

        if ($ancien === $this->sms->dernierCode()) {
            $this->markTestSkipped('Deux codes tirés au sort identiques (une chance sur un million).');
        }

        $this->post(route('connexion.verifier'), ['code' => $ancien])->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    public function test_le_renvoi_envoie_un_nouveau_code_au_meme_numero(): void
    {
        $this->demanderCode();

        $this->post(route('connexion.renvoyer'))->assertRedirect(route('connexion.code'));

        $this->assertCount(2, $this->sms->envoyes);
        $this->assertSame('+243812345678', $this->sms->envoyes[1]['telephone']);
        $this->post(route('connexion.verifier'), ['code' => $this->sms->dernierCode()])
            ->assertRedirect(route('espace'));
    }

    public function test_le_renvoi_compte_dans_la_limite_des_demandes(): void
    {
        $this->demanderCode();
        $this->post(route('connexion.renvoyer'));
        $this->post(route('connexion.renvoyer'));

        $this->post(route('connexion.renvoyer'))->assertSessionHasErrors('telephone');
        $this->assertCount(3, $this->sms->envoyes);
    }

    public function test_les_demandes_de_code_sont_limitees_par_numero(): void
    {
        $this->demanderCode();
        $this->demanderCode();
        $this->demanderCode();

        $this->demanderCode()->assertSessionHasErrors('telephone');
        $this->assertCount(3, $this->sms->envoyes);
    }

    public function test_on_ne_verifie_pas_de_code_sans_avoir_demande_un_numero(): void
    {
        $this->get(route('connexion.code'))->assertRedirect(route('connexion'));
        $this->post(route('connexion.verifier'), ['code' => '123456'])->assertRedirect(route('connexion'));
    }

    public function test_un_patient_connecte_ne_revoit_pas_l_ecran_de_connexion(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(route('connexion'))->assertRedirect(route('espace'));
    }

    public function test_la_deconnexion_ferme_la_session(): void
    {
        $this->actingAs(User::factory()->create());

        $this->post(route('deconnexion'))->assertRedirect(route('accueil'));
        $this->assertGuest();
    }

    private function autreCode(): string
    {
        return $this->sms->dernierCode() === '000000' ? '111111' : '000000';
    }
}
