<?php

namespace Tests\Feature\Personnel;

use App\Models\AuthentificationPersonnel;
use App\Models\Medecin;
use App\Models\User;
use App\Services\Personnel\ConnexionPersonnelService;
use App\Services\Personnel\DisponibilitesMedecinService;
use App\Support\Totp;
use Carbon\CarbonImmutable;
use Database\Seeders\ReferentielMedicalSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use Tests\Support\PersonnelDeTest;
use Tests\TestCase;

/**
 * Constats de la revue de sécurité du lot intégré (comptes du personnel,
 * espace médecin), chacun verrouillé par un test qui échouait avant correction.
 */
class DurcissementPersonnelTest extends TestCase
{
    use PersonnelDeTest, RefreshDatabase;

    private const MOT_DE_PASSE = 'mot-de-passe-de-test';

    private Medecin $medecin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesSeeder::class, ReferentielMedicalSeeder::class]);
        $this->travelTo(CarbonImmutable::parse('2026-11-09 10:00:10', 'Africa/Kinshasa'));
        $this->medecin = $this->medecinActive();
    }

    private function identifiants(): TestResponse
    {
        return $this->post(route('personnel.connexion.verifier'), ['email' => $this->medecin->user->email, 'password' => self::MOT_DE_PASSE]);
    }

    /**
     * Une autre requête (un autre code faux, en parallèle) verrouille le
     * compte juste après la première lecture de sa ligne de sécurité.
     *
     * Le verrou de ligne sérialise les écrivains : si la décision est prise
     * sous SELECT … FOR UPDATE, l'écriture concurrente a eu lieu avant (on la
     * voit) ou aura lieu après (elle n'a pas pu passer entre la lecture et la
     * décision). Si la lecture est faite sans verrou, l'écriture se glisse
     * entre la lecture et la décision : c'est exactement ce que simule
     * l'injection après une lecture non verrouillée.
     */
    private function verrouillageConcurrentApresLaProchaineLecture(): void
    {
        $fait = false;
        $verrouiller = function () use (&$fait) {
            $fait = true;
            DB::table('authentifications_personnel')->update(['verrouille_jusqu_au' => now()->addMinutes(15)]);
        };

        DB::beforeExecuting(function (string $sql) use (&$fait, $verrouiller) {
            if (! $fait && str_contains($sql, 'authentifications_personnel') && str_contains(strtolower($sql), 'for update')) {
                $verrouiller();
            }
        });
        DB::listen(function (QueryExecuted $requete) use (&$fait, $verrouiller) {
            if (! $fait && str_contains($requete->sql, 'authentifications_personnel') && str_starts_with(strtolower(ltrim($requete->sql)), 'select')) {
                $verrouiller();
            }
        });
    }

    // --- 1. Verrouillage : décision prise sous le verrou de la ligne ------------

    public function test_un_verrouillage_pose_par_une_requete_concurrente_bloque_meme_le_bon_code(): void
    {
        $this->identifiants()->assertRedirect(route('personnel.connexion.code'));
        $this->verrouillageConcurrentApresLaProchaineLecture();

        $this->post(route('personnel.connexion.code.verifier'), ['code' => $this->codeTotpCourant()])
            ->assertSessionHasErrors('code');

        $this->assertGuest();
    }

    public function test_un_verrouillage_pose_par_une_requete_concurrente_bloque_meme_le_bon_mot_de_passe(): void
    {
        $this->verrouillageConcurrentApresLaProchaineLecture();

        $this->identifiants()->assertSessionHasErrors(['email' => 'Trop de tentatives. Réessayez dans 15 minute(s).']);
        $this->get(route('personnel.connexion.code'))->assertRedirect(route('personnel.connexion'));
    }

    public function test_un_compte_verrouille_refuse_le_bon_code(): void
    {
        $this->identifiants();
        DB::table('authentifications_personnel')->update(['verrouille_jusqu_au' => now()->addMinutes(15)]);

        $this->post(route('personnel.connexion.code.verifier'), ['code' => $this->codeTotpCourant()])
            ->assertSessionHasErrors(['code' => 'Trop de tentatives. Réessayez dans 15 minute(s).']);
        $this->assertGuest();
    }

    public function test_la_tentative_est_comptee_par_courriel_avant_la_verification_du_mot_de_passe(): void
    {
        // Compter APRÈS la vérification laissait passer, en parallèle, bien
        // plus d'essais que la limite : chaque requête lisait le compteur
        // avant que les autres ne l'aient incrémenté.
        $cle = ConnexionPersonnelService::cleCourriel($this->medecin->user->email);
        $vus = [];
        Hash::partialMock()->shouldReceive('check')->andReturnUsing(function ($valeur, $empreinte) use (&$vus, $cle) {
            $vus[] = RateLimiter::attempts($cle);

            return password_verify($valeur, $empreinte);
        });

        $this->post(route('personnel.connexion.verifier'), ['email' => $this->medecin->user->email, 'password' => 'mauvais']);

        $this->assertSame([1], $vus);
    }

    public function test_un_mot_de_passe_juste_ne_consomme_pas_d_essai_sur_le_courriel(): void
    {
        $this->identifiants()->assertRedirect(route('personnel.connexion.code'));

        $this->assertSame(0, (int) RateLimiter::attempts(ConnexionPersonnelService::cleCourriel($this->medecin->user->email)));
    }

    // --- 2. Historique du navigateur chiffré, effacé à la déconnexion ----------

    private function page(TestResponse $reponse): array
    {
        return $reponse->assertOk()->viewData('page');
    }

    public function test_l_historique_du_navigateur_est_chiffre_pour_le_personnel_et_le_patient(): void
    {
        $this->assertTrue($this->page($this->get(route('personnel.connexion')))['encryptHistory']);
        $this->assertTrue($this->page($this->commePersonnel($this->medecin->user)->get(route('personnel.medecin.agenda')))['encryptHistory']);
        $this->assertTrue($this->page($this->actingAs(User::factory()->create())->get(route('profil.modifier')))['encryptHistory']);
    }

    public function test_la_deconnexion_du_personnel_efface_l_historique(): void
    {
        $this->commePersonnel($this->medecin->user)->post(route('personnel.deconnexion'));

        $this->assertTrue($this->page($this->get(route('personnel.connexion')))['clearHistory']);
        $this->assertFalse($this->page($this->get(route('personnel.connexion')))['clearHistory'], 'Une seule fois.');
    }

    public function test_la_deconnexion_du_patient_efface_l_historique(): void
    {
        $this->actingAs(User::factory()->create())->post(route('deconnexion'))->assertRedirect(route('accueil'));

        $this->assertTrue($this->page($this->get(route('accueil')))['clearHistory']);
    }

    public function test_la_fermeture_d_une_session_inactive_efface_l_historique(): void
    {
        $this->commePersonnel($this->medecin->user)->get(route('personnel.medecin.accueil'))->assertOk();
        $this->travel(31)->minutes();
        $this->get(route('personnel.medecin.accueil'))->assertRedirect(route('personnel.connexion'));

        $this->assertTrue($this->page($this->get(route('personnel.connexion')))['clearHistory']);
    }

    public function test_la_fin_de_l_activation_efface_l_historique_qui_contenait_le_secret(): void
    {
        Artisan::call('personnel:creer-medecin', [
            '--email' => 'dr.nouveau@exemple.cd', '--telephone' => '0815559999', '--nom' => 'Nouveau',
            '--prenom' => 'Jean', '--specialite' => 'pediatrie', '--ordre' => 'CNOM-4242',
        ]);
        preg_match('#(http\S+/personnel/activation/\S+)#', Artisan::output(), $m);
        $this->get($m[1]);
        $this->post(route('personnel.activation.mot-de-passe.enregistrer'), ['password' => 'Tshangu-Lemba-2026!', 'password_confirmation' => 'Tshangu-Lemba-2026!']);
        $this->assertTrue($this->page($this->get(route('personnel.activation.double-facteur')))['encryptHistory']);

        $user = User::where('email', 'dr.nouveau@exemple.cd')->sole();
        $secret = AuthentificationPersonnel::pour($user)->totp_secret;
        $this->post(route('personnel.activation.double-facteur.confirmer'), ['code' => Totp::code($secret, Totp::pas())])
            ->assertRedirect(route('personnel.codes-recuperation'));

        $this->assertTrue($this->page($this->get(route('personnel.codes-recuperation')))['clearHistory']);
    }

    // --- 4. Même verrou que la réservation ---------------------------------------

    /** Une autre connexion tient le verrou consultatif du médecin, comme une réservation en cours. */
    private function sousLeVerrouDeLaReservation(callable $ecriture): void
    {
        config(['database.connections.pgsql_concurrente' => config('database.connections.pgsql')]);
        $concurrente = DB::connection('pgsql_concurrente');
        $concurrente->beginTransaction();
        $concurrente->select('SELECT pg_advisory_xact_lock(hashtext(?))', ['medecin:'.$this->medecin->id]);

        DB::statement("SET lock_timeout = '200ms'");
        try {
            $ecriture();
            $this->fail('L\'écriture aurait dû attendre le verrou du médecin pris par la réservation.');
        } catch (QueryException $e) {
            $this->assertSame('55P03', $e->getCode(), 'lock_not_available');
        } finally {
            DB::statement('SET lock_timeout = 0');
            $concurrente->rollBack();
            $concurrente->disconnect();
        }
    }

    public function test_une_absence_attend_la_reservation_en_cours_du_meme_medecin(): void
    {
        $this->sousLeVerrouDeLaReservation(fn () => app(DisponibilitesMedecinService::class)->ajouterAbsence(
            $this->medecin,
            CarbonImmutable::parse('2026-11-20 08:00'),
            CarbonImmutable::parse('2026-11-21 08:00'),
            null,
        ));
    }

    public function test_une_plage_attend_la_reservation_en_cours_du_meme_medecin(): void
    {
        $this->sousLeVerrouDeLaReservation(fn () => app(DisponibilitesMedecinService::class)->ajouterPlage($this->medecin, 1, '09:00', '12:00'));
    }
}
