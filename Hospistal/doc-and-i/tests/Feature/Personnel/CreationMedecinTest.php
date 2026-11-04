<?php

namespace Tests\Feature\Personnel;

use App\Models\JetonActivation;
use App\Models\Medecin;
use App\Models\User;
use Database\Seeders\ReferentielMedicalSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\PersonnelDeTest;
use Tests\TestCase;

/** CPT-05 : un administrateur crée le compte d'un médecin, puis vérifie son inscription à l'Ordre. */
class CreationMedecinTest extends TestCase
{
    use PersonnelDeTest, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesSeeder::class, ReferentielMedicalSeeder::class]);
    }

    /** @param array<string, string> $surcharges */
    private function creer(array $surcharges = []): int
    {
        return Artisan::call('personnel:creer-medecin', array_merge([
            '--email' => 'Dr.Mukendi@Exemple.cd',
            '--telephone' => '081 555 12 34',
            '--nom' => 'Mukendi',
            '--prenom' => 'Pauline',
            '--specialite' => 'pediatrie',
            '--ordre' => 'CNOM-12345',
        ], $surcharges));
    }

    public function test_la_commande_cree_un_medecin_non_verifie_sans_mot_de_passe(): void
    {
        $this->assertSame(0, $this->creer());

        $user = User::where('email', 'dr.mukendi@exemple.cd')->sole();
        $this->assertSame('+243815551234', $user->telephone);
        $this->assertSame('MUKENDI', $user->nom);
        $this->assertSame('Pauline', $user->prenom);
        $this->assertTrue($user->hasRole('medecin'));
        $this->assertTrue($user->estDuPersonnel());
        $this->assertNull($user->password, 'Aucun mot de passe : le médecin le choisit lui-même.');

        $medecin = $user->medecin;
        $this->assertSame('CNOM-12345', $medecin->numero_ordre);
        $this->assertSame('pediatrie', $medecin->specialite->slug);
        $this->assertNull($medecin->ordre_verifie_le);
        $this->assertFalse($medecin->estReservable(), 'Pas proposé aux patients avant la vérification à l\'Ordre.');
    }

    public function test_la_commande_affiche_un_lien_d_activation_signe_valable_72_heures(): void
    {
        $this->travelTo(now()->startOfMinute());
        $this->creer();
        $sortie = Artisan::output();

        $this->assertMatchesRegularExpression('#/personnel/activation/[A-Za-z0-9]{64}\?expires=\d+&signature=[0-9a-f]{64}#', $sortie);
        preg_match('#/personnel/activation/([A-Za-z0-9]{64})\?expires=(\d+)#', $sortie, $m);
        $this->assertSame(now()->addHours(72)->getTimestamp(), (int) $m[2]);

        $jeton = JetonActivation::sole();
        $this->assertTrue($jeton->expire_le->equalTo(now()->addHours(72)));
        $this->assertNotSame($m[1], $jeton->empreinte, 'Le jeton est gardé en empreinte, jamais en clair.');
        $this->assertStringNotContainsString($m[1], json_encode(JetonActivation::query()->toBase()->get()));
        $this->assertStringNotContainsStringIgnoringCase('mot de passe :', $sortie);
    }

    public function test_une_specialite_inconnue_est_refusee_sans_rien_creer(): void
    {
        $this->assertNotSame(0, $this->creer(['--specialite' => 'chirurgie-spatiale']));
        $this->assertSame(0, Medecin::count());
        $this->assertNull(User::where('email', 'dr.mukendi@exemple.cd')->first());
    }

    public function test_un_numero_de_telephone_invalide_est_refuse(): void
    {
        $this->assertNotSame(0, $this->creer(['--telephone' => '0212345678']));
        $this->assertSame(0, Medecin::count());
    }

    public function test_un_courriel_deja_pris_est_refuse(): void
    {
        $this->creer();

        $this->assertNotSame(0, $this->creer(['--email' => 'dr.mukendi@exemple.cd', '--telephone' => '0815550000', '--ordre' => 'CNOM-99999']));
        $this->assertSame(1, Medecin::count());
    }

    public function test_un_numero_d_ordre_deja_pris_est_refuse(): void
    {
        $this->creer();

        $this->assertNotSame(0, $this->creer(['--email' => 'autre@exemple.cd', '--telephone' => '0815550000']));
        $this->assertSame(1, Medecin::count());
    }

    public function test_un_telephone_deja_pris_est_refuse(): void
    {
        $patient = User::factory()->create();

        $this->assertNotSame(0, $this->creer(['--telephone' => $patient->telephone]));
        $this->assertSame(0, Medecin::count());
        $this->assertFalse($patient->fresh()->hasRole('medecin'), 'Le compte du patient n\'est pas transformé.');
    }

    public function test_un_champ_manquant_est_refuse(): void
    {
        $this->assertNotSame(0, $this->creer(['--ordre' => '']));
        $this->assertSame(0, Medecin::count());
    }

    public function test_la_verification_a_l_ordre_rend_le_medecin_reservable(): void
    {
        $this->creer();
        $this->travelTo(now()->startOfSecond());

        $this->assertSame(0, Artisan::call('personnel:verifier-ordre', ['email' => 'DR.MUKENDI@exemple.cd']));

        $medecin = User::where('email', 'dr.mukendi@exemple.cd')->sole()->medecin;
        $this->assertTrue($medecin->ordre_verifie_le->equalTo(now()));
        $this->assertTrue($medecin->estReservable());
        $this->assertEquals([$medecin->id], Medecin::reservables()->pluck('id')->all());
    }

    public function test_la_verification_a_l_ordre_ne_change_pas_une_date_deja_posee(): void
    {
        $medecin = Medecin::factory()->create();
        $date = $medecin->ordre_verifie_le;
        $this->travel(2)->days();

        $this->assertSame(0, Artisan::call('personnel:verifier-ordre', ['email' => $medecin->user->email]));
        $this->assertTrue($medecin->fresh()->ordre_verifie_le->equalTo($date));
    }

    public function test_la_verification_refuse_un_compte_qui_n_est_pas_medecin(): void
    {
        $caissier = $this->personnelActive('caisse');

        $this->assertNotSame(0, Artisan::call('personnel:verifier-ordre', ['email' => $caissier->email]));
        $this->assertNotSame(0, Artisan::call('personnel:verifier-ordre', ['email' => 'inconnu@exemple.cd']));
    }

    public function test_un_nouveau_lien_remplace_le_precedent(): void
    {
        $this->creer();
        preg_match('#(http\S+/personnel/activation/\S+)#', Artisan::output(), $premier);

        $this->assertSame(0, Artisan::call('personnel:lien-activation', ['email' => 'dr.mukendi@exemple.cd']));
        preg_match('#(http\S+/personnel/activation/\S+)#', Artisan::output(), $second);

        $this->assertNotSame($premier[1], $second[1]);
        $this->get($premier[1])->assertStatus(410);
        $this->get($second[1])->assertRedirect(route('personnel.activation.mot-de-passe'));
    }

    public function test_aucun_lien_n_est_reemis_pour_un_compte_deja_active(): void
    {
        $medecin = $this->medecinActive();

        $this->assertNotSame(0, Artisan::call('personnel:lien-activation', ['email' => $medecin->user->email]));
        $this->assertSame(0, JetonActivation::count());
    }
}
