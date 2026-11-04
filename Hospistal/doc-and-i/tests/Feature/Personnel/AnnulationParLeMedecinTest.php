<?php

namespace Tests\Feature\Personnel;

use App\Enums\StatutRendezVous;
use App\Sms\EnvoyeurSms;
use Database\Seeders\ReferentielMedicalSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\EnvoyeurSmsFactice;
use Tests\Support\ParcoursRendezVous;
use Tests\Support\PersonnelDeTest;
use Tests\TestCase;

/** RG-05 depuis l'agenda : le médecin annule un rendez-vous confirmé. */
class AnnulationParLeMedecinTest extends TestCase
{
    use ParcoursRendezVous, PersonnelDeTest, RefreshDatabase;

    private EnvoyeurSmsFactice $sms;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesSeeder::class, ReferentielMedicalSeeder::class]);
        $this->sms = new EnvoyeurSmsFactice;
        $this->app->instance(EnvoyeurSms::class, $this->sms);
        $this->voyagerAuLundi('07:00');
    }

    private function annuler($medecin, $rdv, ?string $motif = 'Urgence au bloc opératoire')
    {
        return $this->commePersonnel($medecin->user)
            ->post(route('personnel.medecin.rendez-vous.annuler', $rdv), array_filter(['motif' => $motif]));
    }

    public function test_le_medecin_annule_un_rendez_vous_confirme_rembourse_integralement(): void
    {
        $medecin = $this->medecinActive();
        $rdv = $this->rendezVousExistant($medecin, $this->patient(), $this->lundi('10:00'));

        $this->annuler($medecin, $rdv)->assertRedirect(route('personnel.medecin.agenda'))->assertSessionHas('succes');

        $rdv->refresh();
        $this->assertSame(StatutRendezVous::AnnuleParMedecin, $rdv->statut);
        $this->assertSame(100, $rdv->taux_remboursement);
        $this->assertSame('ouverte', $rdv->demande->fresh()->statut, 'Le patient peut choisir un autre créneau.');
    }

    public function test_le_patient_est_prevenu_par_sms_sans_aucun_contenu_medical(): void
    {
        $medecin = $this->medecinActive();
        $patient = $this->patient();
        $rdv = $this->rendezVousExistant($medecin, $patient, $this->lundi('10:00'));

        $this->annuler($medecin, $rdv, 'Suspicion de paludisme grave chez un autre patient');

        $this->assertCount(1, $this->sms->envoyes);
        $this->assertSame($patient->telephone, $this->sms->envoyes[0]['telephone']);
        $message = $this->sms->envoyes[0]['message'];
        $this->assertStringContainsString('07/12 a 10:00', $message);
        $this->assertStringNotContainsString('paludisme', $message);
        $this->assertStringNotContainsString($rdv->demande->motif, $message);
        $this->assertStringNotContainsString($medecin->specialite->nom, $message);
    }

    public function test_une_panne_sms_n_empeche_pas_l_annulation_ni_n_affiche_d_erreur(): void
    {
        $this->app->instance(EnvoyeurSms::class, new class implements EnvoyeurSms
        {
            public function envoyer(string $telephone, string $message): void
            {
                throw new \RuntimeException('Fournisseur SMS indisponible');
            }
        });
        $medecin = $this->medecinActive();
        $rdv = $this->rendezVousExistant($medecin, $this->patient(), $this->lundi('10:00'));

        $this->annuler($medecin, $rdv)
            ->assertRedirect(route('personnel.medecin.agenda'))
            ->assertSessionHas('succes', fn ($m) => str_contains($m, 'n\'a pas pu partir'));

        $this->assertSame(StatutRendezVous::AnnuleParMedecin, $rdv->fresh()->statut);
    }

    public function test_le_motif_est_exige(): void
    {
        $medecin = $this->medecinActive();
        $rdv = $this->rendezVousExistant($medecin, $this->patient(), $this->lundi('10:00'));

        $this->annuler($medecin, $rdv, null)->assertSessionHasErrors('motif');

        $this->assertSame(StatutRendezVous::Confirme, $rdv->fresh()->statut);
        $this->assertSame([], $this->sms->envoyes);
    }

    public function test_un_rendez_vous_en_attente_de_paiement_ne_s_annule_pas_depuis_l_agenda(): void
    {
        $medecin = $this->medecinActive();
        $rdv = $this->rendezVousExistant($medecin, $this->patient(), $this->lundi('10:00'), statut: StatutRendezVous::EnAttentePaiement);

        $this->annuler($medecin, $rdv)->assertSessionHasErrors('motif');

        $this->assertSame(StatutRendezVous::EnAttentePaiement, $rdv->fresh()->statut);
    }

    public function test_un_second_envoi_du_formulaire_est_refuse_proprement_et_un_seul_sms_part(): void
    {
        $medecin = $this->medecinActive();
        $rdv = $this->rendezVousExistant($medecin, $this->patient(), $this->lundi('10:00'));

        $this->annuler($medecin, $rdv);
        $this->annuler($medecin, $rdv)->assertSessionHasErrors('motif');

        $this->assertCount(1, $this->sms->envoyes);
    }

    public function test_un_medecin_n_annule_pas_le_rendez_vous_d_un_confrere(): void
    {
        $confrere = $this->medecinActive();
        $rdv = $this->rendezVousExistant($confrere, $this->patient(), $this->lundi('10:00'));

        $this->annuler($this->medecinActive(), $rdv)->assertNotFound();

        $this->assertSame(StatutRendezVous::Confirme, $rdv->fresh()->statut);
    }

    public function test_ni_un_patient_ni_un_autre_role_ni_une_session_sans_second_facteur_n_annulent(): void
    {
        $medecin = $this->medecinActive();
        $patient = $this->patient();
        $rdv = $this->rendezVousExistant($medecin, $patient, $this->lundi('10:00'));
        $url = route('personnel.medecin.rendez-vous.annuler', $rdv);

        $this->actingAs($patient)->post($url, ['motif' => 'x'])->assertForbidden();
        $this->commePersonnel($this->personnelActive('caisse'))->post($url, ['motif' => 'x'])->assertForbidden();
        $this->actingAs($medecin->user)->post($url, ['motif' => 'x'])->assertRedirect();

        $this->assertSame(StatutRendezVous::Confirme, $rdv->fresh()->statut);
    }

    public function test_l_agenda_ne_propose_l_annulation_que_pour_les_rendez_vous_confirmes_a_venir(): void
    {
        $medecin = $this->medecinActive();
        $confirme = $this->rendezVousExistant($medecin, $this->patient(), $this->lundi('10:00'));
        $enAttente = $this->rendezVousExistant($medecin, $this->patient(), $this->lundi('11:00'), statut: StatutRendezVous::EnAttentePaiement);

        $this->commePersonnel($medecin->user)->get(route('personnel.medecin.agenda'))
            ->assertInertia(fn ($page) => $page
                ->where('rendezVous.0.id', $confirme->id)->where('rendezVous.0.annulable', true)
                ->where('rendezVous.1.id', $enAttente->id)->where('rendezVous.1.annulable', false));
    }
}
