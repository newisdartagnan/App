<?php

namespace Tests\Feature\Paiement;

use App\Enums\MotifRemboursement;
use App\Enums\StatutPaiement;
use App\Enums\StatutRemboursement;
use App\Enums\StatutRendezVous;
use App\Models\Medecin;
use App\Models\Paiement;
use App\Models\Remboursement;
use App\Models\RendezVous;
use App\Models\User;
use App\Services\Paiement\PaiementRefuse;
use App\Services\Paiement\RemboursementService;
use App\Services\RendezVous\CreneauxService;
use App\Services\RendezVous\ReservationRefusee;
use App\Services\RendezVous\ReservationService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Mockery;
use Tests\Support\ParcoursPaiement;
use Tests\Support\ParcoursRendezVous;
use Tests\Support\PersonnelDeTest;
use Tests\TestCase;

/** RG-04, RG-05 branchés sur les remboursements (PAY-07) ; RG-03 : reprise d'un rendez-vous expiré. */
class RemboursementAnnulationTest extends TestCase
{
    use ParcoursPaiement, ParcoursRendezVous, PersonnelDeTest, RefreshDatabase;

    private ReservationService $service;

    private Medecin $medecin;

    private User $patient;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesSeeder::class);
        $this->service = app(ReservationService::class);
        $this->medecin = $this->medecin([[2, '10:00', '12:00']]);
        $this->patient = $this->patient();
    }

    private function mardi(string $heure): CarbonImmutable
    {
        return CarbonImmutable::parse('2026-12-08 '.$heure, 'Africa/Kinshasa');
    }

    /** @return array{0: RendezVous, 1: Paiement} */
    private function rdvPaye(string $heure = '10:00', string $prix = '15.00'): array
    {
        $rdv = $this->rendezVousExistant($this->medecin, $this->patient, $this->mardi($heure), 20, StatutRendezVous::Confirme,
            type: $this->typeConsultation($this->medecin, $prix));

        return [$rdv, $this->paiementExistant($rdv)];
    }

    public function test_rg04_plus_de_24_heures_avant_remboursement_total_a_valider_par_la_caisse(): void
    {
        $this->travelTo($this->mardi('10:00')->subDays(2));
        [$rdv, $paiement] = $this->rdvPaye();

        $this->service->annulerParPatient($rdv);

        $remboursement = Remboursement::sole();
        $this->assertSame($paiement->id, $remboursement->paiement_id);
        $this->assertSame('15.00', $remboursement->montant);
        $this->assertSame(MotifRemboursement::AnnulationPatient, $remboursement->motif);
        $this->assertSame(StatutRemboursement::AValider, $remboursement->statut);
        $this->assertFalse($remboursement->automatique);
        $this->assertSame('15.00', $paiement->fresh()->montant_rembourse);
    }

    public function test_rg04_dans_la_tranche_a_moitie_la_moitie_du_paiement(): void
    {
        $this->travelTo($this->mardi('05:00'));
        [$rdv] = $this->rdvPaye('10:00', '15.01');

        $this->service->annulerParPatient($rdv);

        $this->assertSame('7.50', Remboursement::sole()->montant, 'Arrondi au centime inférieur.');
    }

    public function test_rg04_moins_de_deux_heures_avant_aucun_remboursement(): void
    {
        $this->travelTo($this->mardi('09:00'));
        [$rdv] = $this->rdvPaye();

        $this->service->annulerParPatient($rdv);

        $this->assertSame(0, $rdv->fresh()->taux_remboursement);
        $this->assertSame(0, Remboursement::count());
    }

    public function test_rg04_en_francs_le_remboursement_suit_la_devise_payee(): void
    {
        $this->travelTo($this->mardi('05:00'));
        $rdv = $this->rendezVousExistant($this->medecin, $this->patient, $this->mardi('10:00'), 20, StatutRendezVous::Confirme,
            type: $this->typeConsultation($this->medecin, '10.00'));
        $this->paiementExistant($rdv, montant: '28500.00', devise: 'CDF');

        $this->service->annulerParPatient($rdv);

        $this->assertSame('14250.00', Remboursement::sole()->montant);
        $this->assertSame('CDF', Remboursement::sole()->devise);
    }

    public function test_annuler_une_attente_de_paiement_ne_cree_aucun_remboursement(): void
    {
        $this->travelTo($this->mardi('07:00'));
        $rdv = $this->rendezVousExistant($this->medecin, $this->patient, $this->mardi('10:00'), 20, StatutRendezVous::EnAttentePaiement);

        $this->service->annulerParPatient($rdv);

        $this->assertSame(0, Remboursement::count());
    }

    public function test_rg05_le_medecin_qui_annule_declenche_le_remboursement_total(): void
    {
        $this->travelTo($this->mardi('09:50'));
        [$rdv, $paiement] = $this->rdvPaye();

        $this->service->annulerParMedecin($rdv, 'Urgence au service');

        $remboursement = Remboursement::sole();
        $this->assertSame('15.00', $remboursement->montant);
        $this->assertSame(MotifRemboursement::AnnulationMedecin, $remboursement->motif);
        $this->assertSame($paiement->id, $remboursement->paiement_id);
    }

    public function test_un_rendez_vous_confirme_sans_paiement_encaisse_s_annule_sans_remboursement(): void
    {
        $this->travelTo($this->mardi('07:00'));
        $rdv = $this->rendezVousExistant($this->medecin, $this->patient, $this->mardi('10:00'));
        $this->paiementExistant($rdv, StatutPaiement::Echoue);

        $this->service->annulerParMedecin($rdv, 'Absent');

        $this->assertSame(0, Remboursement::count());
        $this->assertSame(StatutRendezVous::AnnuleParMedecin, $rdv->fresh()->statut);
    }

    public function test_avec_deux_paiements_l_annulation_rembourse_le_premier(): void
    {
        $this->travelTo($this->mardi('07:00'));
        [$rdv, $premier] = $this->rdvPaye();
        $this->travel(1)->minutes();
        $second = $this->paiementExistant($rdv);
        app(RemboursementService::class)->demander($second, '15.00', MotifRemboursement::PaiementEnDouble);

        $this->service->annulerParMedecin($rdv, 'Absent');

        $this->assertSame($premier->id, Remboursement::where('motif', 'annulation_medecin')->sole()->paiement_id);
    }

    public function test_si_la_demande_de_remboursement_echoue_l_annulation_est_annulee_aussi(): void
    {
        $this->travelTo($this->mardi('07:00'));
        [$rdv] = $this->rdvPaye();
        $remboursements = Mockery::mock(RemboursementService::class)->makePartial();
        $remboursements->shouldReceive('demander')->andThrow(new \RuntimeException('Base indisponible'));
        $service = new ReservationService(app(CreneauxService::class), $remboursements);

        try {
            $service->annulerParMedecin($rdv, 'Absent');
            $this->fail('L\'erreur doit remonter.');
        } catch (\RuntimeException) {
            $this->assertSame(StatutRendezVous::Confirme, $rdv->fresh()->statut, 'Même transaction : rien n\'est annulé sans sa demande de remboursement.');
        }
    }

    public function test_l_annulation_rejouee_ne_rembourse_pas_deux_fois(): void
    {
        $this->travelTo($this->mardi('07:00'));
        [$rdv] = $this->rdvPaye();
        $this->service->annulerParMedecin($rdv, 'Absent');

        try {
            $this->service->annulerParMedecin($rdv, 'Absent');
        } catch (ReservationRefusee) {
        }

        $this->assertSame(1, Remboursement::count());
    }

    public function test_la_reprise_apres_paiement_tardif_est_le_seul_chemin_d_un_rendez_vous_expire_vers_confirme(): void
    {
        $rdv = $this->rendezVousExistant($this->medecin, $this->patient, $this->mardi('10:00'), 20, StatutRendezVous::Expire);

        // Le tableau général des transitions le refuse toujours.
        $this->assertFalse(StatutRendezVous::Expire->peutPasserA(StatutRendezVous::Confirme));
        try {
            $rdv->transitionVers(StatutRendezVous::Confirme);
            $this->fail('transitionVers doit refuser Expire → Confirme.');
        } catch (LogicException) {
            $this->addToAssertionCount(1);
        }

        // La reprise dédiée refuse tout autre point de départ.
        foreach ([StatutRendezVous::AnnuleParPatient, StatutRendezVous::EnAttentePaiement, StatutRendezVous::Confirme] as $statut) {
            $autre = $this->rendezVousExistant($this->medecin, User::factory()->create(), $this->mardi('11:00'), 20, $statut);
            try {
                $autre->reprendreApresPaiementTardif();
                $this->fail("Reprise refusée attendue depuis {$statut->value}.");
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            }
            $autre->delete();
        }

        $rdv->reprendreApresPaiementTardif();
        $this->assertSame(StatutRendezVous::Confirme, $rdv->statut);
    }

    public function test_confirmer_paiement_refuse_toujours_un_rendez_vous_expire(): void
    {
        $this->travelTo($this->mardi('07:00'));
        $rdv = $this->rendezVousExistant($this->medecin, $this->patient, $this->mardi('10:00'), 20, StatutRendezVous::Expire);

        $this->expectException(ReservationRefusee::class);
        $this->service->confirmerPaiement($rdv);
    }

    public function test_confirmer_apres_paiement_donne_l_issue_selon_le_rendez_vous(): void
    {
        $this->travelTo($this->mardi('07:00'));
        $attente = $this->rendezVousExistant($this->medecin, $this->patient, $this->mardi('10:00'), 20, StatutRendezVous::EnAttentePaiement);
        $this->assertSame(ReservationService::CONFIRME, $this->service->confirmerApresPaiement($attente));
        $this->assertSame(ReservationService::DEJA_CONFIRME, $this->service->confirmerApresPaiement($attente));

        $annule = $this->rendezVousExistant($this->medecin, $this->patient, $this->mardi('11:00'), 20, StatutRendezVous::AnnuleParMedecin);
        $this->assertSame(ReservationService::IMPOSSIBLE, $this->service->confirmerApresPaiement($annule));

        // Médecin devenu non réservable : pas de reprise.
        $expire = $this->rendezVousExistant($this->medecin, User::factory()->create(), $this->mardi('11:20'), 20, StatutRendezVous::Expire);
        $expire->demande->fresh()->forceFill(['statut' => 'ouverte'])->save();
        $this->medecin->forceFill(['actif' => false])->save();
        $this->assertSame(ReservationService::IMPOSSIBLE, $this->service->confirmerApresPaiement($expire));
        $this->medecin->forceFill(['actif' => true])->save();
        $this->assertSame(ReservationService::REPRIS, $this->service->confirmerApresPaiement($expire));
    }

    // --- Revue de sécurité : remboursement plafonné à ce qui reste remboursable ---

    private function gesteCommercial(Paiement $paiement, string $montant): void
    {
        app(RemboursementService::class)->demander($paiement, $montant, MotifRemboursement::GesteCommercial, $this->personnelActive('caisse'));
    }

    public function test_apres_un_geste_commercial_l_annulation_ne_demande_que_le_reste(): void
    {
        $this->travelTo($this->mardi('07:00'));
        [$rdv, $paiement] = $this->rdvPaye('10:00', '15.00');
        $this->gesteCommercial($paiement, '8.00');

        $this->service->annulerParMedecin($rdv, 'Urgence');

        $this->assertSame(StatutRendezVous::AnnuleParMedecin, $rdv->fresh()->statut);
        $this->assertSame('7.00', Remboursement::where('motif', 'annulation_medecin')->sole()->montant);
        $this->assertSame('15.00', $paiement->fresh()->montant_rembourse);
    }

    public function test_si_tout_est_deja_rembourse_l_annulation_ne_demande_rien_et_aboutit(): void
    {
        $this->travelTo($this->mardi('10:00')->subDays(2));
        [$rdv, $paiement] = $this->rdvPaye('10:00', '15.00');
        $this->gesteCommercial($paiement, '15.00');

        $this->service->annulerParPatient($rdv);

        $this->assertSame(StatutRendezVous::AnnuleParPatient, $rdv->fresh()->statut);
        $this->assertSame(100, $rdv->fresh()->taux_remboursement);
        $this->assertSame(0, Remboursement::where('motif', 'annulation_patient')->count());
    }

    public function test_le_patient_qui_annule_apres_un_geste_commercial_n_obtient_jamais_d_erreur_500(): void
    {
        $this->travelTo($this->mardi('10:00')->subDays(2));
        [$rdv, $paiement] = $this->rdvPaye('10:00', '15.00');
        $this->gesteCommercial($paiement, '10.00');

        $this->actingAs($this->patient)->post(route('rdv.annuler', $rdv))
            ->assertRedirect(route('rdv.voir', $rdv))
            ->assertSessionHasNoErrors();

        $this->assertSame('5.00', Remboursement::where('motif', 'annulation_patient')->sole()->montant);
    }

    public function test_les_deux_controleurs_d_annulation_rendent_un_refus_metier_sans_erreur_500(): void
    {
        $this->travelTo($this->mardi('07:00'));
        $medecin = $this->medecinActive();
        $rdvPatient = $this->rendezVousExistant($medecin, $this->patient, $this->mardi('10:00'));
        $rdvMedecin = $this->rendezVousExistant($medecin, User::factory()->create(), $this->mardi('11:00'));
        $this->paiementExistant($rdvPatient);
        $this->paiementExistant($rdvMedecin);
        $remboursements = Mockery::mock(RemboursementService::class)->makePartial();
        $remboursements->shouldReceive('demander')->andThrow(new PaiementRefuse('Remboursement impossible pour le moment.'));
        $this->app->instance(RemboursementService::class, $remboursements);
        $this->app->forgetInstance(ReservationService::class);

        $this->actingAs($this->patient)->from(route('rdv.voir', $rdvPatient))->post(route('rdv.annuler', $rdvPatient))
            ->assertRedirect(route('rdv.voir', $rdvPatient))
            ->assertSessionHasErrors(['annulation' => 'Remboursement impossible pour le moment.']);

        $this->commePersonnel($medecin->user)->from(route('personnel.medecin.agenda'))
            ->post(route('personnel.medecin.rendez-vous.annuler', $rdvMedecin), ['motif' => 'Urgence'])
            ->assertRedirect(route('personnel.medecin.agenda'))
            ->assertSessionHasErrors('motif');

        $this->assertSame(StatutRendezVous::Confirme, $rdvPatient->fresh()->statut, 'Même transaction : rien n\'est annulé.');
        $this->assertSame(StatutRendezVous::Confirme, $rdvMedecin->fresh()->statut);
    }
}
