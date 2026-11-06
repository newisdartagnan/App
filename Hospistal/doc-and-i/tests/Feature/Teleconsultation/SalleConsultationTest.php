<?php

namespace Tests\Feature\Teleconsultation;

use App\Enums\StatutConsultation;
use App\Enums\StatutRendezVous;
use App\Models\Consultation;
use App\Models\Medecin;
use App\Models\RendezVous;
use App\Models\User;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ParcoursRendezVous;
use Tests\Support\PersonnelDeTest;
use Tests\TestCase;

/** TLC-01, TLC-06 : pages de la salle, côté patient et côté médecin, et boutons « Rejoindre ». */
class SalleConsultationTest extends TestCase
{
    use ParcoursRendezVous, PersonnelDeTest, RefreshDatabase;

    private User $patient;

    private Medecin $medecin;

    private RendezVous $rdv;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesSeeder::class);
        $this->voyagerAuLundi('08:55');
        $this->patient = $this->patient();
        $this->medecin = $this->medecinActive();
        $this->medecin->user->forceFill(['prenom' => 'Pauline', 'nom' => 'MUKENDI'])->save();
        $this->patient->forceFill(['prenom' => 'Jean', 'nom' => 'KASONGO'])->save();
        $this->rdv = $this->rendezVousExistant($this->medecin, $this->patient, $this->lundi('09:00'));
    }

    private function salleMedecin(?RendezVous $rdv = null): string
    {
        return route('personnel.medecin.consultation', ($rdv ?? $this->rdv)->id);
    }

    public function test_le_patient_entre_dans_sa_salle(): void
    {
        $this->actingAs($this->patient)->get(route('consultation', $this->rdv))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Teleconsultation/Salle')
                ->where('rendezVous.medecin', 'Dr Pauline MUKENDI')
                ->where('appel.role', 'patient')
                ->where('appel.signaux', route('consultation.signaux', $this->rdv))
                ->where('appel.iceServers', [])
                ->where('appel.dernierSignal', 0)
                ->where('appel.intervalleMs', 1500)
                ->where('appel.seuilVideoKbps', 150));

        $consultation = Consultation::sole();
        $this->assertNotNull($consultation->patient_rejoint_le);
        $this->assertSame(StatutConsultation::EnAttente, $consultation->statut);
    }

    public function test_le_medecin_entre_et_la_consultation_commence(): void
    {
        $this->actingAs($this->patient)->get(route('consultation', $this->rdv));

        $this->commePersonnel($this->medecin->user)->get($this->salleMedecin())
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Personnel/Medecin/Consultation')
                ->where('rendezVous.patient', 'Jean KASONGO')
                ->where('appel.role', 'medecin')
                ->where('appel.signaux', route('personnel.medecin.consultation.signaux', $this->rdv->id)));

        $this->assertSame(StatutConsultation::EnCours, Consultation::sole()->statut);
    }

    public function test_revenir_dans_la_salle_ne_relit_pas_les_anciens_signaux_et_compte_la_reconnexion(): void
    {
        $this->actingAs($this->patient)->get(route('consultation', $this->rdv));
        $this->commePersonnel($this->medecin->user)->get($this->salleMedecin());
        $id = $this->commePersonnel($this->medecin->user)
            ->postJson(route('personnel.medecin.consultation.signaux.envoyer', $this->rdv->id), ['type' => 'raccroche', 'charge' => []])
            ->json('id');

        // TLC-04 : le patient recharge la page.
        $this->actingAs($this->patient)->get(route('consultation', $this->rdv))
            ->assertInertia(fn ($page) => $page->where('appel.dernierSignal', $id));

        $this->assertSame(1, Consultation::sole()->reconnexions);
    }

    public function test_les_serveurs_turn_ephemeres_sont_donnes_a_la_page(): void
    {
        config(['doc-and-i.teleconsultation.stun' => 'stun:turn.medihome.cd:3478', 'doc-and-i.teleconsultation.turn' => 'turn:turn.medihome.cd:3478',
            'doc-and-i.teleconsultation.turn_secret' => str_repeat('s', 40)]);

        $this->actingAs($this->patient)->get(route('consultation', $this->rdv))
            ->assertInertia(fn ($page) => $page
                ->where('appel.iceServers.0.urls', ['stun:turn.medihome.cd:3478'])
                ->where('appel.iceServers.1.username', fn ($u) => str_ends_with($u, ':'.$this->patient->id))
                ->has('appel.iceServers.1.credential'));
    }

    public function test_un_autre_patient_ou_le_personnel_n_entrent_pas_dans_la_salle_du_patient(): void
    {
        $this->actingAs($this->patient())->get(route('consultation', $this->rdv))->assertNotFound();
        $this->commePersonnel($this->medecin->user)->get(route('consultation', $this->rdv))->assertForbidden();
        $this->commePersonnel($this->personnelActive('caisse'))->get(route('consultation', $this->rdv))->assertForbidden();
        auth()->logout();
        $this->get(route('consultation', $this->rdv))->assertRedirect(route('connexion'));

        $this->assertSame(0, Consultation::count());
    }

    public function test_seul_le_medecin_du_rendez_vous_entre_dans_la_salle_medecin(): void
    {
        $this->commePersonnel($this->medecinActive()->user)->get($this->salleMedecin())->assertNotFound();
        foreach (['caisse', 'support', 'admin_fonctionnel'] as $role) {
            $this->commePersonnel($this->personnelActive($role))->get($this->salleMedecin())->assertForbidden();
        }
        $this->actingAs($this->patient)->get($this->salleMedecin())->assertForbidden();
        // Sans le second facteur dans la session : pas d'accès.
        $this->actingAs($this->medecin->user)->get($this->salleMedecin())->assertRedirect('/personnel/connexion');

        $this->assertSame(0, Consultation::count());
    }

    public function test_hors_de_la_fenetre_ou_sans_confirmation_le_participant_est_renvoye_avec_la_raison(): void
    {
        $this->travelTo($this->lundi('08:30'));
        $this->actingAs($this->patient)->get(route('consultation', $this->rdv))
            ->assertRedirect(route('rdv.voir', $this->rdv))
            ->assertSessionHasErrors(['consultation']);
        $this->commePersonnel($this->medecin->user)->get($this->salleMedecin())
            ->assertRedirect(route('personnel.medecin.agenda'))
            ->assertSessionHasErrors(['consultation']);

        $this->travelTo($this->lundi('08:55'));
        $attente = $this->rendezVousExistant($this->medecin, $this->patient, $this->lundi('09:20'), 20, StatutRendezVous::EnAttentePaiement);
        $this->actingAs($this->patient)->get(route('consultation', $attente))->assertSessionHasErrors(['consultation']);

        $this->assertSame(0, Consultation::count());
    }

    public function test_le_bouton_rejoindre_du_patient_n_apparait_que_dans_la_fenetre(): void
    {
        $this->actingAs($this->patient)->get(route('rdv.voir', $this->rdv))
            ->assertInertia(fn ($page) => $page->where('actions.rejoindre', true));

        $this->travelTo($this->lundi('08:30'));
        $this->get(route('rdv.voir', $this->rdv))->assertInertia(fn ($page) => $page->where('actions.rejoindre', false));

        $this->travelTo($this->lundi('08:55'));
        $attente = $this->rendezVousExistant($this->medecin, $this->patient, $this->lundi('09:20'), 20, StatutRendezVous::EnAttentePaiement);
        $this->get(route('rdv.voir', $attente))->assertInertia(fn ($page) => $page->where('actions.rejoindre', false));
    }

    public function test_les_pages_gardent_la_csp_sans_autre_origine_et_l_appel_n_est_jamais_enregistre(): void
    {
        $reponse = $this->actingAs($this->patient)->get(route('consultation', $this->rdv));

        $csp = $reponse->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("connect-src 'self'", $csp);
        $this->assertStringContainsString('camera=(self), microphone=(self)', $reponse->headers->get('Permissions-Policy'));

        $composant = file_get_contents(resource_path('js/Composants/AppelVideo.vue'));
        $this->assertStringNotContainsString('MediaRecorder', $composant, 'TLC-06 : aucun enregistrement.');
        $this->assertStringContainsString('jamais enregistré', $composant);
        $this->assertDoesNotMatchRegularExpression('#https?://#', $composant, 'Aucune ressource d\'un autre domaine.');
    }
}
