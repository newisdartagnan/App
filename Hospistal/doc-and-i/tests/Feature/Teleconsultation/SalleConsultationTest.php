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
use Illuminate\Testing\TestResponse;
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

    private function battementPatient(): TestResponse
    {
        return $this->actingAs($this->patient)->postJson(route('consultation.presence', $this->rdv));
    }

    private function battementMedecin(): TestResponse
    {
        return $this->commePersonnel($this->medecin->user)->postJson(route('personnel.medecin.consultation.presence', $this->rdv->id));
    }

    public function test_ouvrir_les_deux_pages_ne_commence_pas_la_consultation_les_battements_si(): void
    {
        $this->actingAs($this->patient)->get(route('consultation', $this->rdv));

        $this->commePersonnel($this->medecin->user)->get($this->salleMedecin())
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Personnel/Medecin/Consultation')
                ->where('rendezVous.patient', 'Jean KASONGO')
                ->where('appel.role', 'medecin')
                ->where('appel.signaux', route('personnel.medecin.consultation.signaux', $this->rdv->id))
                ->where('appel.presence', route('personnel.medecin.consultation.presence', $this->rdv->id))
                ->where('appel.presenceIntervalleMs', 15000));

        $this->assertSame(StatutConsultation::EnAttente, Consultation::sole()->statut, 'Ouvrir la page ne prouve pas l\'appel.');

        // Les deux navigateurs connectés battent : la consultation commence.
        $this->battementPatient()->assertOk()->assertJson(['statut' => 'en_attente', 'ensemble' => false]);
        $this->battementMedecin()->assertOk()->assertJson(['statut' => 'en_cours', 'ensemble' => true, 'secondes_ensemble' => 0]);
        $this->assertSame(StatutConsultation::EnCours, Consultation::sole()->statut);

        // Le temps passé ensemble augmente à chaque battement.
        $this->travel(15)->seconds();
        $this->battementPatient()->assertOk()->assertJson(['secondes_ensemble' => 15]);
    }

    public function test_les_reponses_de_presence_ne_sont_pas_mises_en_cache_et_un_tiers_est_refuse(): void
    {
        $this->actingAs($this->patient)->get(route('consultation', $this->rdv));

        $this->assertStringContainsString('no-store', $this->battementPatient()->headers->get('Cache-Control'));
        $refus = $this->actingAs($this->patient())->postJson(route('consultation.presence', $this->rdv))->assertForbidden();
        $this->assertStringContainsString('no-store', $refus->headers->get('Cache-Control'));
        $this->commePersonnel($this->medecinActive()->user)->postJson(route('personnel.medecin.consultation.presence', $this->rdv->id))->assertForbidden();
        $this->commePersonnel($this->personnelActive('caisse'))->postJson(route('personnel.medecin.consultation.presence', $this->rdv->id))->assertForbidden();

        $this->travelTo($this->lundi('10:21'));
        $this->battementPatient()->assertForbidden();
        $this->assertSame('08:55', Consultation::sole()->presence_patient_le->format('H:i'), 'Hors fenêtre : rien n\'est noté.');
    }

    public function test_sans_salle_ouverte_le_battement_est_refuse(): void
    {
        $this->battementPatient()->assertForbidden()->assertJson(['message' => 'La salle de consultation n\'est pas encore ouverte.']);
    }

    public function test_le_battement_a_sa_limite_de_debit(): void
    {
        $this->actingAs($this->patient)->get(route('consultation', $this->rdv));
        $route = app('router')->getRoutes()->getByName('consultation.presence');
        $this->assertContains('throttle:presence-consultation', $route->gatherMiddleware());
        $this->assertContains('throttle:presence-consultation', app('router')->getRoutes()->getByName('personnel.medecin.consultation.presence')->gatherMiddleware());

        foreach (range(1, 12) as $i) {
            $this->battementPatient()->assertOk();
        }
        $this->battementPatient()->assertStatus(429);
    }

    public function test_les_pages_des_salles_ont_une_limite_de_debit(): void
    {
        foreach (['consultation', 'personnel.medecin.consultation'] as $nom) {
            $this->assertContains('throttle:salle-consultation', app('router')->getRoutes()->getByName($nom)->gatherMiddleware());
        }

        foreach (range(1, 20) as $i) {
            $this->actingAs($this->patient)->get(route('consultation', $this->rdv))->assertOk();
        }
        $this->actingAs($this->patient)->get(route('consultation', $this->rdv))->assertStatus(429);
    }

    public function test_une_page_rechargee_juste_apres_une_offre_attend_le_reste_du_delai(): void
    {
        $this->commePersonnel($this->medecin->user)->get($this->salleMedecin())
            ->assertInertia(fn ($page) => $page->where('appel.attenteOffreMs', 0)->where('appel.intervalleOffreMs', 3500));

        $this->commePersonnel($this->medecin->user)
            ->postJson(route('personnel.medecin.consultation.signaux.envoyer', $this->rdv->id), ['type' => 'offre', 'charge' => ['type' => 'offer', 'sdp' => "v=0\r\n"]])
            ->assertCreated();
        $this->travel(1)->seconds();

        // Rechargement 1 s après l'offre : 2 s restantes (plus une marge) avant la suivante.
        $this->commePersonnel($this->medecin->user)->get($this->salleMedecin())
            ->assertInertia(fn ($page) => $page->where('appel.attenteOffreMs', 2500));

        $this->travel(5)->seconds();
        $this->commePersonnel($this->medecin->user)->get($this->salleMedecin())
            ->assertInertia(fn ($page) => $page->where('appel.attenteOffreMs', 0));
    }

    public function test_avec_un_relais_turn_tout_passe_par_le_relais(): void
    {
        $this->actingAs($this->patient)->get(route('consultation', $this->rdv))
            ->assertInertia(fn ($page) => $page->where('appel.iceTransportPolicy', 'all'));

        config(['doc-and-i.teleconsultation.stun' => 'stun:turn.medihome.cd:3478', 'doc-and-i.teleconsultation.turn' => 'turn:turn.medihome.cd:3478',
            'doc-and-i.teleconsultation.turn_secret' => str_repeat('s', 40)]);
        $this->actingAs($this->patient)->get(route('consultation', $this->rdv))
            ->assertInertia(fn ($page) => $page->where('appel.iceTransportPolicy', 'relay'));
        $this->commePersonnel($this->medecin->user)->get($this->salleMedecin())
            ->assertInertia(fn ($page) => $page->where('appel.iceTransportPolicy', 'relay'));

        config(['doc-and-i.teleconsultation.relais_seulement' => false]);
        $this->actingAs($this->patient)->get(route('consultation', $this->rdv))
            ->assertInertia(fn ($page) => $page->where('appel.iceTransportPolicy', 'all'));

        // STUN seul : pas de relais, connexion directe.
        config(['doc-and-i.teleconsultation.relais_seulement' => true, 'doc-and-i.teleconsultation.turn' => '']);
        $this->actingAs($this->patient)->get(route('consultation', $this->rdv))
            ->assertInertia(fn ($page) => $page->where('appel.iceTransportPolicy', 'all'));
    }

    public function test_revenir_dans_la_salle_ne_relit_pas_les_anciens_signaux_et_compte_la_reconnexion(): void
    {
        $this->actingAs($this->patient)->get(route('consultation', $this->rdv));
        $this->commePersonnel($this->medecin->user)->get($this->salleMedecin());
        $this->battementPatient()->assertOk();
        $this->battementMedecin()->assertJson(['statut' => 'en_cours']);
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
