<?php

namespace Tests\Feature;

use App\Enums\StatutRendezVous;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ParcoursRendezVous;
use Tests\Support\PersonnelDeTest;
use Tests\TestCase;

/**
 * Refonte du design : thème mémorisé (clair, repos, sombre) posé par le
 * serveur avant tout affichage, et rôle affiché dans le bandeau. Seules des
 * données d'affichage : jamais le dossier médical.
 */
class ApparenceTest extends TestCase
{
    use ParcoursRendezVous, PersonnelDeTest, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesSeeder::class);
    }

    public function test_le_theme_clair_par_defaut(): void
    {
        $this->get('/')->assertOk()->assertSee('data-theme="clair"', false);
    }

    public function test_le_theme_choisi_est_pose_avant_l_affichage(): void
    {
        foreach (['repos', 'sombre', 'clair'] as $theme) {
            $this->withUnencryptedCookie('medihome_theme', $theme)->get('/')
                ->assertOk()->assertSee('data-theme="'.$theme.'"', false);
        }
    }

    public function test_un_theme_inconnu_retombe_sur_le_clair_sans_rien_injecter(): void
    {
        $this->withUnencryptedCookie('medihome_theme', '"><script>alert(1)</script>')->get('/')
            ->assertOk()->assertSee('data-theme="clair"', false)->assertDontSee('<script>alert(1)', false);
    }

    public function test_le_bandeau_recoit_le_nom_et_le_role_rien_de_medical(): void
    {
        $patient = $this->patient();
        $patient->forceFill(['prenom' => 'Grâce', 'nom' => 'Mbuyi'])->save();

        $this->actingAs($patient)->get('/espace')->assertInertia(fn ($page) => $page
            ->where('utilisateur', ['prenom' => 'Grâce', 'nom' => 'Mbuyi', 'role' => 'Patient', 'espace' => 'patient']));
    }

    public function test_le_role_du_personnel_est_affiche(): void
    {
        $medecin = $this->medecinActive();
        $this->commePersonnel($medecin->user)->get('/personnel/medecin')
            ->assertInertia(fn ($page) => $page->where('utilisateur.role', 'Médecin')->where('utilisateur.espace', 'medecin'));

        $this->commePersonnel($this->personnelActive('caisse'))->get('/personnel/caisse')
            ->assertInertia(fn ($page) => $page->where('utilisateur.role', 'Caisse')->where('utilisateur.espace', 'caisse'));

        $this->commePersonnel($this->personnelActive('admin_fonctionnel'))->get('/personnel/admin')
            ->assertInertia(fn ($page) => $page->where('utilisateur.role', 'Administration')->where('utilisateur.espace', 'admin'));
    }

    public function test_l_espace_patient_montre_le_prochain_rendez_vous_confirme_sans_le_motif(): void
    {
        $this->voyagerAuLundi();
        $patient = $this->patient();
        $medecin = $this->medecin();
        $this->actingAs($patient)->get('/espace')->assertInertia(fn ($page) => $page->where('prochainRendezVous', null));

        $this->rendezVousExistant($medecin, $patient, $this->lundi('07:00')->subDay(), statut: StatutRendezVous::Termine);
        $this->rendezVousExistant($medecin, $patient, $this->lundi('09:00'), statut: StatutRendezVous::AnnuleParPatient);
        $suivant = $this->rendezVousExistant($medecin, $patient, $this->lundi('10:00'));
        $this->rendezVousExistant($medecin, $patient, $this->lundi('11:00'));
        $this->rendezVousExistant($medecin, $this->patient(), $this->lundi('08:00'));

        $this->actingAs($patient)->get('/espace')->assertInertia(fn ($page) => $page
            ->where('prochainRendezVous.id', $suivant->id)
            ->where('prochainRendezVous.heure', '10:00')
            ->has('prochainRendezVous.jour')->has('prochainRendezVous.medecin')
            ->missing('prochainRendezVous.motif'));
    }

    public function test_un_visiteur_n_a_pas_de_bandeau_personnel(): void
    {
        $this->get('/')->assertInertia(fn ($page) => $page->where('utilisateur', null));
    }
}
