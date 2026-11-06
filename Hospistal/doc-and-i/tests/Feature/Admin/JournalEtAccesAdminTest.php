<?php

namespace Tests\Feature\Admin;

use App\Models\EntreeJournalAdministration;
use App\Models\User;
use App\Services\Administration\JournalAdministration;
use Carbon\CarbonImmutable;
use Database\Seeders\ReferentielMedicalSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\PersonnelDeTest;
use Tests\TestCase;

/** ADM-02 : journal d'administration en lecture seule ; chaque écran et action réservé à l'administrateur fonctionnel. */
class JournalEtAccesAdminTest extends TestCase
{
    use PersonnelDeTest, RefreshDatabase;

    private const ID = '00000000-0000-0000-0000-000000000000';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesSeeder::class, ReferentielMedicalSeeder::class]);
        $this->travelTo(CarbonImmutable::parse('2026-12-07 09:00', 'Africa/Kinshasa'));
        $this->admin = $this->personnelActive('admin_fonctionnel');
    }

    // --- Journal ------------------------------------------------------------------------

    public function test_le_journal_liste_les_actions_avec_auteur_cible_et_details(): void
    {
        $agent = $this->personnelActive('caisse');
        app(JournalAdministration::class)->consigner($this->admin, 'personnel.compte_desactive', $agent, ['motif' => 'Départ', 'rendez_vous_annules' => 0]);

        $this->commePersonnel($this->admin)->get(route('personnel.admin.journal'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Personnel/Admin/Journal')
                ->has('entrees.data', 1)
                ->where('entrees.data.0.action', 'Compte désactivé')
                ->where('entrees.data.0.auteur', trim(mb_strtoupper($this->admin->nom).' '.$this->admin->prenom))
                ->where('entrees.data.0.cible', trim(mb_strtoupper($agent->nom).' '.$agent->prenom))
                ->where('entrees.data.0.details.0.valeur', 'Départ'));
    }

    public function test_le_journal_se_filtre_par_action_auteur_et_periode(): void
    {
        $autreAdmin = $this->personnelActive('admin_fonctionnel');
        $journal = app(JournalAdministration::class);
        $journal->consigner($this->admin, 'personnel.compte_cree', $this->admin, ['role' => 'caisse']);
        $journal->consigner($autreAdmin, 'medecin.ordre_verifie');
        $this->travelTo(CarbonImmutable::parse('2026-12-09 09:00', 'Africa/Kinshasa'));
        $journal->consigner($this->admin, 'medecin.ordre_verifie');

        $page = fn (array $f) => $this->commePersonnel($this->admin)->get(route('personnel.admin.journal', $f));

        $page(['action' => 'medecin.ordre_verifie'])->assertInertia(fn ($p) => $p->has('entrees.data', 2));
        $page(['auteur' => $autreAdmin->id])->assertInertia(fn ($p) => $p->has('entrees.data', 1));
        $page(['du' => '2026-12-07', 'au' => '2026-12-07'])->assertInertia(fn ($p) => $p->has('entrees.data', 2));
        $page(['du' => '2026-12-08'])->assertInertia(fn ($p) => $p->has('entrees.data', 1));
        $page([])->assertInertia(fn ($p) => $p->has('actions', 2)->has('auteurs', 2));
        $page(['du' => '2026-12-09', 'au' => '2026-12-07'])->assertSessionHasErrors('au');
    }

    public function test_le_journal_est_pagine_les_plus_recents_d_abord(): void
    {
        for ($i = 0; $i < 55; $i++) {
            $this->travel(1)->minutes();
            app(JournalAdministration::class)->consigner($this->admin, 'medecin.ordre_verifie', null, ['rang' => $i]);
        }

        $this->commePersonnel($this->admin)->get(route('personnel.admin.journal'))
            ->assertInertia(fn ($p) => $p->has('entrees.data', 50)->where('entrees.last_page', 2)->where('entrees.data.0.details.0.valeur', '54'));
        $this->commePersonnel($this->admin)->get(route('personnel.admin.journal', ['page' => 2]))
            ->assertInertia(fn ($p) => $p->has('entrees.data', 5));
    }

    public function test_le_journal_se_lit_seulement(): void
    {
        $this->commePersonnel($this->admin)->post(route('personnel.admin.journal'))->assertStatus(405);
        $this->commePersonnel($this->admin)->delete(route('personnel.admin.journal'))->assertStatus(405);
    }

    // --- Accès ------------------------------------------------------------------------------

    /** @return array<string, array{string, string}> */
    public static function ecransEtActions(): array
    {
        $base = 'http://localhost/personnel/admin/';

        return [
            'liste' => ['get', $base.'personnel'],
            'formulaire de création' => ['get', $base.'personnel/nouveau'],
            'création' => ['post', $base.'personnel'],
            'fiche' => ['get', $base.'personnel/'.self::ID],
            'nouveau lien' => ['post', $base.'personnel/'.self::ID.'/lien-activation'],
            'désactivation' => ['post', $base.'personnel/'.self::ID.'/desactivation'],
            'réactivation' => ['post', $base.'personnel/'.self::ID.'/reactivation'],
            'vérification de l\'Ordre' => ['post', $base.'personnel/'.self::ID.'/ordre'],
            'profil du médecin' => ['put', $base.'personnel/'.self::ID.'/medecin'],
            'journal' => ['get', $base.'journal'],
        ];
    }

    #[DataProvider('ecransEtActions')]
    public function test_un_visiteur_est_renvoye_vers_la_connexion_du_personnel(string $methode, string $adresse): void
    {
        $this->{$methode}($adresse)->assertRedirect(route('personnel.connexion'));
    }

    #[DataProvider('ecransEtActions')]
    public function test_un_patient_est_refuse(string $methode, string $adresse): void
    {
        $this->actingAs(User::factory()->create())->{$methode}($adresse)->assertForbidden();
    }

    #[DataProvider('ecransEtActions')]
    public function test_un_medecin_est_refuse(string $methode, string $adresse): void
    {
        $this->commePersonnel($this->medecinActive()->user)->{$methode}($adresse)->assertForbidden();
    }

    #[DataProvider('ecransEtActions')]
    public function test_un_agent_de_caisse_est_refuse(string $methode, string $adresse): void
    {
        $this->commePersonnel($this->personnelActive('caisse'))->{$methode}($adresse)->assertForbidden();
    }

    #[DataProvider('ecransEtActions')]
    public function test_un_administrateur_technique_est_refuse(string $methode, string $adresse): void
    {
        $this->commePersonnel($this->personnelActive('admin_technique'))->{$methode}($adresse)->assertForbidden();
    }

    #[DataProvider('ecransEtActions')]
    public function test_un_administrateur_sans_second_facteur_dans_la_session_est_refuse(string $methode, string $adresse): void
    {
        $this->actingAs($this->admin)->{$methode}($adresse)->assertRedirect(route('personnel.connexion'));
        $this->assertGuest();
    }

    public function test_les_ecrans_s_ouvrent_pour_l_administrateur_fonctionnel(): void
    {
        foreach ([
            route('personnel.admin.personnel') => 'Personnel/Admin/Personnel/Liste',
            route('personnel.admin.personnel.nouveau') => 'Personnel/Admin/Personnel/Nouveau',
            route('personnel.admin.personnel.fiche', $this->admin->id) => 'Personnel/Admin/Personnel/Fiche',
            route('personnel.admin.journal') => 'Personnel/Admin/Journal',
        ] as $adresse => $ecran) {
            $this->commePersonnel($this->admin)->get($adresse)->assertOk()->assertInertia(fn ($p) => $p->component($ecran));
        }
    }

    public function test_aucune_action_refusee_n_ecrit_au_journal(): void
    {
        $caisse = $this->personnelActive('caisse');
        $cible = $this->personnelActive('support');

        $this->commePersonnel($caisse)->post(route('personnel.admin.personnel.desactiver', $cible->id), ['motif' => 'X'])->assertForbidden();

        $this->assertSame(0, EntreeJournalAdministration::count());
        $this->assertNull($cible->fresh()->desactive_le);
    }
}
