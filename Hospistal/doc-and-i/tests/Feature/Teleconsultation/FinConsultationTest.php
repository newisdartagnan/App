<?php

namespace Tests\Feature\Teleconsultation;

use App\Enums\MotifRemboursement;
use App\Enums\StatutConsultation;
use App\Enums\StatutPaiement;
use App\Enums\StatutRemboursement;
use App\Enums\StatutRendezVous;
use App\Models\Consultation;
use App\Models\Remboursement;
use App\Models\RendezVous;
use App\Services\Administration\PeriodeTableau;
use App\Services\Administration\TableauDeBordService;
use App\Services\Caisse\ReversementService;
use App\Services\Paiement\FacturationService;
use App\Services\Paiement\JournalCaisse;
use App\Services\Paiement\PaiementRefuse;
use App\Services\Paiement\RemboursementService;
use App\Services\RendezVous\ReservationService;
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

/**
 * RG-06, RG-07 : fin de consultation et absences, sur la présence réelle
 * dans l'appel (ouvrir la page dit « venu », pas « en cours »).
 */
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

    private function interrompre(): TestResponse
    {
        return $this->commeMedecinRdv()->postJson($this->adresse('personnel.medecin.consultation.interrompre'));
    }

    private function patientAbsent(): TestResponse
    {
        return $this->commeMedecinRdv()->postJson($this->adresse('personnel.medecin.consultation.patient-absent'));
    }

    private function medecinAbsent(): TestResponse
    {
        return $this->commePatientRdv()->postJson($this->adresse('consultation.medecin-absent'));
    }

    private function encaisse(RendezVous $rdv): void
    {
        $p = $this->paiementExistant($rdv);
        app(FacturationService::class)->emettreFacture($p);
        app(JournalCaisse::class)->encaissement($p);
    }

    /** Brut du reversement du médecin pour le 7 décembre, ou 0 s'il n'y a rien à reverser. */
    private function brutReverse(): string
    {
        config(['doc-and-i.paiement.commission_pourcent' => '10']);
        $this->travelTo(CarbonImmutable::parse('2026-12-08 08:00', 'Africa/Kinshasa'));

        try {
            [$r] = app(ReversementService::class)->preparer($this->medecinRdv, CarbonImmutable::parse('2026-12-01'), CarbonImmutable::parse('2026-12-07'), $this->personnelActive('caisse'));
        } catch (PaiementRefuse) {
            return '0.00';
        }

        return (string) $r->montant_brut;
    }

    // --- Terminer : seulement après un vrai échange ----------------------------------------------

    public function test_le_medecin_termine_apres_le_temps_minimal_passe_ensemble(): void
    {
        $this->entrerTousLesDeux();
        $this->connectesEnsemble(120);

        $this->terminer()->assertOk()->assertJsonPath('statut', 'terminee')->assertJsonPath('actions', []);

        $consultation = Consultation::sole();
        $this->assertSame([StatutConsultation::Terminee, 120], [$consultation->statut, $consultation->secondes_ensemble]);
        $this->assertSame(StatutRendezVous::Termine, $this->rdv->fresh()->statut);
    }

    public function test_terminer_trop_tot_est_refuse_avec_le_temps_manquant(): void
    {
        $this->entrerTousLesDeux();
        $this->connectesEnsemble(60);

        $this->terminer()->assertForbidden()->assertJsonPath('message', fn ($m) => str_contains($m, 'Encore 1 minute(s) d\'échange'));
        $this->assertSame(StatutConsultation::EnCours, Consultation::sole()->statut);
        $this->assertSame(StatutRendezVous::Confirme, $this->rdv->fresh()->statut);
    }

    public function test_terminer_deux_fois_est_refuse_proprement(): void
    {
        $this->entrerTousLesDeux();
        $this->connectesEnsemble(150);
        $this->terminer()->assertOk();

        $this->terminer()->assertForbidden()->assertJsonPath('message', 'Cette consultation est déjà close (terminée).');
        $this->assertSame(StatutRendezVous::Termine, $this->rdv->fresh()->statut);
    }

    public function test_ouvrir_la_page_des_deux_cotes_sans_connexion_ne_permet_pas_de_terminer(): void
    {
        $this->entrerTousLesDeux();

        $this->terminer()->assertForbidden();
        $this->assertSame(StatutConsultation::EnAttente, Consultation::sole()->statut);
        $this->assertSame(StatutRendezVous::Confirme, $this->rdv->fresh()->statut);
    }

    public function test_le_patient_ne_termine_ni_n_interrompt(): void
    {
        $this->entrerTousLesDeux();
        $this->connectesEnsemble(150);

        foreach (['terminer', 'interrompre'] as $action) {
            try {
                app(FinConsultation::class)->{$action}($this->patientRdv, $this->rdv);
                $this->fail("Le patient a pu {$action}.");
            } catch (AccesRefuse) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame(StatutConsultation::EnCours, Consultation::sole()->statut);
    }

    // --- Interrompue (RG-07) --------------------------------------------------------------------------

    public function test_une_consultation_interrompue_est_remboursee_et_la_demande_rouverte(): void
    {
        $paiement = $this->paiementExistant($this->rdv);
        $this->entrerTousLesDeux();
        $this->connectesEnsemble(30);

        $this->interrompre()->assertOk()->assertJsonPath('statut', 'interrompue');

        $this->assertSame(StatutConsultation::Interrompue, Consultation::sole()->statut);
        $rdv = $this->rdv->fresh();
        $this->assertSame([StatutRendezVous::AnnuleParMedecin, FinConsultation::MOTIF_INTERRUPTION, 100], [$rdv->statut, $rdv->motif_annulation, $rdv->taux_remboursement]);
        $r = Remboursement::sole();
        $this->assertSame([$paiement->id, MotifRemboursement::ConsultationInterrompue, '10.00', StatutRemboursement::Valide, true],
            [$r->paiement_id, $r->motif, (string) $r->montant, $r->statut, $r->automatique]);
        $this->assertSame('ouverte', $rdv->demande->statut, 'Le patient reprend un créneau pour la même demande.');
        $this->assertSame([[
            'telephone' => $this->patientRdv->telephone,
            'message' => 'MEDIHOME : votre consultation du 07/12 a 09:00 a ete interrompue. Vous serez rembourse integralement ; ouvrez l\'application pour reprendre rendez-vous.',
        ]], $this->sms->envoyes);
    }

    public function test_une_salle_jamais_connectee_ne_s_interrompt_pas(): void
    {
        $this->entrerTousLesDeux();

        $this->interrompre()->assertForbidden();
        $this->assertSame(0, Remboursement::count());
    }

    public function test_interrompre_deux_fois_ne_rembourse_qu_une_fois(): void
    {
        $this->paiementExistant($this->rdv);
        $this->entrerTousLesDeux();
        $this->connectesEnsemble(30);

        $this->interrompre()->assertOk();
        $this->interrompre()->assertForbidden();

        $this->assertSame(1, Remboursement::count());
        $this->assertCount(1, $this->sms->envoyes);
    }

    // --- Patient absent ---------------------------------------------------------------------------------

    public function test_le_medecin_venu_a_l_heure_constate_l_absence_du_patient_sans_remboursement(): void
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

    public function test_un_patient_venu_a_l_heure_n_est_pas_absent_meme_sans_connexion(): void
    {
        $this->entrerTousLesDeux();
        $this->travelTo($this->lundi('09:15'));

        $this->commeMedecinRdv()->getJson($this->adresse('personnel.medecin.consultation.fin'))->assertJsonPath('actions', []);
        $this->commePatientRdv()->getJson($this->adresse('consultation.fin'))->assertJsonPath('actions', ['medecin_absent']);
        $this->patientAbsent()->assertForbidden();
        $this->assertSame(StatutConsultation::EnAttente, Consultation::sole()->statut);
    }

    public function test_arriver_a_la_dixieme_minute_pile_c_est_encore_a_l_heure(): void
    {
        $this->entrer($this->medecinRdv->user);
        $this->travelTo($this->lundi('09:10'));
        $this->entrer($this->patientRdv);
        $this->travelTo($this->lundi('09:12'));

        $this->patientAbsent()->assertForbidden()->assertJsonPath('message', 'Le patient est venu à l\'heure : son absence ne peut pas être constatée.');
    }

    public function test_des_participants_connectes_ensemble_ne_sont_pas_absents(): void
    {
        $this->entrerTousLesDeux();
        $this->connectesEnsemble(15);
        $this->travelTo($this->lundi('09:15'));

        $this->patientAbsent()->assertForbidden();
        $this->medecinAbsent()->assertForbidden();
        $this->assertSame(0, Remboursement::count());
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

    // --- Médecin absent ------------------------------------------------------------------------------------

    public function test_le_patient_venu_a_l_heure_constate_l_absence_du_medecin_et_est_rembourse(): void
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
        $this->assertSame('MEDIHOME : le medecin n\'a pas pu vous recevoir le 07/12 a 09:00. Vous serez rembourse integralement ; ouvrez l\'application pour reprendre rendez-vous.', $this->sms->envoyes[0]['message']);
    }

    public function test_un_patient_arrive_apres_le_delai_ne_constate_pas_l_absence_du_medecin(): void
    {
        $this->travelTo($this->lundi('09:11'));
        $this->entrer($this->patientRdv);

        $this->commePatientRdv()->getJson($this->adresse('consultation.fin'))->assertJsonPath('actions', []);
        $this->medecinAbsent()->assertForbidden();
        $this->assertSame(0, Remboursement::count());
    }

    public function test_un_medecin_arrive_apres_le_delai_ne_constate_pas_l_absence_du_patient(): void
    {
        $this->travelTo($this->lundi('09:11'));
        $this->entrer($this->medecinRdv->user);

        $this->commeMedecinRdv()->getJson($this->adresse('personnel.medecin.consultation.fin'))->assertJsonPath('actions', []);
        $this->patientAbsent()->assertForbidden();
        $this->assertSame(StatutConsultation::EnAttente, Consultation::sole()->statut);
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

    public function test_tous_les_paiements_reussis_du_rendez_vous_sont_rembourses(): void
    {
        // Deux débits réussis pour le même rendez-vous (paiement tardif, en double).
        $premier = $this->paiementExistant($this->rdv);
        $second = $this->paiementExistant($this->rdv);
        $this->entrer($this->patientRdv);
        $this->travelTo($this->lundi('09:15'));

        $this->medecinAbsent()->assertOk();

        $this->assertSame('10.00', (string) $premier->fresh()->montant_rembourse);
        $this->assertSame('10.00', (string) $second->fresh()->montant_rembourse);
        $this->assertSame(2, Remboursement::where('motif', MotifRemboursement::AbsenceMedecin->value)->count());
        $this->assertSame(StatutPaiement::Reussi, $second->fresh()->statut);
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

    // --- Les trois scénarios de la revue : jamais le médecin payé -----------------------------------------

    public function test_scenario_1_le_medecin_ouvre_la_page_et_repart_le_patient_est_rembourse(): void
    {
        $this->encaisse($this->rdv);
        $this->entrer($this->medecinRdv->user);          // 08:55, puis repart sans appel
        $this->travelTo($this->lundi('09:05'));
        $this->entrer($this->patientRdv);
        $this->travelTo($this->lundi('09:10'));

        $this->medecinAbsent()->assertOk();

        $this->assertSame(StatutRendezVous::AbsenceMedecin, $this->rdv->fresh()->statut);
        $this->assertSame(1, Remboursement::count());
        $this->assertSame('0.00', $this->brutReverse());
    }

    public function test_scenario_1_sans_reclamation_la_tache_conclut_au_medecin_absent(): void
    {
        $this->encaisse($this->rdv);
        $this->entrer($this->medecinRdv->user);
        $this->travelTo($this->lundi('09:05'));
        $this->entrer($this->patientRdv);

        $this->travelTo($this->lundi('10:21'));
        Artisan::call('teleconsultation:constater-salles-fermees');

        $this->assertSame(StatutRendezVous::AbsenceMedecin, $this->rdv->fresh()->statut);
        $this->assertSame('0.00', $this->brutReverse());
    }

    public function test_scenario_2_le_medecin_arrive_apres_le_depart_du_patient(): void
    {
        $this->encaisse($this->rdv);
        $this->travelTo($this->lundi('09:00'));
        $this->entrer($this->patientRdv);               // puis repart
        $this->travelTo($this->lundi('09:30'));
        $this->entrer($this->medecinRdv->user);

        $this->terminer()->assertForbidden();
        $this->patientAbsent()->assertForbidden();       // arrivé après le délai de 10 minutes

        $this->travelTo($this->lundi('10:21'));
        Artisan::call('teleconsultation:constater-salles-fermees');
        $this->assertSame(StatutRendezVous::AbsenceMedecin, $this->rdv->fresh()->statut);
        $this->assertSame('0.00', $this->brutReverse());
    }

    public function test_scenario_3_un_report_efface_le_passage_du_medecin(): void
    {
        $this->medecinRdv->plages()->create(['jour_semaine' => 1, 'heure_debut' => '09:00', 'heure_fin' => '12:00']);
        $this->encaisse($this->rdv);
        $this->entrer($this->medecinRdv->user);          // 08:55
        app(ReservationService::class)->reporter($this->rdv, $this->lundi('11:00'));
        $this->rdv->refresh();

        $this->travelTo($this->lundi('11:02'));
        $this->entrer($this->patientRdv);
        $this->travelTo($this->lundi('11:10'));

        $this->patientAbsent()->assertForbidden();       // son passage de 08:55 ne compte plus
        $this->medecinAbsent()->assertOk();
        $this->assertSame(StatutRendezVous::AbsenceMedecin, $this->rdv->fresh()->statut);
        $this->assertSame('0.00', $this->brutReverse());
    }

    // --- État proposé aux composants -------------------------------------------------------------------------

    public function test_l_etat_propose_les_actions_selon_le_role_la_presence_et_l_heure(): void
    {
        $this->entrer($this->patientRdv);

        $this->commePatientRdv()->getJson($this->adresse('consultation.fin'))
            ->assertOk()->assertJsonPath('role', 'patient')->assertJsonPath('statut', 'en_attente')
            ->assertJsonPath('actions', [])->assertJsonPath('absenceDisponibleA', '09:10');

        $this->travelTo($this->lundi('09:10'));
        $this->commePatientRdv()->getJson($this->adresse('consultation.fin'))->assertJsonPath('actions', ['medecin_absent']);
        $this->commeMedecinRdv()->getJson($this->adresse('personnel.medecin.consultation.fin'))->assertJsonPath('actions', []);

        $this->entrer($this->medecinRdv->user);
        $this->connectesEnsemble(30);
        $this->commeMedecinRdv()->getJson($this->adresse('personnel.medecin.consultation.fin'))
            ->assertJsonPath('actions', ['interrompre'])->assertJsonPath('terminerDansSecondes', 90);
        $this->connectesEnsemble(90);
        $this->commeMedecinRdv()->getJson($this->adresse('personnel.medecin.consultation.fin'))->assertJsonPath('actions', ['terminer', 'interrompre']);
        $this->commePatientRdv()->getJson($this->adresse('consultation.fin'))->assertJsonPath('actions', []);
    }

    // --- Tâche planifiée --------------------------------------------------------------------------------------

    public function test_la_tache_conclut_les_salles_fermees_sur_la_presence_reelle(): void
    {
        $acces = app(AccesConsultation::class);
        $personne = $this->rdv;
        $paiementPersonne = $this->paiementExistant($personne);
        $medecinSeul = $this->rendezVousExistant($this->medecinRdv, $this->patient(), $this->lundi('09:30'));
        $patientSeul = $this->rendezVousExistant($this->medecinRdv, $this->patient(), $this->lundi('10:00'));
        $assez = $this->rendezVousExistant($this->medecinRdv, $this->patient(), $this->lundi('10:30'));
        $tropCourt = $this->rendezVousExistant($this->medecinRdv, $this->patient(), $this->lundi('11:00'));
        $paiementCourt = $this->paiementExistant($tropCourt);
        $plusTard = $this->rendezVousExistant($this->medecinRdv, $this->patient(), $this->lundi('15:00'));

        $this->travelTo($this->lundi('09:30'));
        $acces->entrer($this->medecinRdv->user, $medecinSeul);
        $this->travelTo($this->lundi('10:00'));
        $acces->entrer($patientSeul->patient, $patientSeul);
        $this->travelTo($this->lundi('10:30'));
        $acces->entrer($assez->patient, $assez);
        $acces->entrer($this->medecinRdv->user, $assez);
        $this->connectesEnsemble(180, $assez);
        $this->travelTo($this->lundi('11:00'));
        $acces->entrer($tropCourt->patient, $tropCourt);
        $acces->entrer($this->medecinRdv->user, $tropCourt);
        $this->connectesEnsemble(30, $tropCourt);

        // Fin de 11:00–11:20 plus 60 minutes de fermeture.
        $this->travelTo($this->lundi('12:21'));
        $this->assertSame(0, Artisan::call('teleconsultation:constater-salles-fermees'));

        $this->assertSame(StatutRendezVous::AbsenceMedecin, $personne->fresh()->statut, 'Personne n\'est venu : le médecin est réputé absent.');
        $this->assertSame(StatutRendezVous::AbsencePatient, $medecinSeul->fresh()->statut);
        $this->assertSame(StatutRendezVous::AbsenceMedecin, $patientSeul->fresh()->statut);
        $this->assertSame(StatutRendezVous::Termine, $assez->fresh()->statut);
        $this->assertSame(StatutConsultation::Interrompue, Consultation::where('rendez_vous_id', $tropCourt->id)->sole()->statut);
        $this->assertSame(StatutRendezVous::AnnuleParMedecin, $tropCourt->fresh()->statut);
        $this->assertSame(StatutRendezVous::Confirme, $plusTard->fresh()->statut, 'Salle pas encore fermée.');
        $this->assertSame(1, Remboursement::where('paiement_id', $paiementPersonne->id)->where('motif', MotifRemboursement::AbsenceMedecin->value)->count());
        $this->assertSame(1, Remboursement::where('paiement_id', $paiementCourt->id)->where('motif', MotifRemboursement::ConsultationInterrompue->value)->count());
        $this->assertCount(3, $this->sms->envoyes, 'Patients sans médecin et consultation interrompue prévenus.');

        // Relancée, la tâche ne refait rien.
        Artisan::call('teleconsultation:constater-salles-fermees');
        $this->assertCount(3, $this->sms->envoyes);
        $this->assertSame(2, Remboursement::count());
    }

    public function test_la_tache_ne_fait_pas_payer_le_patient_venu_a_l_heure_sans_connexion(): void
    {
        // Les deux à l'heure, jamais connectés ensemble : le doute profite au patient (RG-06).
        $this->encaisse($this->rdv);
        $this->entrerTousLesDeux();

        $this->travelTo($this->lundi('10:21'));
        Artisan::call('teleconsultation:constater-salles-fermees');

        $this->assertSame(StatutRendezVous::AbsenceMedecin, $this->rdv->fresh()->statut);
        $this->assertSame(MotifRemboursement::AbsenceMedecin, Remboursement::sole()->motif);
        $this->assertSame('0.00', $this->brutReverse());
    }

    public function test_la_tache_est_planifiee_toutes_les_cinq_minutes(): void
    {
        $evenement = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains($e->command, 'teleconsultation:constater-salles-fermees'));

        $this->assertNotNull($evenement);
        $this->assertSame('*/5 * * * *', $evenement->expression);
    }

    // --- Agenda du médecin -------------------------------------------------------------------------------------

    public function test_l_agenda_montre_le_statut_de_la_consultation_a_cote_du_rendez_vous(): void
    {
        $sansSalle = $this->rendezVousExistant($this->medecinRdv, $this->patient(), $this->lundi('11:00'));
        $this->entrerTousLesDeux();
        $this->connectesEnsemble(120);

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

    // --- Effets sur les reversements et le tableau de bord ------------------------------------------------------

    public function test_seule_la_consultation_terminee_compte_au_reversement(): void
    {
        $this->encaisse($this->rdv);
        $absent = $this->rendezVousExistant($this->medecinRdv, $this->patient(), $this->lundi('10:00'));
        $this->encaisse($absent);
        $interrompu = $this->rendezVousExistant($this->medecinRdv, $this->patient(), $this->lundi('11:00'));
        $this->encaisse($interrompu);

        $this->entrerTousLesDeux();
        $this->connectesEnsemble(120);
        $this->terminer()->assertOk();

        $this->travelTo($this->lundi('10:00'));
        app(AccesConsultation::class)->entrer($absent->patient, $absent);
        $this->travelTo($this->lundi('10:12'));
        $this->actingAs($absent->patient)->postJson(route('consultation.medecin-absent', ['rendezVous' => $absent->id]))->assertOk();

        $this->travelTo($this->lundi('11:00'));
        app(AccesConsultation::class)->entrer($interrompu->patient, $interrompu);
        app(AccesConsultation::class)->entrer($this->medecinRdv->user, $interrompu);
        $this->connectesEnsemble(30, $interrompu);
        app(FinConsultation::class)->interrompre($this->medecinRdv->user, $interrompu);

        $this->assertSame('10.00', $this->brutReverse(), 'La consultation terminée seule : ni l\'absence du médecin, ni l\'interrompue.');
    }

    public function test_le_tableau_de_bord_compte_les_consultations_terminees_et_les_absences(): void
    {
        $patientAbsent = $this->rendezVousExistant($this->medecinRdv, $this->patient(), $this->lundi('10:00'));
        $this->entrerTousLesDeux();
        $this->connectesEnsemble(120);
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
