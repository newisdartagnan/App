<?php

namespace Tests\Feature\Admin;

use App\Models\EntreeJournalAdministration;
use App\Models\Partenaire;
use App\Models\User;
use App\Services\Administration\JournalAdministration;
use App\Services\Personnel\ActivationPersonnelService;
use Database\Seeders\RolesSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;
use Tests\Support\PersonnelDeTest;
use Tests\TestCase;

/** Socle de l'espace administrateur : accès, comptes désactivés, journal non modifiable. */
class SocleAdministrationTest extends TestCase
{
    use PersonnelDeTest, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesSeeder::class);
    }

    public function test_l_administrateur_arrive_dans_son_espace(): void
    {
        $admin = $this->personnelActive('admin_fonctionnel');

        $this->commePersonnel($admin)->get('/personnel')->assertRedirect('/personnel/admin');
        $this->commePersonnel($admin)->get('/personnel/admin')->assertOk()
            ->assertInertia(fn ($page) => $page->component('Personnel/Admin/Accueil'));
    }

    public function test_les_autres_roles_n_entrent_pas_dans_l_espace_administrateur(): void
    {
        foreach (['caisse', 'support', 'coordinateur', 'admin_technique'] as $role) {
            $this->commePersonnel($this->personnelActive($role))->get('/personnel/admin')->assertForbidden();
        }
        $this->commePersonnel($this->medecinActive()->user)->get('/personnel/admin')->assertForbidden();
        $this->actingAs(User::factory()->create())->get('/personnel/admin')->assertForbidden();
    }

    public function test_sans_connexion_l_espace_administrateur_renvoie_a_la_connexion(): void
    {
        $this->get('/personnel/admin')->assertRedirect('/personnel/connexion');
    }

    public function test_un_compte_desactive_perd_sa_session_et_ne_se_connecte_plus(): void
    {
        $caisse = $this->personnelActive('caisse');
        $caisse->forceFill(['desactive_le' => now(), 'motif_desactivation' => 'Départ'])->save();

        $this->commePersonnel($caisse)->get('/personnel/caisse')->assertRedirect('/personnel/connexion');
        $this->assertGuest();

        // À la connexion, il répond comme un compte inconnu : aucune énumération.
        $this->post('/personnel/connexion', ['email' => $caisse->email, 'password' => 'mot-de-passe-de-test'])
            ->assertSessionHasErrors(['email' => 'Courriel ou mot de passe incorrect.']);
    }

    public function test_un_compte_desactive_ne_recoit_pas_de_lien_et_ses_liens_ne_servent_plus(): void
    {
        $user = User::factory()->sansRole()->create(['email' => 'nouveau@exemple.cd']);
        $user->assignRole('caisse');
        $lien = app(ActivationPersonnelService::class)->emettreLien($user);
        $user->forceFill(['desactive_le' => now()])->save();

        $this->get($lien)->assertInertia(fn ($page) => $page->component('Personnel/Activation/LienInvalide'));

        $this->expectException(InvalidArgumentException::class);
        app(ActivationPersonnelService::class)->emettreLien($user);
    }

    public function test_le_journal_garde_auteur_action_cible_et_refuse_les_secrets(): void
    {
        $admin = $this->personnelActive('admin_fonctionnel');
        $partenaire = Partenaire::create(['type' => 'laboratoire', 'nom' => 'Labo Gombe']);

        $entree = app(JournalAdministration::class)->consigner($admin, 'partenaire.creer', $partenaire, ['nom' => 'Labo Gombe']);

        $this->assertSame(['Partenaire', $partenaire->id, ['nom' => 'Labo Gombe']], [$entree->cible_type, $entree->cible_id, $entree->fresh()->details]);

        foreach (['password' => 'x', 'lien' => 'https://…', 'secret' => 'x'] as $cle => $valeur) {
            try {
                app(JournalAdministration::class)->consigner($admin, 'test', null, [$cle => $valeur]);
                $this->fail("Clé « {$cle} » acceptée.");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_le_journal_ne_se_modifie_ni_ne_se_supprime(): void
    {
        $entree = app(JournalAdministration::class)->consigner($this->personnelActive('admin_fonctionnel'), 'test');

        try {
            $entree->forceFill(['action' => 'autre'])->save();
            $this->fail('Modification acceptée par le modèle.');
        } catch (LogicException) {
        }

        foreach (['UPDATE journal_administration SET action = \'x\'', 'DELETE FROM journal_administration'] as $sql) {
            try {
                DB::transaction(fn () => DB::statement($sql));
                $this->fail("La base a accepté : {$sql}");
            } catch (QueryException $e) {
                $this->assertStringContainsString('journal non modifiable', $e->getMessage());
            }
        }
        $this->assertSame(1, EntreeJournalAdministration::count());
    }

    public function test_un_partenaire_est_un_laboratoire_ou_une_pharmacie(): void
    {
        $this->expectException(QueryException::class);
        Partenaire::create(['type' => 'clinique', 'nom' => 'X']);
    }
}
