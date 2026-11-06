<?php

namespace Tests\Feature\Teleconsultation;

use App\Enums\MotifRemboursement;
use App\Enums\StatutConsultation;
use App\Enums\StatutRemboursement;
use App\Enums\StatutRendezVous;
use App\Models\Consultation;
use App\Models\Remboursement;
use App\Models\Reversement;
use App\Services\Administration\PeriodeTableau;
use App\Services\Administration\TableauDeBordService;
use App\Services\Caisse\ReversementService;
use App\Services\Paiement\FacturationService;
use App\Services\Paiement\JournalCaisse;
use App\Services\Paiement\RemboursementService;
use App\Services\Teleconsultation\AccesConsultation;
use App\Services\Teleconsultation\AccesRefuse;
use App\Services\Teleconsultation\FinConsultation;
use App\Sms\EnvoyeurSms;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\Support\EnvoyeurSmsFactice;
use Tests\Support\ParcoursConsultation;
use Tests\Support\ParcoursPaiement;
use Tests\Support\ParcoursRendezVous;
use Tests\Support\PersonnelDeTest;
use Tests\TestCase;

/** RG-06, RG-07 : fin de consultation et absences, sous verrou, avec leurs effets. */
class FinConsultationTest extends TestCase
{
    use ParcoursConsultation, ParcoursPaiement, ParcoursRendezVous, PersonnelDeTest, RefreshDatabase;

    private EnvoyeurSmsFactice $sms;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesSeeder::class);
        $this->preparerConsultation();
        $this->sms = new EnvoyeurSmsFactice;
        $this->app->instance(EnvoyeurSms::class, $this->sms);
    }

    private function terminer(): TestResponse
    {
        return $this->commeMedecinRdv()->postJson($this->adresse('personnel.medecin.consultation.terminer'));
    }

    private function patientAbsent(): TestResponse
    {
        return $this->commeMedecinRdv()->postJson($this->adresse('personnel.medecin.consultation.patient-absent'));
    }

    private function medecinAbsent(): TestResponse
    {
        return $this->commePatientRdv()->postJson($this->adresse('consultation.medecin-absent'));
    }

    // --- Terminer --------------------------------------------------------------------------

    public function test_le_medecin_termine_une_consultation_en_cours(): void
    {
        $this->entrerTousLesDeux();
        $this->travelTo($this->lundi('09:18'));

        $this->terminer()->assertOk()->assertJsonPath('statut', 'terminee')->assertJsonPath('actions', []);

        $consultation = Consultation::sole();
        $this->assertSame(StatutConsultation::Terminee, $consultation->statut);
        $this->assertTrue($consultation->fin_le->equalTo(now()));
        $this->assertSame(StatutRendezVous::Termine, $this->rdv->fresh()->statut);
    }

    public function test_terminer_deux_fois_est_refuse_proprement(): void
    {
        $this->entrerTousLesDeux();
        $this->terminer()->assertOk();

        $this->terminer()->assertForbidden()->assertJsonPath('message', 'Cette consultation est déjà close (terminée).');
        $this->assertSame(StatutRendezVous::Termine, $this->rdv->fresh()->statut);
    }

    public function test_une_salle_en_attente_ne_se_termine_pas(): void
    {
        $this->entrer($this->medecinRdv->user);

        $this->terminer()->assertForbidden();
        $this->assertSame(StatutConsultation::EnAttente, Consultation::sole()->statut);
        $this->assertSame(StatutRendezVous::Confirme, $this->rdv->fresh()->statut);
    }

    public function test_le_patient_ne_termine_pas_la_consultation(): void
    {
        $this->entrerTousLesDeux();

        $this->expectException(AccesRefuse::class);
        app(FinConsultation::class)->terminer($this->patientRdv, $this->rdv);
    }

    // --- Patient absent ------------------------------------------------------------------------

    public function test_le_medecin_constate_l_absence_du_patient_dix_minutes_apres_l_heure_sans_remboursement(): void
    {
        $this->paiementExistant($this->rdv);
        $this->entrer($this->medecinRdv->user);

        $this->travelTo($this->lundi('09:09'));
        $this->patientAbsent()->assertForbidden();

        $this->travelTo($this->lundi('09:10'));
        $this->patientAbsent()->assertOk()->assertJsonPath('statut', 'patient_absent');

        $this->assertSame(StatutConsultation::PatientAbsent, Consultation::sole()->statut);
        $this->assertSame(StatutRendezVous::AbsencePatient, $this->rdv->fresh()->statut);
        $this->assertSame(0, Remboursement::count(), 'RG-04 : pas de remboursement.');
        $this->assertSame([], $this->sms->envoyes);
    }

    public function test_un_patient_venu_n_est_pas_absent(): void
    {
        $this->entrerTousLesDeux();
        $this->travelTo($this->lundi('09:15'));

        $this->patientAbsent()->assertForbidden();
        $this->assertSame(StatutConsultation::EnCours, Consultation::sole()->statut);
    }

    public function test_l_absence_du_patient_se_constate_une_seule_fois(): void
    {
        $this->entrer($this->medecinRdv->user);
        $this->travelTo($this->lundi('09:12'));

        $this->patientAbsent()->assertOk();
        $this->patientAbsent()->assertForbidden();
        $this->assertSame(StatutRendezVous::AbsencePatient, $this->rdv->fresh()->statut);
    }

    public function test_un_medecin_jamais_entre_ne_constate_pas_l_absence_du_patient(): void
    {
        $this->travelTo($this->lundi('09:12'));

        $this->patientAbsent()->assertForbidden();
        $this->assertSame(0, Consultation::count());
    }

    // --- Médecin absent ---------------------------------------------------------------------------

    public function test_le_patient_constate_l_absence_du_medecin_et_est_rembourse_integralement(): void
    {
        $paiement = $this->paiementExistant($this->rdv);
        $this->entrer($this->patientRdv);

        $this->travelTo($this->lundi('09:09'));
        $this->medecinAbsent()->assertForbidden();
        $this->travelTo($this->lundi('09:10'));
        $this->medecinAbsent()->assertOk()->assertJsonPath('statut', 'medecin_absent');

        $this->assertSame(StatutConsultation::MedecinAbsent, Consultation::sole()->statut);
        $this->assertSame(StatutRendezVous::AbsenceMedecin, $this->rdv->fresh()->statut);
        $r = Remboursement::sole();
        $this->assertSame([$paiement->id, MotifRemboursement::AbsenceMedecin, '10.00', StatutRemboursement::Valide, true],
            [$r->paiement_id, $r->motif, (string) $r->montant, $r->statut, $r->automatique]);
        $this->assertSame([[
            'telephone' => $this->patientRdv->telephone,
            'message' => 'MEDIHOME : le medecin n\'a pas pu vous recevoir le 07/12 a 09:00. Vous serez rembourse integralement ; ouvrez l\'application pour reprendre rendez-vous.',
        ]], $this->sms->envoyes);
    }

    public function test_l_absence_du_medecin_constatee_deux_fois_ne_rembourse_qu_une_fois(): void
    {
        $this->paiementExistant($this->rdv);
        $this->entrer($this->patientRdv);
        $this->travelTo($this->lundi('09:15'));

        $this->medecinAbsent()->assertOk();
        $this->medecinAbsent()->assertForbidden();

        $this->assertSame(1, Remboursement::count());
        $this->assertCount(1, $this->sms->envoyes);
    }

    public function test_un_medecin_venu_n_est_pas_absent(): void
    {
        $this->entrerTousLesDeux();
        $this->travelTo($this->lundi('09:15'));

        $this->medecinAbsent()->assertForbidden();
        $this->assertSame(0, Remboursement::count());
    }

    public function test_un_remboursement_deja_partiel_est_complete_sans_depasser_le_paye(): void
    {
        $paiement = $this->paiementExistant($this->rdv);
        app(RemboursementService::class)->gesteCommercial($paiement, '3.00', $this->personnelActive('caisse'), 'Attente');
        $this->entrer($this->patientRdv);
        $this->travelTo($this->lundi('09:15'));

        $this->medecinAbsent()->assertOk();

        $this->assertSame('7.00', (string) Remboursement::where('motif', MotifRemboursement::AbsenceMedecin->value)->sole()->montant);
        $this->assertSame('10.00', (string) $paiement->fresh()->montant_rembourse);
    }

    public function test_une_panne_sms_ne_defait_pas_l_absence_et_le_journal_n_a_pas_le_numero(): void
    {
        Log::spy();
        $this->app->instance(EnvoyeurSms::class, new class implements EnvoyeurSms
        {
            public function envoyer(string $telephone, string $message): void
            {
                throw new RuntimeException('Passerelle indisponible pour '.$telephone);
            }
        });
        $this->paiementExistant($this->rdv);
        $this->entrer($this->patientRdv);
        $this->travelTo($this->lundi('09:15'));

        $this->medecinAbsent()->assertOk();

        $this->assertSame(StatutRendezVous::AbsenceMedecin, $this->rdv->fresh()->statut);
        Log::shouldHaveReceived('warning')->withArgs(fn ($message, $contexte) => array_keys($contexte) === ['rendez_vous', 'erreur']
            && ! str_contains(json_encode($contexte), substr($this->patientRdv->telephone, 4)));
    }

    // --- État proposé aux composants -----------------------------------------------------------------

    public function test_l_etat_propose_les_actions_selon_le_role_et_l_heure(): void
    {
        $this->entrer($this->patientRdv);

        $this->commePatientRdv()->getJson($this->adresse('consultation.fin'))
            ->assertOk()->assertJsonPath('role', 'patient')->assertJsonPath('statut', 'en_attente')
            ->assertJsonPath('actions', [])->assertJsonPath('absenceDisponibleA', '09:10');

        $this->travelTo($this->lundi('09:10'));
        $this->commePatientRdv()->getJson($this->adresse('consultation.fin'))->assertJsonPath('actions', ['medecin_absent']);
        // Le médecin, jamais entré, n'a rien à constater.
        $this->commeMedecinRdv()->getJson($this->adresse('personnel.medecin.consultation.fin'))->assertJsonPath('actions', []);

        $this->entrer($this->medecinRdv->user);
        $this->commeMedecinRdv()->getJson($this->adresse('personnel.medecin.consultation.fin'))->assertJsonPath('actions', ['terminer']);
        $this->commePatientRdv()->getJson($this->adresse('consultation.fin'))->assertJsonPath('actions', []);
    }

    // --- Tâche planifiée --------------------------------------------------------------------------------

    public function test_la_tache_conclut_les_salles_fermees_selon_qui_est_venu(): void
    {
        $personne = $this->rdv;
        $paiementPersonne = $this->paiementExistant($personne);
        $medecinSeul = $this->rendezVousExistant($this->medecinRdv, $this->patient(), $this->lundi('09:30'));
        $patientSeul = $this->rendezVousExistant($this->medecinRdv, $this->patient(), $this->lundi('10:00'));
        $tousLesDeux = $this->rendezVousExistant($this->medecinRdv, $this->patient(), $this->lundi('10:30'));
        $plusTard = $this->rendezVousExistant($this->medecinRdv, $this->patient(), $this->lundi('15:00'));

        $this->travelTo($this->lundi('09:30'));
        app(AccesConsultation::class)->entrer($this->medecinRdv->user, $medecinSeul);
        $this->travelTo($this->lundi('10:00'));
        app(AccesConsultation::class)->entrer($patientSeul->patient, $patientSeul);
        $this->travelTo($this->lundi('10:30'));
        app(AccesConsultation::class)->entrer($tousLesDeux->patient, $tousLesDeux);
        app(AccesConsultation::class)->entrer($this->medecinRdv->user, $tousLesDeux);

        // Fin de 10:30–10:50 plus 60 minutes de fermeture.
        $this->travelTo($this->lundi('11:51'));
        $this->assertSame(0, Artisan::call('teleconsultation:constater-salles-fermees'));

        $this->assertSame(StatutRendezVous::AbsenceMedecin, $personne->fresh()->statut, 'Personne n\'est venu : le médecin est réputé absent.');
        $this->assertSame(StatutRendezVous::AbsencePatient, $medecinSeul->fresh()->statut);
        $this->assertSame(StatutRendezVous::AbsenceMedecin, $patientSeul->fresh()->statut);
        $this->assertSame(StatutRendezVous::Termine, $tousLesDeux->fresh()->statut);
        $this->assertSame(StatutRendezVous::Confirme, $plusTard->fresh()->statut, 'Salle pas encore fermée.');
        $this->assertSame(1, Remboursement::where('paiement_id', $paiementPersonne->id)->where('motif', MotifRemboursement::AbsenceMedecin->value)->count());
        $this->assertCount(2, $this->sms->envoyes, 'Les deux patients dont le médecin est absent sont prévenus.');

        // Relancée, la tâche ne refait rien.
        Artisan::call('teleconsultation:constater-salles-fermees');
        $this->assertCount(2, $this->sms->envoyes);
        $this->assertSame(1, Remboursement::count());
    }

    public function test_la_tache_est_planifiee_toutes_les_cinq_minutes(): void
    {
        $evenement = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains($e->command, 'teleconsultation:constater-salles-fermees'));

        $this->assertNotNull($evenement);
        $this->assertSame('*/5 * * * *', $evenement->expression);
    }

    // --- Agenda du médecin --------------------------------------------------------------------------------

    public function test_l_agenda_montre_le_statut_de_la_consultation_a_cote_du_rendez_vous(): void
    {
        $sansSalle = $this->rendezVousExistant($this->medecinRdv, $this->patient(), $this->lundi('11:00'));
        $this->entrerTousLesDeux();

        $this->commeMedecinRdv()->get(route('personnel.medecin.agenda'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Personnel/Medecin/Agenda')
                ->where('rendezVous.0.id', $this->rdv->id)
                ->where('rendezVous.0.consultation', 'en_cours')
                ->where('rendezVous.0.consultationLibelle', 'En cours')
                ->where('rendezVous.0.rejoignable', true)
                ->where('rendezVous.1.id', $sansSalle->id)
                ->where('rendezVous.1.consultation', null)
                ->where('rendezVous.1.rejoignable', false));

        $this->terminer();
        $this->commeMedecinRdv()->get(route('personnel.medecin.agenda'))
            ->assertInertia(fn ($page) => $page->where('rendezVous.0.consultationLibelle', 'Terminée')
                ->where('rendezVous.0.statut', 'Terminé')
                ->where('rendezVous.0.rejoignable', false)
                ->whereNot('rendezVous.0.patient', null));
    }

    public function test_un_rendez_vous_devient_rejoignable_dix_minutes_avant_l_heure(): void
    {
        $this->travelTo($this->lundi('08:49'));
        $this->commeMedecinRdv()->get(route('personnel.medecin.agenda'))->assertInertia(fn ($page) => $page->where('rendezVous.0.rejoignable', false));

        $this->travelTo($this->lundi('08:50'));
        $this->commeMedecinRdv()->get(route('personnel.medecin.agenda'))->assertInertia(fn ($page) => $page->where('rendezVous.0.rejoignable', true));

        // Un rendez-vous en attente de paiement n'est jamais rejoignable.
        $this->rdv->forceFill(['statut' => StatutRendezVous::EnAttentePaiement, 'expire_le' => now()->addMinutes(15)])->save();
        $this->commeMedecinRdv()->get(route('personnel.medecin.agenda'))->assertInertia(fn ($page) => $page->where('rendezVous.0.rejoignable', false));
    }

    // --- Effets sur les reversements et le tableau de bord ---------------------------------------------

    public function test_une_consultation_terminee_compte_au_reversement_une_absence_du_medecin_non(): void
    {
        config(['doc-and-i.paiement.commission_pourcent' => '10']);
        $encaisser = function ($rdv) {
            $p = $this->paiementExistant($rdv);
            app(FacturationService::class)->emettreFacture($p);
            app(JournalCaisse::class)->encaissement($p);
        };
        $encaisser($this->rdv);
        $absent = $this->rendezVousExistant($this->medecinRdv, $this->patient(), $this->lundi('10:00'));
        $encaisser($absent);

        $this->entrerTousLesDeux();
        $this->terminer()->assertOk();
        $this->travelTo($this->lundi('10:00'));
        app(AccesConsultation::class)->entrer($absent->patient, $absent);
        $this->travelTo($this->lundi('10:12'));
        $this->actingAs($absent->patient)->postJson(route('consultation.medecin-absent', ['rendezVous' => $absent->id]))->assertOk();

        $this->travelTo(CarbonImmutable::parse('2026-12-08 08:00', 'Africa/Kinshasa'));
        [$reversement] = app(ReversementService::class)->preparer($this->medecinRdv, CarbonImmutable::parse('2026-12-01'), CarbonImmutable::parse('2026-12-07'), $this->personnelActive('caisse'));

        $this->assertSame('10.00', (string) $reversement->montant_brut, 'La consultation terminée seule ; l\'absence du médecin ne lui est pas payée.');
        $this->assertSame(1, Reversement::count());
    }

    public function test_le_tableau_de_bord_compte_les_consultations_terminees_et_les_absences(): void
    {
        $patientAbsent = $this->rendezVousExistant($this->medecinRdv, $this->patient(), $this->lundi('10:00'));
        $this->entrerTousLesDeux();
        $this->terminer();
        $this->travelTo($this->lundi('10:00'));
        app(AccesConsultation::class)->entrer($this->medecinRdv->user, $patientAbsent);
        $this->travelTo($this->lundi('10:10'));
        app(FinConsultation::class)->constaterPatientAbsent($this->medecinRdv->user, $patientAbsent);

        $tableau = app(TableauDeBordService::class)->calculer(PeriodeTableau::depuis('30j'));

        $parStatut = collect($tableau['parStatut'])->pluck('nombre', 'statut');
        $this->assertSame([1, 1], [$parStatut['termine'], $parStatut['absent_patient']]);
        $this->assertSame(1, $tableau['taux']['absencePatient']['nombre']);
    }
}
