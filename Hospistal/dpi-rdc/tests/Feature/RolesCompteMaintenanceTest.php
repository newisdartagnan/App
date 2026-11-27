<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Establishment;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use App\Models\Role;
use Tests\TestCase;

/**
 * Rôles libres et leurs permissions (cases ou configuration en chaîne),
 * comptes à rôles multiples, Mon compte, activités et maintenance.
 */
class RolesCompteMaintenanceTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Establishment $etab;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->admin = User::where('email', 'admin@dpi-rdc.local')->firstOrFail();
        $this->etab = Establishment::firstOrFail();
        $this->actingAs($this->admin);
    }

    protected function agent(array $roles = []): User
    {
        $u = User::factory()->create(['establishment_id' => $this->etab->id]);
        if ($roles) {
            $u->syncRoles($roles);
        }

        return $u;
    }

    public function test_creer_un_role_en_cochant_puis_le_regler_par_chaine(): void
    {
        $this->post(route('roles.store'), [
            'libelle' => 'Accoucheuse',
            'permissions' => ['patient.view', 'soin.execute', 'inexistante.permission'],
        ])->assertRedirect(route('roles.index'));

        $role = Role::where('name', 'accoucheuse')->firstOrFail();
        $this->assertSame('Accoucheuse', $role->libelle);
        $this->assertEqualsCanonicalizing(['patient.view', 'soin.execute'], $role->permissions->pluck('name')->all());

        // La chaîne remplace les cases.
        $this->put(route('roles.update', $role), [
            'libelle' => 'Accoucheuse',
            'permissions' => ['patient.view'],
            'chaine' => 'patient.view\\consultation.view\\labo.view\\',
        ])->assertRedirect();
        $this->assertEqualsCanonicalizing(
            ['patient.view', 'consultation.view', 'labo.view'],
            $role->fresh()->permissions->pluck('name')->all()
        );

        $this->get(route('roles.edit', $role))
            ->assertOk()
            ->assertSee('Afficher la configuration en chaîne')
            ->assertSee('consultation.view\\labo.view\\patient.view\\', false);

        $this->get(route('roles.index'))->assertOk()->assertSee('Accoucheuse');
    }

    public function test_un_role_attribue_ne_se_supprime_pas(): void
    {
        $role = Role::create(['name' => 'archiviste', 'guard_name' => 'web', 'libelle' => 'Archiviste']);
        $agent = $this->agent(['archiviste']);

        $this->delete(route('roles.destroy', $role))->assertSessionHas('error');
        $this->assertDatabaseHas('roles', ['id' => $role->id]);

        $agent->syncRoles([]);
        $this->delete(route('roles.destroy', $role))->assertSessionHas('success');
        $this->assertDatabaseMissing('roles', ['id' => $role->id]);

        $super = Role::where('name', 'super_admin')->first();
        $this->delete(route('roles.destroy', $super))->assertSessionHas('error');
    }

    public function test_le_super_admin_garde_toutes_ses_permissions(): void
    {
        $super = Role::where('name', 'super_admin')->first();
        $this->put(route('roles.update', $super), ['libelle' => 'Super administrateur', 'permissions' => []]);
        $this->assertTrue($super->fresh()->hasPermissionTo('user.manage'));
    }

    public function test_un_agent_cumule_plusieurs_roles_et_une_fonction_libre_donne_ses_droits(): void
    {
        Role::create(['name' => 'comptable', 'guard_name' => 'web', 'libelle' => 'Comptable'])
            ->givePermissionTo('finance.view');
        $agent = $this->agent(['infirmier']);

        $this->get(route('utilisateurs.edit', $agent))->assertOk()->assertSee('Comptable');

        $this->post(route('utilisateurs.update', $agent), ['roles' => ['medecin', 'radiologue', 'comptable']])
            ->assertSessionHas('success');

        $agent->refresh();
        $this->assertTrue($agent->hasAllRoles(['medecin', 'radiologue', 'comptable']));
        $this->assertTrue($agent->can('finance.view'));
        $this->assertStringContainsString('Comptable', $agent->libelleRoles());

        $this->get(route('utilisateurs.index', ['recherche' => $agent->email]))
            ->assertOk()->assertSee($agent->nom_complet)->assertSee('Activités');
    }

    public function test_supprimer_un_compte_le_retire_sans_effacer_ses_traces(): void
    {
        $agent = $this->agent(['caissier']);
        $this->delete(route('utilisateurs.destroy', $agent))->assertSessionHas('success');

        $agent->refresh();
        $this->assertFalse($agent->is_active);
        $this->assertCount(0, $agent->roles);
        $this->assertDatabaseHas('users', ['id' => $agent->id]);

        $this->delete(route('utilisateurs.destroy', $this->admin))->assertSessionHas('error');
    }

    public function test_mon_compte_photo_informations_mot_de_passe_et_permissions(): void
    {
        Storage::fake('local');
        $agent = $this->agent(['infirmier']);
        $this->actingAs($agent);

        $this->get(route('compte.index'))
            ->assertOk()->assertSee('Mes rôles (1)')->assertSee('Mes permissions')
            ->assertSee('Administrer les soins');

        $this->post(route('compte.photo.store'), ['photo' => UploadedFile::fake()->image('moi.png', 800, 600)])
            ->assertSessionHas('success');
        $agent->refresh();
        $this->assertNotNull($agent->photo_path);
        Storage::disk('local')->assertExists($agent->photo_path);
        $this->get(route('compte.photo', $agent))->assertOk()->assertHeader('Content-Type', 'image/jpeg');

        $this->post(route('compte.informations'), ['nom' => 'AHADI', 'prenom' => 'Wilo', 'email' => 'wilo@hgr.cd'])
            ->assertSessionHas('success');
        $this->assertSame('wilo@hgr.cd', $agent->fresh()->email);

        $this->post(route('compte.mot-de-passe'), ['password' => 'Nouveau@2026', 'password_confirmation' => 'autre'])
            ->assertSessionHasErrors('password');
        $this->post(route('compte.mot-de-passe'), ['password' => 'Nouveau@2026', 'password_confirmation' => 'Nouveau@2026'])
            ->assertSessionHas('success');
        $this->assertTrue(Hash::check('Nouveau@2026', $agent->fresh()->password));
    }

    public function test_un_agent_sans_role_est_prevenu(): void
    {
        $this->actingAs($this->agent())->get(route('compte.index'))
            ->assertOk()->assertSee('aucun rôle ni d\'aucune permission', false);
    }

    public function test_les_activites_traduisent_le_journal(): void
    {
        Patient::create([
            'establishment_id' => $this->etab->id, 'dossier_number' => 'PAT-ACT-1',
            'nom' => 'JOURNAL', 'prenom' => 'Test', 'sexe' => 'M', 'type_prise_en_charge' => 'prive',
        ]);

        $this->get(route('utilisateurs.activites.agent', $this->admin))
            ->assertOk()->assertSee('a enregistré un patient');
        $this->get(route('utilisateurs.activites'))->assertOk()->assertSee($this->admin->nom_complet);

        // Un agent lit ses propres activités, pas celles des autres.
        $agent = $this->agent(['infirmier']);
        $this->actingAs($agent)->get(route('utilisateurs.activites.agent', $agent))->assertOk();
        $this->actingAs($agent)->get(route('utilisateurs.activites.agent', $this->admin))->assertForbidden();
    }

    public function test_maintenance_reservee_et_deconnexion_et_vidage_protege(): void
    {
        $this->actingAs($this->agent(['caissier']))->get(route('maintenance.index'))->assertForbidden();

        $this->actingAs($this->admin)->get(route('maintenance.index'))
            ->assertOk()->assertSee('Adresse')->assertSee('Utilisateurs connectés')
            ->assertSee('Sauvegarder maintenant')->assertDontSee('Vider la base de données');

        $this->get(route('maintenance.index', ['mode' => 'dieu']))
            ->assertOk()->assertSee('Vider la base de données')->assertDontSee('value="users"', false);

        // Sans la confirmation, rien n'est vidé ; une table protégée est refusée.
        $this->post(route('maintenance.vider'), ['tables' => ['rendez_vous']])->assertSessionHasErrors('confirmation');
        $this->post(route('maintenance.vider'), ['tables' => ['users'], 'confirmation' => 'VIDER'])->assertSessionHasErrors('tables.0');
        $this->assertGreaterThan(0, User::count());

        $this->post(route('maintenance.vider'), ['tables' => ['rendez_vous'], 'confirmation' => 'VIDER'])
            ->assertSessionHas('success');
    }

    public function test_acces_rapides_de_l_administrateur_montre_roles_et_maintenance(): void
    {
        $this->get(route('dashboard'))->assertOk()
            ->assertSee('Rôles d\'utilisateurs')->assertSee(route('roles.create'), false)
            ->assertSee('Maintenance')->assertSee(route('maintenance.index'), false);
    }
}
