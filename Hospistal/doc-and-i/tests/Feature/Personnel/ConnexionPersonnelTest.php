<?php

namespace Tests\Feature\Personnel;

use App\Http\Middleware\PersonnelAuthentifie;
use App\Models\AuthentificationPersonnel;
use App\Models\CodeRecuperation;
use App\Models\Medecin;
use App\Models\User;
use App\Services\Personnel\ConnexionPersonnelService;
use App\Services\Personnel\DoubleFacteurService;
use App\Sms\EnvoyeurSms;
use App\Support\Totp;
use Database\Seeders\ReferentielMedicalSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Tests\Support\EnvoyeurSmsFactice;
use Tests\Support\PersonnelDeTest;
use Tests\TestCase;

/**
 * CPT-06 : le personnel se connecte par courriel et mot de passe, PUIS par
 * le code de son application d'authentification (ou un code de récupération).
 */
class ConnexionPersonnelTest extends TestCase
{
    use PersonnelDeTest, RefreshDatabase;

    private const MOT_DE_PASSE = 'mot-de-passe-de-test';

    private Medecin $medecin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesSeeder::class, ReferentielMedicalSeeder::class]);
        // Au milieu d'un pas de 30 s : les tests ne changent pas de pas en route.
        $this->travelTo(now()->setTime(10, 0, 10));
        $this->medecin = $this->medecinActive();
    }

    private function identifiants(?string $email = null, string $motDePasse = self::MOT_DE_PASSE): TestResponse
    {
        return $this->post(route('personnel.connexion.verifier'), ['email' => $email ?? $this->medecin->user->email, 'password' => $motDePasse]);
    }

    private function code(string $code): TestResponse
    {
        return $this->post(route('personnel.connexion.code.verifier'), ['code' => $code]);
    }

    private function codeFaux(): string
    {
        return $this->codeTotpCourant() === '000000' ? '111111' : '000000';
    }

    private function auth(): AuthentificationPersonnel
    {
        return AuthentificationPersonnel::pour($this->medecin->user);
    }

    private function seDeconnecter(): void
    {
        $this->post(route('personnel.deconnexion'));
        $this->assertGuest();
    }

    // --- Parcours -----------------------------------------------------------

    public function test_l_ecran_de_connexion_du_personnel_demande_courriel_et_mot_de_passe(): void
    {
        $this->get(route('personnel.connexion'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Personnel/Connexion/Identifiants'));
    }

    public function test_le_bon_mot_de_passe_seul_ne_connecte_pas(): void
    {
        $this->identifiants()->assertRedirect(route('personnel.connexion.code'));

        $this->assertGuest();
        $this->get(route('personnel.connexion.code'))->assertInertia(fn ($page) => $page->component('Personnel/Connexion/Code'));
        $this->get(route('personnel.medecin.accueil'))->assertRedirect(route('personnel.connexion'));
    }

    public function test_mot_de_passe_puis_code_totp_connectent_et_regenerent_la_session(): void
    {
        $this->identifiants();
        $avant = session()->getId();

        $this->code($this->codeTotpCourant())->assertRedirect(route('personnel.accueil'));

        $this->assertAuthenticatedAs($this->medecin->user);
        $this->assertNotSame($avant, session()->getId());
        $this->assertSame($this->medecin->user->id, session(PersonnelAuthentifie::CLE_AUTHENTIFIE));
        $this->get(route('personnel.accueil'))->assertRedirect(route('personnel.medecin.accueil'));
        $this->get(route('personnel.medecin.accueil'))->assertOk();
    }

    public function test_le_courriel_est_reconnu_quelle_que_soit_la_casse(): void
    {
        $this->identifiants('  '.mb_strtoupper($this->medecin->user->email).' ')->assertRedirect(route('personnel.connexion.code'));
    }

    public function test_le_code_est_demande_dans_les_5_minutes(): void
    {
        $this->identifiants();
        $this->travel(6)->minutes();

        $this->code($this->codeTotpCourant())->assertRedirect(route('personnel.connexion'));
        $this->assertGuest();
        $this->get(route('personnel.connexion.code'))->assertRedirect(route('personnel.connexion'));
    }

    public function test_sans_mot_de_passe_verifie_la_saisie_du_code_ne_mene_a_rien(): void
    {
        $this->code($this->codeTotpCourant())->assertRedirect(route('personnel.connexion'));
        $this->assertGuest();
    }

    public function test_tout_role_du_personnel_se_connecte_de_la_meme_facon(): void
    {
        // Un rôle sans écrans propres pour l'instant : l'accueil générique du personnel.
        $support = $this->personnelActive('support');

        $this->identifiants($support->email);
        $this->code($this->codeTotpCourant())->assertRedirect(route('personnel.accueil'));

        $this->assertAuthenticatedAs($support);
        $this->get(route('personnel.accueil'))->assertOk()->assertInertia(fn ($page) => $page->component('Personnel/Accueil'));
        $this->get(route('personnel.medecin.accueil'))->assertForbidden();
        $this->get(route('personnel.caisse.accueil'))->assertForbidden();
    }

    public function test_un_agent_de_caisse_connecte_arrive_dans_l_espace_caisse(): void
    {
        $caissier = $this->personnelActive('caisse');

        $this->identifiants($caissier->email);
        $this->code($this->codeTotpCourant())->assertRedirect(route('personnel.accueil'));

        $this->get(route('personnel.accueil'))->assertRedirect(route('personnel.caisse.accueil'));
        $this->get(route('personnel.caisse.accueil'))->assertOk()->assertInertia(fn ($page) => $page->component('Personnel/Caisse/Accueil'));
        $this->get(route('personnel.medecin.accueil'))->assertForbidden();
    }

    public function test_seule_une_page_du_personnel_demandee_avant_la_connexion_est_rouverte(): void
    {
        $this->get(route('personnel.medecin.agenda'))->assertRedirect(route('personnel.connexion'));
        $this->identifiants();
        $this->code($this->codeTotpCourant())->assertRedirect(route('personnel.medecin.agenda'));

        $this->seDeconnecter();
        $this->withSession(['url.intended' => route('espace')]);
        $this->identifiants();
        $this->travel(30)->seconds();
        $this->code($this->codeTotpCourant())->assertRedirect(route('personnel.accueil'));
    }

    // --- Pas d'énumération, pas de SMS ------------------------------------------

    public function test_meme_message_que_le_courriel_existe_ou_non(): void
    {
        $inconnu = $this->identifiants('inconnu@exemple.cd')->assertSessionHasErrors('email');
        $messageInconnu = session('errors')->first('email');

        $this->identifiants(null, 'mauvais-mot-de-passe')->assertSessionHasErrors('email');

        $this->assertSame(ConnexionPersonnelService::ERREUR_IDENTIFIANTS, $messageInconnu);
        $this->assertSame($messageInconnu, session('errors')->first('email'));
        $this->assertGuest();
    }

    public function test_un_patient_ne_passe_pas_par_la_connexion_du_personnel(): void
    {
        $patient = User::factory()->create(['email' => 'patient@exemple.cd', 'password' => self::MOT_DE_PASSE]);

        $this->identifiants($patient->email)->assertSessionHasErrors(['email' => ConnexionPersonnelService::ERREUR_IDENTIFIANTS]);
        $this->assertGuest();
    }

    public function test_un_code_sms_ne_connecte_jamais_le_personnel(): void
    {
        $sms = new EnvoyeurSmsFactice;
        $this->app->instance(EnvoyeurSms::class, $sms);

        $this->post(route('connexion.demander'), ['telephone' => $this->medecin->user->telephone]);
        $this->post(route('connexion.verifier'), ['code' => $sms->dernierCode()])->assertSessionHasErrors('code');

        $this->assertGuest();
        $this->get(route('personnel.medecin.accueil'))->assertRedirect(route('personnel.connexion'));
    }

    // --- Second facteur ----------------------------------------------------------

    public function test_un_code_totp_faux_ne_connecte_pas(): void
    {
        $this->identifiants();

        $this->code($this->codeFaux())->assertSessionHasErrors(['code' => ConnexionPersonnelService::ERREUR_CODE]);
        $this->assertGuest();
    }

    public function test_un_code_totp_ne_sert_pas_deux_fois(): void
    {
        $code = $this->codeTotpCourant();
        $this->identifiants();
        $this->code($code)->assertRedirect(route('personnel.accueil'));
        $this->assertSame(Totp::pas(), $this->auth()->totp_dernier_pas);
        $this->seDeconnecter();

        // Même pas de 30 s : un code vu par-dessus l'épaule ne rouvre pas la session.
        $this->identifiants();
        $this->code($code)->assertSessionHasErrors('code');
        $this->assertGuest();

        // Le pas suivant donne un nouveau code, accepté.
        $this->travel(30)->seconds();
        $this->code($this->codeTotpCourant())->assertRedirect(route('personnel.accueil'));
    }

    public function test_un_code_plus_ancien_que_le_dernier_accepte_est_refuse(): void
    {
        $ancien = Totp::code($this->secretTotp, Totp::pas() - 1);
        $this->identifiants();
        $this->code($this->codeTotpCourant())->assertRedirect();
        $this->seDeconnecter();

        $this->identifiants();
        $this->code($ancien)->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    public function test_un_code_de_recuperation_remplace_une_seule_fois_le_code_totp(): void
    {
        $codes = app(DoubleFacteurService::class)->genererCodesRecuperation($this->medecin->user);

        $this->identifiants();
        $this->code(strtolower($codes[0]))->assertRedirect(route('personnel.accueil'));
        $this->assertAuthenticatedAs($this->medecin->user);
        $this->assertSame(1, CodeRecuperation::whereNotNull('utilise_le')->count());
        $this->seDeconnecter();

        $this->identifiants();
        $this->code($codes[0])->assertSessionHasErrors('code');
        $this->assertGuest();

        $this->code($codes[1])->assertRedirect(route('personnel.accueil'));
        $this->assertSame(6, app(DoubleFacteurService::class)->codesRestants($this->medecin->user));
    }

    public function test_le_code_de_recuperation_d_un_autre_compte_ne_sert_pas(): void
    {
        $autre = $this->medecinActive();
        $codes = app(DoubleFacteurService::class)->genererCodesRecuperation($autre->user);

        $this->identifiants();
        $this->code($codes[0])->assertSessionHasErrors('code');
        $this->assertGuest();
        $this->assertSame(0, CodeRecuperation::whereNotNull('utilise_le')->count());
    }

    // --- Limites et verrouillage ---------------------------------------------------

    public function test_le_compte_est_verrouille_apres_cinq_mots_de_passe_faux(): void
    {
        Log::spy();

        for ($i = 0; $i < 5; $i++) {
            $this->identifiants(null, 'mauvais-'.$i);
        }

        $this->identifiants()->assertSessionHasErrors(['email' => 'Trop de tentatives. Réessayez dans 15 minute(s).']);
        $this->assertGuest();
        $this->assertTrue($this->auth()->estVerrouille());
        Log::shouldHaveReceived('warning')->withArgs(fn ($message, $contexte) => $contexte === ['user_id' => $this->medecin->user->id]);

        $this->travel(16)->minutes();
        $this->identifiants()->assertRedirect(route('personnel.connexion.code'));
    }

    public function test_les_codes_faux_verrouillent_aussi_le_compte(): void
    {
        $this->identifiants();

        for ($i = 0; $i < 4; $i++) {
            $this->code($this->codeFaux())->assertSessionHasErrors(['code' => ConnexionPersonnelService::ERREUR_CODE]);
        }
        $this->code($this->codeFaux())->assertSessionHasErrors(['code' => 'Trop de tentatives. Réessayez dans 15 minute(s).']);

        // Même le bon code est refusé tant que dure le verrouillage.
        $this->code($this->codeTotpCourant())->assertSessionHasErrors('code');
        $this->assertGuest();
        $this->assertTrue($this->auth()->estVerrouille());

        // Le verrouillage porte sur le compte : le bon mot de passe aussi est refusé.
        $this->identifiants()->assertSessionHasErrors(['email' => 'Trop de tentatives. Réessayez dans 15 minute(s).']);
    }

    public function test_une_connexion_reussie_remet_les_echecs_a_zero(): void
    {
        for ($i = 0; $i < 4; $i++) {
            $this->identifiants(null, 'mauvais-'.$i);
        }
        $this->identifiants();
        $this->code($this->codeTotpCourant())->assertRedirect(route('personnel.accueil'));
        $this->seDeconnecter();

        $this->identifiants(null, 'encore-faux')->assertSessionHasErrors(['email' => ConnexionPersonnelService::ERREUR_IDENTIFIANTS]);
        $this->assertSame(1, $this->auth()->echecs_consecutifs);
        $this->identifiants()->assertRedirect(route('personnel.connexion.code'));
    }

    public function test_un_courriel_inconnu_est_limite_comme_un_compte_existant(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->identifiants('inconnu@exemple.cd', 'mauvais-'.$i);
        }
        $this->identifiants('inconnu@exemple.cd')->assertSessionHasErrors('email');
        $messageInconnu = session('errors')->first('email');

        for ($i = 0; $i < 5; $i++) {
            $this->identifiants(null, 'mauvais-'.$i);
        }
        $this->identifiants()->assertSessionHasErrors('email');

        $this->assertSame('Trop de tentatives. Réessayez dans 15 minute(s).', $messageInconnu);
        $this->assertSame($messageInconnu, session('errors')->first('email'), 'Un compte verrouillé ne se distingue pas d\'un courriel inconnu.');
    }

    public function test_les_tentatives_sont_limitees_par_adresse_ip(): void
    {
        config(['personnel.connexion.tentatives_par_ip_par_minute' => 3]);

        for ($i = 0; $i < 3; $i++) {
            $this->identifiants("inconnu{$i}@exemple.cd")->assertStatus(302);
        }
        $this->identifiants('autre@exemple.cd')->assertStatus(429);
    }

    public function test_les_saisies_de_code_sont_limitees_par_adresse_ip(): void
    {
        config(['personnel.connexion.tentatives_par_ip_par_minute' => 3]);
        $this->identifiants();

        for ($i = 0; $i < 3; $i++) {
            $this->code('12345')->assertStatus(302);
        }
        $this->code($this->codeTotpCourant())->assertStatus(429);
        $this->assertGuest();
    }

    public function test_les_limites_du_personnel_ont_leurs_propres_compteurs(): void
    {
        config(['personnel.connexion.tentatives_par_ip_par_minute' => 3]);

        for ($i = 0; $i < 3; $i++) {
            $this->identifiants("inconnu{$i}@exemple.cd");
        }

        // La connexion des patients n'en souffre pas.
        $this->post(route('connexion.demander'), ['telephone' => '0899999999'])->assertStatus(302);
    }

    // --- Session ------------------------------------------------------------------

    public function test_une_session_sans_second_facteur_n_ouvre_pas_l_espace_du_personnel(): void
    {
        $this->actingAs($this->medecin->user)->get(route('personnel.accueil'))->assertRedirect(route('personnel.connexion'));
        $this->assertGuest();
    }

    public function test_une_session_active_mais_sans_second_facteur_n_est_pas_admise(): void
    {
        $this->actingAs($this->medecin->user)
            ->withSession([PersonnelAuthentifie::CLE_ACTIVITE => now()->getTimestamp()])
            ->get(route('personnel.accueil'))
            ->assertRedirect(route('personnel.connexion'));
        $this->assertGuest();
    }

    public function test_une_session_validee_pour_un_autre_compte_n_est_pas_admise(): void
    {
        $autre = $this->medecinActive();

        $this->actingAs($this->medecin->user)
            ->withSession([PersonnelAuthentifie::CLE_AUTHENTIFIE => $autre->user->id, PersonnelAuthentifie::CLE_ACTIVITE => now()->getTimestamp()])
            ->get(route('personnel.accueil'))
            ->assertRedirect(route('personnel.connexion'));
        $this->assertGuest();
    }

    public function test_la_session_du_personnel_se_ferme_apres_30_minutes_d_inactivite(): void
    {
        $this->commePersonnel($this->medecin->user)->get(route('personnel.medecin.accueil'))->assertOk();
        $this->travel(29)->minutes();
        $this->get(route('personnel.medecin.accueil'))->assertOk();

        $this->travel(31)->minutes();
        $this->get(route('personnel.medecin.accueil'))->assertRedirect(route('personnel.connexion'));
        $this->assertGuest();
    }

    public function test_la_deconnexion_ferme_la_session_du_personnel(): void
    {
        $this->commePersonnel($this->medecin->user)->post(route('personnel.deconnexion'))->assertRedirect(route('personnel.connexion'));

        $this->assertGuest();
        $this->get(route('personnel.medecin.accueil'))->assertRedirect(route('personnel.connexion'));
    }

    public function test_un_membre_du_personnel_connecte_est_renvoye_vers_son_espace_et_non_celui_du_patient(): void
    {
        $this->commePersonnel($this->medecin->user)->get(route('connexion'))->assertRedirect(route('personnel.accueil'));
        $this->get(route('personnel.connexion'))->assertRedirect(route('personnel.accueil'));
    }

    public function test_un_patient_connecte_reste_renvoye_vers_l_espace_patient(): void
    {
        $this->actingAs(User::factory()->create())->get(route('connexion'))->assertRedirect(route('espace'));
    }

    public function test_un_patient_connecte_n_entre_pas_dans_l_espace_du_personnel(): void
    {
        $this->actingAs(User::factory()->create())->get(route('personnel.accueil'))->assertForbidden();
    }
}
