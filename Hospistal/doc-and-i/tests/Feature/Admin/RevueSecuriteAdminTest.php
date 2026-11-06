<?php

namespace Tests\Feature\Admin;

use App\Enums\StatutRendezVous;
use App\Models\Facture;
use App\Models\Partenaire;
use App\Models\User;
use App\Services\Administration\JournalAdministration;
use App\Services\Personnel\ComptePersonnelService;
use App\Sms\EnvoyeurSms;
use Database\Seeders\ReferentielMedicalSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\EnvoyeurSmsFactice;
use Tests\Support\ParcoursRendezVous;
use Tests\Support\PersonnelDeTest;
use Tests\TestCase;

/** Non-régression des constats de la revue de sécurité de l'espace administrateur. */
class RevueSecuriteAdminTest extends TestCase
{
    use ParcoursRendezVous, PersonnelDeTest, RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesSeeder::class, ReferentielMedicalSeeder::class]);
        $this->app->instance(EnvoyeurSms::class, new EnvoyeurSmsFactice);
        $this->admin = $this->personnelActive('admin_fonctionnel');
    }

    private function comptes(): ComptePersonnelService
    {
        return app(ComptePersonnelService::class);
    }

    private function desactiveSansEcran(User $user): void
    {
        $user->forceFill(['desactive_le' => now(), 'motif_desactivation' => 'Départ'])->save();
    }

    public function test_un_administrateur_ne_reactive_pas_un_autre_administrateur(): void
    {
        $autre = $this->personnelActive('admin_fonctionnel');
        $this->desactiveSansEcran($autre);

        $this->commePersonnel($this->admin)->post("/personnel/admin/personnel/{$autre->id}/reactivation", ['motif_reactivation' => 'Retour'])
            ->assertSessionHasErrors('motif_reactivation');

        $this->assertTrue($autre->fresh()->estDesactive());
    }

    public function test_les_comptes_hors_ecran_ne_se_desactivent_pas_depuis_l_ecran(): void
    {
        foreach (['admin_fonctionnel', 'admin_technique', 'auditeur'] as $role) {
            $cible = $this->personnelActive($role);
            $this->commePersonnel($this->admin)->post("/personnel/admin/personnel/{$cible->id}/desactivation", ['motif' => 'Prise de pouvoir'])
                ->assertSessionHasErrors('motif');
            $this->assertFalse($cible->fresh()->estDesactive(), $role);
        }
    }

    public function test_aucun_lien_d_activation_pour_un_auditeur_depuis_l_ecran(): void
    {
        $auditeur = User::factory()->sansRole()->create(['email' => 'audit@exemple.cd']);
        $auditeur->assignRole('auditeur');

        $this->commePersonnel($this->admin)->post("/personnel/admin/personnel/{$auditeur->id}/lien-activation")
            ->assertSessionHasErrors('lien');
        $this->assertSame(0, DB::table('jetons_activation_personnel')->where('user_id', $auditeur->id)->count());
    }

    public function test_l_administrateur_qui_cree_un_medecin_ne_verifie_pas_son_ordre(): void
    {
        [$user] = $this->comptes()->creerParAdministrateur($this->admin, 'medecin', [
            'email' => 'dr.nouveau@exemple.cd', 'telephone' => '0815550001', 'nom' => 'Nouveau', 'prenom' => 'Paul',
            'specialite' => 'medecine-generale', 'ordre' => 'CNOM-9001',
        ]);

        $this->commePersonnel($this->admin)->post("/personnel/admin/personnel/{$user->id}/ordre")->assertSessionHasErrors('ordre');
        $this->assertNull($user->medecin->fresh()->ordre_verifie_le);

        // Un second administrateur le peut.
        $second = $this->personnelActive('admin_fonctionnel');
        $this->commePersonnel($second)->post("/personnel/admin/personnel/{$user->id}/ordre")->assertSessionHasNoErrors();
        $this->assertNotNull($user->medecin->fresh()->ordre_verifie_le);
    }

    public function test_un_medecin_reactive_doit_etre_verifie_de_nouveau_a_l_ordre(): void
    {
        $medecin = $this->medecinActive();
        $this->comptes()->desactiver($medecin->user, $this->admin, 'Suspension');
        $this->comptes()->reactiver($medecin->user, $this->admin, 'Fin de suspension');

        $medecin->refresh();
        $this->assertNull($medecin->ordre_verifie_le);
        $this->assertFalse($medecin->estReservable());

        // Celui qui a réactivé ne vérifie pas lui-même.
        $this->expectException(ValidationException::class);
        $this->comptes()->verifierOrdreParAdministrateur($medecin, $this->admin);
    }

    public function test_le_motif_interne_de_desactivation_n_est_pas_montre_aux_patients(): void
    {
        $this->voyagerAuLundi();
        $medecin = $this->medecinActive();
        $rdv = $this->rendezVousExistant($medecin, $this->patient(), $this->lundi('10:00'));

        $this->comptes()->desactiver($medecin->user, $this->admin, 'Enquête pour faute grave', true);

        $rdv->refresh();
        $this->assertSame(StatutRendezVous::AnnuleParMedecin, $rdv->statut);
        $this->assertSame('Médecin indisponible', $rdv->motif_annulation);
    }

    public function test_une_session_ouverte_avant_la_desactivation_ne_revit_pas_a_la_reactivation(): void
    {
        $caisse = $this->personnelActive('caisse');
        $this->comptes()->desactiver($caisse, $this->admin, 'Contrôle');
        $this->comptes()->reactiver($caisse->fresh(), $this->admin, 'Contrôle terminé');

        // commePersonnel pose une session de version 0, celle d'avant la désactivation.
        $this->commePersonnel($caisse->fresh())->get('/personnel/caisse')->assertRedirect('/personnel/connexion');
        $this->assertSame(1, $caisse->fresh()->version_session);
    }

    public function test_un_laboratoire_avec_des_comptes_ne_devient_pas_une_pharmacie(): void
    {
        $labo = Partenaire::create(['type' => 'laboratoire', 'nom' => 'Labo Gombe']);
        $this->comptes()->creerParAdministrateur($this->admin, 'laboratoire', [
            'email' => 'labo@exemple.cd', 'telephone' => '0815550002', 'nom' => 'Mbala', 'prenom' => 'Jean', 'partenaire_id' => $labo->id,
        ]);

        $this->commePersonnel($this->admin)->put("/personnel/admin/partenaires/{$labo->id}", ['type' => 'pharmacie', 'nom' => 'Labo Gombe'])
            ->assertSessionHasErrors('type');
        $this->assertSame('laboratoire', $labo->fresh()->type);
    }

    public function test_truncate_est_refuse_sur_le_journal_et_les_pieces_comptables(): void
    {
        app(JournalAdministration::class)->consigner($this->admin, 'test');

        foreach (['journal_administration', 'factures', 'operations_caisse'] as $table) {
            try {
                DB::transaction(fn () => DB::statement("TRUNCATE {$table} CASCADE"));
                $this->fail("TRUNCATE accepté sur {$table}.");
            } catch (QueryException $e) {
                $this->assertStringContainsString('non modifiable', $e->getMessage());
            }
        }
        $this->assertSame(0, Facture::count());
    }

    public function test_les_commandes_desactivent_et_reactivent_un_administrateur_pas_un_medecin(): void
    {
        $autre = $this->personnelActive('admin_fonctionnel');

        $this->artisan('personnel:desactiver', ['email' => $autre->email])->assertFailed();
        $this->artisan('personnel:desactiver', ['email' => $autre->email, '--motif' => 'Départ'])->assertSuccessful();
        $this->assertTrue($autre->fresh()->estDesactive());
        $this->assertSame(1, $autre->fresh()->version_session);

        $this->artisan('personnel:reactiver', ['email' => $autre->email])->assertSuccessful();
        $this->assertFalse($autre->fresh()->estDesactive());

        $this->artisan('personnel:desactiver', ['email' => $this->medecinActive()->user->email, '--motif' => 'X'])->assertFailed();
    }

    public function test_l_espace_administrateur_a_une_limite_de_debit(): void
    {
        foreach (['personnel.admin.accueil', 'personnel.admin.tarifs', 'personnel.admin.partenaires', 'personnel.admin.personnel'] as $nom) {
            $this->assertContains('throttle:administration', app('router')->getRoutes()->getByName($nom)->gatherMiddleware(), $nom);
        }
    }
}
