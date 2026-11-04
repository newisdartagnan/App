<?php

namespace Tests\Feature\Personnel;

use App\Http\Middleware\PersonnelAuthentifie;
use App\Models\AuthentificationPersonnel;
use App\Models\CodeRecuperation;
use App\Models\JetonActivation;
use App\Models\User;
use App\Support\Totp;
use Database\Seeders\ReferentielMedicalSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\PersonnelDeTest;
use Tests\TestCase;

/**
 * CPT-05 / CPT-06 : le médecin active son compte par le lien reçu, choisit
 * son mot de passe, puis enrôle son application d'authentification.
 */
class ActivationPersonnelTest extends TestCase
{
    use PersonnelDeTest, RefreshDatabase;

    private const MOT_DE_PASSE = 'Tshangu-Lemba-2026!';

    private string $lien;

    private User $medecin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesSeeder::class, ReferentielMedicalSeeder::class]);

        Artisan::call('personnel:creer-medecin', [
            '--email' => 'dr.lukusa@exemple.cd', '--telephone' => '0815551234', '--nom' => 'Lukusa',
            '--prenom' => 'Albert', '--specialite' => 'medecine-generale', '--ordre' => 'CNOM-777',
        ]);
        preg_match('#(http\S+/personnel/activation/\S+)#', Artisan::output(), $m);
        $this->lien = $m[1];
        $this->medecin = User::where('email', 'dr.lukusa@exemple.cd')->sole();
    }

    private function choisirMotDePasse(string $motDePasse = self::MOT_DE_PASSE, ?string $confirmation = null): TestResponse
    {
        return $this->post(route('personnel.activation.mot-de-passe.enregistrer'), [
            'password' => $motDePasse,
            'password_confirmation' => $confirmation ?? $motDePasse,
        ]);
    }

    private function secretEnAttente(): string
    {
        return AuthentificationPersonnel::where('user_id', $this->medecin->id)->sole()->totp_secret;
    }

    /** Ouvre le lien, choisit le mot de passe, confirme le code : renvoie les codes de récupération affichés. */
    private function activerCompletement(): array
    {
        $this->get($this->lien);
        $this->choisirMotDePasse();
        $this->post(route('personnel.activation.double-facteur.confirmer'), ['code' => Totp::code($this->secretEnAttente(), Totp::pas())])
            ->assertRedirect(route('personnel.codes-recuperation'));

        $codes = [];
        $this->get(route('personnel.codes-recuperation'))
            ->assertOk()
            ->assertInertia(function ($page) use (&$codes) {
                $page->component('Personnel/Activation/CodesRecuperation');
                $codes = $page->toArray()['props']['codes'];
            });

        return $codes;
    }

    public function test_le_lien_mene_au_choix_du_mot_de_passe_sans_garder_le_jeton_dans_l_adresse(): void
    {
        $this->get($this->lien)->assertRedirect(route('personnel.activation.mot-de-passe'));

        $this->get(route('personnel.activation.mot-de-passe'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Personnel/Activation/MotDePasse')
                ->where('email', 'dr.lukusa@exemple.cd'));
        $this->assertGuest();
    }

    public function test_un_lien_dont_la_signature_est_alteree_est_refuse(): void
    {
        $this->get(preg_replace('/signature=[0-9a-f]{4}/', 'signature=0000', $this->lien))->assertForbidden();
        $this->get(route('personnel.activation.mot-de-passe'))->assertInertia(fn ($page) => $page->component('Personnel/Activation/LienInvalide'));
    }

    public function test_un_lien_expire_apres_72_heures_est_refuse(): void
    {
        $this->travel(72)->hours();
        $this->travel(1)->minutes();

        $this->get($this->lien)->assertForbidden();
    }

    public function test_un_jeton_inconnu_meme_signe_est_refuse(): void
    {
        $this->get(URL::temporarySignedRoute('personnel.activation', now()->addHour(), ['jeton' => Str::random(64)]))
            ->assertStatus(410);
    }

    public function test_un_jeton_dont_l_echeance_en_base_est_passee_est_refuse(): void
    {
        JetonActivation::query()->update(['expire_le' => now()->subMinute()]);

        $this->get($this->lien)->assertStatus(410);
    }

    public function test_sans_passer_par_le_lien_on_ne_choisit_pas_de_mot_de_passe(): void
    {
        $this->choisirMotDePasse()->assertRedirect(route('personnel.activation.mot-de-passe'));

        $this->assertNull($this->medecin->fresh()->password);
        $this->get(route('personnel.activation.double-facteur'))->assertRedirect(route('personnel.activation.mot-de-passe'));
    }

    public function test_le_mot_de_passe_fait_au_moins_12_caracteres(): void
    {
        $this->get($this->lien);

        $this->choisirMotDePasse('Court-2026!')->assertSessionHasErrors('password');
        $this->assertNull($this->medecin->fresh()->password);
    }

    public function test_le_mot_de_passe_doit_etre_confirme(): void
    {
        $this->get($this->lien);

        $this->choisirMotDePasse(self::MOT_DE_PASSE, 'Autre-Chose-2026!')->assertSessionHasErrors('password');
        $this->assertNull($this->medecin->fresh()->password);
    }

    public function test_un_mot_de_passe_trop_courant_est_refuse(): void
    {
        $this->get($this->lien);

        foreach (['motdepasse123', 'AZERTYUIOP123', '123456789012', 'dr.lukusa@exemple.cd'] as $courant) {
            $this->choisirMotDePasse($courant)->assertSessionHasErrors('password');
        }
        $this->assertNull($this->medecin->fresh()->password);
    }

    public function test_le_mot_de_passe_est_garde_hache(): void
    {
        $this->get($this->lien);
        $this->choisirMotDePasse()->assertRedirect(route('personnel.activation.double-facteur'));

        $brut = DB::table('users')->where('id', $this->medecin->id)->value('password');
        $this->assertNotSame(self::MOT_DE_PASSE, $brut);
        $this->assertTrue(Hash::check(self::MOT_DE_PASSE, $brut));
        // Le mot de passe seul ne connecte pas.
        $this->assertGuest();
    }

    public function test_l_enrolement_montre_un_qr_code_svg_integre_et_le_secret_en_clair(): void
    {
        $this->get($this->lien);
        $this->choisirMotDePasse();

        $reponse = $this->get(route('personnel.activation.double-facteur'))->assertOk();
        $props = [];
        $reponse->assertInertia(function ($page) use (&$props) {
            $page->component('Personnel/Activation/DoubleFacteur');
            $props = $page->toArray()['props'];
        });

        $this->assertStringStartsWith('data:image/svg+xml;base64,', $props['qrCode']);
        $svg = base64_decode(substr($props['qrCode'], strlen('data:image/svg+xml;base64,')));
        $this->assertStringContainsString('<svg', $svg);
        $this->assertStringNotContainsString('<script', $svg);
        $this->assertStringContainsString("img-src 'self' data:", $reponse->headers->get('Content-Security-Policy'), 'Une image data: passe la CSP stricte.');
        $this->assertSame($this->secretEnAttente(), str_replace(' ', '', $props['secret']));
    }

    public function test_le_secret_totp_est_chiffre_en_base(): void
    {
        $this->get($this->lien);
        $this->choisirMotDePasse();

        $secret = $this->secretEnAttente();
        $brut = DB::table('authentifications_personnel')->where('user_id', $this->medecin->id)->value('totp_secret');

        $this->assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', $secret);
        $this->assertNotSame($secret, $brut);
        $this->assertStringNotContainsString($secret, $brut);
        $this->assertSame($secret, Crypt::decryptString($brut));
    }

    public function test_un_code_faux_ne_termine_pas_l_activation(): void
    {
        $this->get($this->lien);
        $this->choisirMotDePasse();
        $bon = Totp::code($this->secretEnAttente(), Totp::pas());

        $this->post(route('personnel.activation.double-facteur.confirmer'), ['code' => $bon === '000000' ? '111111' : '000000'])
            ->assertSessionHasErrors('code');

        $this->assertNull(AuthentificationPersonnel::where('user_id', $this->medecin->id)->value('active_le'));
        $this->assertNull(JetonActivation::sole()->utilise_le);
        $this->assertGuest();
    }

    public function test_un_code_valide_termine_l_activation_et_ouvre_la_session(): void
    {
        $this->get($this->lien);
        $this->choisirMotDePasse();
        $sessionAvant = session()->getId();

        $this->post(route('personnel.activation.double-facteur.confirmer'), ['code' => Totp::code($this->secretEnAttente(), Totp::pas())])
            ->assertRedirect(route('personnel.codes-recuperation'));

        $this->assertAuthenticatedAs($this->medecin);
        $this->assertNotSame($sessionAvant, session()->getId(), 'Session régénérée.');
        $this->assertSame($this->medecin->id, session(PersonnelAuthentifie::CLE_AUTHENTIFIE));
        $this->assertNotNull(AuthentificationPersonnel::where('user_id', $this->medecin->id)->value('active_le'));
        $this->assertNotNull(JetonActivation::sole()->utilise_le);
    }

    public function test_huit_codes_de_recuperation_montres_une_seule_fois_et_gardes_haches(): void
    {
        $codes = $this->activerCompletement();

        $this->assertCount(8, $codes);
        $this->assertCount(8, array_unique($codes));
        foreach ($codes as $code) {
            $this->assertMatchesRegularExpression('/^[A-Z0-9]{5}-[A-Z0-9]{5}$/', $code);
        }

        $enBase = json_encode(CodeRecuperation::query()->toBase()->get());
        foreach ($codes as $code) {
            $this->assertStringNotContainsString($code, $enBase);
            $this->assertStringNotContainsString(str_replace('-', '', $code), $enBase);
        }
        $this->assertSame(8, CodeRecuperation::where('user_id', $this->medecin->id)->count());

        $this->get(route('personnel.codes-recuperation'))->assertRedirect(route('personnel.accueil'));
    }

    public function test_le_lien_ne_sert_qu_une_fois(): void
    {
        $this->activerCompletement();
        $this->post(route('personnel.deconnexion'));

        $this->get($this->lien)->assertStatus(410);
        $this->get(route('personnel.activation.mot-de-passe'))->assertInertia(fn ($page) => $page->component('Personnel/Activation/LienInvalide'));
        $this->choisirMotDePasse('Un-Autre-Mot-2026!')->assertRedirect(route('personnel.activation.mot-de-passe'));
        $this->assertTrue(Hash::check(self::MOT_DE_PASSE, $this->medecin->fresh()->password));
    }

    public function test_un_enrolement_abandonne_peut_reprendre_par_le_meme_lien(): void
    {
        $this->get($this->lien);
        $this->choisirMotDePasse();
        $premierSecret = $this->secretEnAttente();
        $this->flushSession();

        $this->get($this->lien)->assertRedirect(route('personnel.activation.mot-de-passe'));
        $this->choisirMotDePasse('Nouveau-Choix-2026!');

        $this->assertNotSame($premierSecret, $this->secretEnAttente(), 'Un nouveau secret : l\'ancien a pu être vu.');
        $this->post(route('personnel.activation.double-facteur.confirmer'), ['code' => Totp::code($this->secretEnAttente(), Totp::pas())])
            ->assertRedirect(route('personnel.codes-recuperation'));
    }

    public function test_un_compte_dont_l_activation_n_est_pas_terminee_n_atteint_que_l_activation(): void
    {
        $this->get($this->lien);
        $this->choisirMotDePasse();

        // Même avec une session forgée qui prétend le second facteur validé.
        $this->commePersonnel($this->medecin)->get(route('personnel.medecin.accueil'))
            ->assertRedirect(route('personnel.activation.mot-de-passe'));
        $this->assertGuest();

        $this->post(route('personnel.connexion.verifier'), ['email' => 'dr.lukusa@exemple.cd', 'password' => self::MOT_DE_PASSE])
            ->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_les_essais_de_code_a_l_enrolement_sont_limites(): void
    {
        $this->get($this->lien);
        $this->choisirMotDePasse();
        $bon = Totp::code($this->secretEnAttente(), Totp::pas());
        $faux = $bon === '000000' ? '111111' : '000000';

        for ($i = 0; $i < 10; $i++) {
            $this->post(route('personnel.activation.double-facteur.confirmer'), ['code' => $faux]);
        }

        $this->post(route('personnel.activation.double-facteur.confirmer'), ['code' => $bon])
            ->assertSessionHasErrors('code');
        $this->assertGuest();
    }
}
