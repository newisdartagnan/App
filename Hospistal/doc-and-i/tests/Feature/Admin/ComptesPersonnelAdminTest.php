<?php

namespace Tests\Feature\Admin;

use App\Models\EntreeJournalAdministration;
use App\Models\JetonActivation;
use App\Models\Partenaire;
use App\Models\User;
use Database\Seeders\ReferentielMedicalSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Testing\TestResponse;
use Tests\Support\PersonnelDeTest;
use Tests\TestCase;

/** ADM-01, CPT-05 : l'administrateur crée les comptes du personnel ; le lien d'activation est montré une fois. */
class ComptesPersonnelAdminTest extends TestCase
{
    use PersonnelDeTest, RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesSeeder::class, ReferentielMedicalSeeder::class]);
        $this->admin = $this->personnelActive('admin_fonctionnel');
    }

    private function creer(array $champs = []): TestResponse
    {
        return $this->commePersonnel($this->admin)->post(route('personnel.admin.personnel.creer'), $champs + [
            'role' => 'caisse', 'email' => 'Rose.Kabongo@Exemple.cd', 'telephone' => '0815550101', 'nom' => 'Kabongo', 'prenom' => 'Rose',
        ]);
    }

    private function laboratoire(bool $actif = true): Partenaire
    {
        return Partenaire::create(['type' => 'laboratoire', 'nom' => 'Labo Gombe '.($actif ? 'A' : 'B'), 'actif' => $actif]);
    }

    /** Lien affiché sur la fiche, pris dans les données de la page. */
    private function lienAffiche(User $compte): ?string
    {
        return $this->commePersonnel($this->admin)->get(route('personnel.admin.personnel.fiche', $compte->id))
            ->assertOk()->viewData('page')['props']['lienActivation'];
    }

    // --- Création -------------------------------------------------------------------

    public function test_l_administrateur_cree_un_agent_de_caisse_sans_mot_de_passe(): void
    {
        $reponse = $this->creer();

        $agent = User::where('email', 'rose.kabongo@exemple.cd')->sole();
        $reponse->assertRedirect(route('personnel.admin.personnel.fiche', $agent->id));
        $this->assertTrue($agent->hasRole('caisse'));
        $this->assertNull($agent->password);
        $this->assertSame('+243815550101', $agent->telephone);
        $this->assertSame(1, JetonActivation::where('user_id', $agent->id)->count());
    }

    public function test_un_medecin_cree_par_l_ecran_n_est_pas_propose_avant_la_verification_de_l_ordre(): void
    {
        $this->creer(['role' => 'medecin', 'specialite' => 'pediatrie', 'ordre' => 'CNOM-5151'])->assertSessionHasNoErrors();

        $medecin = User::where('email', 'rose.kabongo@exemple.cd')->sole()->medecin;
        $this->assertSame(['CNOM-5151', 'pediatrie'], [$medecin->numero_ordre, $medecin->specialite->slug]);
        $this->assertNull($medecin->ordre_verifie_le);
        $this->assertFalse($medecin->estReservable());
    }

    public function test_un_compte_laboratoire_est_rattache_a_un_laboratoire_partenaire_actif(): void
    {
        $this->creer(['role' => 'laboratoire'])->assertSessionHasErrors('partenaire_id');
        $this->creer(['role' => 'laboratoire', 'partenaire_id' => $this->laboratoire(false)->id])->assertSessionHasErrors('partenaire_id');
        $this->assertNull(User::where('email', 'rose.kabongo@exemple.cd')->first());

        $labo = $this->laboratoire();
        $this->creer(['role' => 'laboratoire', 'partenaire_id' => $labo->id])->assertSessionHasNoErrors();

        $agent = User::where('email', 'rose.kabongo@exemple.cd')->sole();
        $this->assertDatabaseHas('comptes_laboratoire', ['user_id' => $agent->id, 'partenaire_id' => $labo->id]);
        $this->commePersonnel($this->admin)->get(route('personnel.admin.personnel.fiche', $agent->id))
            ->assertInertia(fn ($page) => $page->where('compte.laboratoire', 'Labo Gombe A'));
    }

    public function test_aucun_administrateur_ne_se_cree_depuis_l_ecran(): void
    {
        foreach (['admin_fonctionnel', 'admin_technique', 'patient', 'auditeur', 'inconnu'] as $role) {
            $this->creer(['role' => $role])->assertSessionHasErrors('role');
        }

        $this->assertNull(User::where('email', 'rose.kabongo@exemple.cd')->first());
        $this->commePersonnel($this->admin)->get(route('personnel.admin.personnel.nouveau'))
            ->assertInertia(fn ($page) => $page->component('Personnel/Admin/Personnel/Nouveau')
                ->missing('roles.admin_fonctionnel')->missing('roles.admin_technique')->has('roles.medecin'));
    }

    public function test_un_double_envoi_ne_cree_qu_un_compte(): void
    {
        $this->creer();
        $this->creer()->assertSessionHasErrors(['email', 'telephone']);
        $this->creer(['email' => 'autre@exemple.cd'])->assertSessionHasErrors('telephone');
        $this->creer(['telephone' => '0815550199'])->assertSessionHasErrors('email');

        $this->assertSame(1, User::where('email', 'rose.kabongo@exemple.cd')->count());
        $this->assertSame(1, EntreeJournalAdministration::where('action', 'personnel.compte_cree')->count());
    }

    // --- Lien d'activation ---------------------------------------------------------------

    public function test_le_lien_est_affiche_une_seule_fois_puis_retire_de_la_session(): void
    {
        $reponse = $this->creer();
        $agent = User::where('email', 'rose.kabongo@exemple.cd')->sole();

        $this->assertStringNotContainsString('activation', (string) $reponse->headers->get('Location'), 'Jamais dans l\'adresse de redirection.');
        $lien = $this->lienAffiche($agent);
        $this->assertMatchesRegularExpression('#/personnel/activation/[A-Za-z0-9]{64}\?expires=#', $lien);
        // Retiré de la session dès l'affichage, pas seulement à la requête suivante.
        $this->assertStringNotContainsString(substr(parse_url($lien, PHP_URL_PATH), -64), json_encode(session()->all()), 'Plus en session après l\'affichage.');
        $this->assertNull($this->lienAffiche($agent), 'Une seule fois.');

        // Le lien fonctionne pour la personne.
        $this->post(route('personnel.deconnexion'));
        $this->get($lien)->assertRedirect(route('personnel.activation.mot-de-passe'));
    }

    public function test_le_lien_n_est_jamais_ecrit_au_journal(): void
    {
        $this->creer();
        $agent = User::where('email', 'rose.kabongo@exemple.cd')->sole();
        $jeton = substr(parse_url($this->lienAffiche($agent), PHP_URL_PATH), -64);
        $this->commePersonnel($this->admin)->post(route('personnel.admin.personnel.lien', $agent->id));
        $second = substr(parse_url($this->lienAffiche($agent), PHP_URL_PATH), -64);

        $journal = json_encode(EntreeJournalAdministration::query()->toBase()->get());
        $this->assertStringNotContainsString($jeton, $journal);
        $this->assertStringNotContainsString($second, $journal);
        $this->assertStringNotContainsString('signature', $journal);
    }

    public function test_un_nouveau_lien_invalide_l_ancien(): void
    {
        $this->creer();
        $agent = User::where('email', 'rose.kabongo@exemple.cd')->sole();
        $ancien = $this->lienAffiche($agent);

        $this->commePersonnel($this->admin)->post(route('personnel.admin.personnel.lien', $agent->id))->assertSessionHasNoErrors();
        $nouveau = $this->lienAffiche($agent);

        $this->assertNotSame($ancien, $nouveau);
        $this->post(route('personnel.deconnexion'));
        $this->get($ancien)->assertStatus(410);
        $this->get($nouveau)->assertRedirect(route('personnel.activation.mot-de-passe'));
    }

    public function test_pas_de_lien_depuis_l_ecran_pour_un_compte_administrateur_ou_deja_active(): void
    {
        $autreAdmin = User::factory()->sansRole()->create(['email' => 'admin2@exemple.cd']);
        $autreAdmin->assignRole('admin_fonctionnel');
        $caisseActive = $this->personnelActive('caisse');

        $this->commePersonnel($this->admin)->post(route('personnel.admin.personnel.lien', $autreAdmin->id))->assertSessionHasErrors('lien');
        $this->commePersonnel($this->admin)->post(route('personnel.admin.personnel.lien', $caisseActive->id))->assertSessionHasErrors('lien');

        $this->assertSame(0, JetonActivation::count());
    }

    // --- Liste ------------------------------------------------------------------------------

    public function test_la_liste_montre_le_personnel_seulement_avec_etat_et_derniere_connexion(): void
    {
        User::factory()->create(['nom' => 'PATIENT']);
        $this->creer();
        $desactive = $this->personnelActive('support');
        $desactive->forceFill(['desactive_le' => now(), 'motif_desactivation' => 'Départ'])->save();

        $this->commePersonnel($this->admin)->get(route('personnel.admin.personnel'))
            ->assertOk()
            // L'administrateur, l'agent de caisse créé et le support désactivé ; pas le patient.
            ->assertInertia(fn ($page) => $page->component('Personnel/Admin/Personnel/Liste')->has('personnel.data', 3));

        $filtre = fn (array $f) => $this->commePersonnel($this->admin)->get(route('personnel.admin.personnel', $f));
        $filtre(['etat' => 'a_activer'])->assertInertia(fn ($p) => $p->has('personnel.data', 1)->where('personnel.data.0.etat', 'À activer'));
        $filtre(['etat' => 'desactive'])->assertInertia(fn ($p) => $p->has('personnel.data', 1)->where('personnel.data.0.id', $desactive->id));
        $filtre(['etat' => 'actif'])->assertInertia(fn ($p) => $p->has('personnel.data', 1)->where('personnel.data.0.id', $this->admin->id));
        $filtre(['role' => 'caisse'])->assertInertia(fn ($p) => $p->has('personnel.data', 1));
        $filtre(['role' => 'patient'])->assertSessionHasErrors('role');
    }

    public function test_un_patient_n_a_pas_de_fiche_dans_l_espace_administrateur(): void
    {
        $patient = User::factory()->create();

        $this->commePersonnel($this->admin)->get(route('personnel.admin.personnel.fiche', $patient->id))->assertNotFound();
    }

    // --- Journal et commande -------------------------------------------------------------------

    public function test_la_creation_est_journalisee_avec_son_auteur(): void
    {
        $this->creer();
        $agent = User::where('email', 'rose.kabongo@exemple.cd')->sole();

        $entrees = EntreeJournalAdministration::orderBy('cree_le')->get();
        $this->assertSame(['personnel.compte_cree', 'personnel.lien_activation_emis'], $entrees->pluck('action')->all());
        $this->assertTrue($entrees->every(fn ($e) => $e->auteur_id === $this->admin->id && $e->cible_id === $agent->id));
        $this->assertSame(['role' => 'caisse'], $entrees->first()->details);
    }

    public function test_la_commande_cree_l_administrateur_fonctionnel(): void
    {
        $this->assertSame(0, Artisan::call('personnel:creer-compte', [
            '--role' => 'admin_fonctionnel', '--email' => 'boris.ikula@exemple.cd', '--telephone' => '0815559911', '--nom' => 'Ikula', '--prenom' => 'Boris',
        ]));

        $boris = User::where('email', 'boris.ikula@exemple.cd')->sole();
        $this->assertTrue($boris->hasRole('admin_fonctionnel'));
        $this->assertNull($boris->password);
        $this->assertMatchesRegularExpression('#/personnel/activation/[A-Za-z0-9]{64}#', Artisan::output());
    }
}
