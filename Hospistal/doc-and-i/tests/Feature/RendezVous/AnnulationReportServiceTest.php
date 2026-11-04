<?php

namespace Tests\Feature\RendezVous;

use App\Enums\StatutRendezVous;
use App\Models\Medecin;
use App\Models\RendezVous;
use App\Models\TypeConsultation;
use App\Models\User;
use App\Services\RendezVous\ReservationRefusee;
use App\Services\RendezVous\ReservationService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ParcoursRendezVous;
use Tests\TestCase;

/** RDV-07, RG-04, RG-05 : annulation et report. */
class AnnulationReportServiceTest extends TestCase
{
    use ParcoursRendezVous, RefreshDatabase;

    private ReservationService $service;

    private Medecin $medecin;

    private TypeConsultation $type;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesSeeder::class);
        $this->service = app(ReservationService::class);
        // Mardi 8 décembre 2026, 10:00 à 12:00.
        $this->medecin = $this->medecin([[2, '10:00', '12:00']]);
        $this->type = $this->typeConsultation($this->medecin, '20.00', 20);
    }

    private function mardi(string $heure): CarbonImmutable
    {
        return CarbonImmutable::parse('2026-12-08 '.$heure, 'Africa/Kinshasa');
    }

    private function confirme(string $heure = '10:00'): RendezVous
    {
        return $this->rendezVousExistant($this->medecin, User::factory()->create(), $this->mardi($heure), 20, StatutRendezVous::Confirme, null, $this->type);
    }

    private function refus(callable $action, string $extrait): void
    {
        try {
            $action();
            $this->fail('Refus attendu : '.$extrait);
        } catch (ReservationRefusee $e) {
            $this->assertStringContainsString($extrait, $e->getMessage());
        }
    }

    /** @return array<string, array{0: string, 1: int}> */
    public static function delais(): array
    {
        return [
            'plus de 24 h : tout' => ['2026-12-07 09:59', 100],
            'exactement 24 h : moitié' => ['2026-12-07 10:00', 50],
            'entre 24 h et 2 h : moitié' => ['2026-12-08 05:00', 50],
            'exactement 2 h : moitié' => ['2026-12-08 08:00', 50],
            'moins de 2 h : rien' => ['2026-12-08 08:01', 0],
        ];
    }

    #[DataProvider('delais')]
    public function test_le_patient_qui_annule_un_rendez_vous_confirme_est_rembourse_selon_le_delai(string $maintenant, int $taux): void
    {
        $this->travelTo(CarbonImmutable::parse($maintenant, 'Africa/Kinshasa'));
        $rdv = $this->confirme();

        $annule = $this->service->annulerParPatient($rdv, '  Empêchement  ');

        $this->assertSame(StatutRendezVous::AnnuleParPatient, $annule->statut);
        $this->assertSame($taux, $rdv->fresh()->taux_remboursement);
        $this->assertSame($maintenant, $rdv->fresh()->annule_le->format('Y-m-d H:i'));
        $this->assertSame('Empêchement', $rdv->fresh()->motif_annulation);
        $this->assertSame('annulee', $rdv->demande->fresh()->statut);
    }

    public function test_les_seuils_de_remboursement_suivent_la_configuration(): void
    {
        config(['doc-and-i.rendez_vous.annulation' => ['remboursement_total_heures' => 48, 'remboursement_partiel_heures' => 6, 'taux_partiel' => 50]]);
        $this->travelTo($this->mardi('10:00')->subHours(30));

        $this->assertSame(50, $this->service->annulerParPatient($this->confirme())->taux_remboursement);
    }

    public function test_annuler_une_attente_de_paiement_ne_pose_pas_la_question_du_remboursement(): void
    {
        $this->travelTo($this->mardi('07:00'));
        $patient = User::factory()->create();
        $rdv = $this->service->reserver($this->demande($patient), $this->medecin, $this->type, $this->mardi('10:00'));

        $this->service->annulerParPatient($rdv);

        $this->assertSame(StatutRendezVous::AnnuleParPatient, $rdv->fresh()->statut);
        $this->assertNull($rdv->fresh()->taux_remboursement);
        $this->assertNotNull($rdv->fresh()->annule_le);
        $this->assertSame('ouverte', $rdv->demande->fresh()->statut, 'Le patient peut choisir un autre créneau.');
        // Le créneau est libre aussitôt.
        $this->service->reserver($this->demande(User::factory()->create()), $this->medecin, $this->type, $this->mardi('10:00'));
    }

    public function test_annuler_deux_fois_est_refuse_sans_rien_changer(): void
    {
        $this->travelTo($this->mardi('07:00'));
        $rdv = $this->confirme();
        $this->service->annulerParPatient($rdv, 'Premier motif');

        $this->refus(fn () => $this->service->annulerParPatient($rdv, 'Second motif'), 'déjà annulé');
        $this->assertSame('Premier motif', $rdv->fresh()->motif_annulation);
    }

    public function test_une_consultation_commencee_ne_s_annule_plus(): void
    {
        $this->travelTo($this->mardi('10:00'));

        $this->refus(fn () => $this->service->annulerParPatient($this->confirme()), 'a déjà commencé');
    }

    public function test_le_medecin_qui_annule_rembourse_tout(): void
    {
        $this->travelTo($this->mardi('09:50'));
        $rdv = $this->confirme();

        $this->service->annulerParMedecin($rdv, 'Urgence au service');

        $rdv->refresh();
        $this->assertSame(StatutRendezVous::AnnuleParMedecin, $rdv->statut);
        $this->assertSame(100, $rdv->taux_remboursement);
        $this->assertSame('Urgence au service', $rdv->motif_annulation);
        $this->assertNotNull($rdv->annule_le);
        $this->assertSame('ouverte', $rdv->demande->fresh()->statut, 'Le patient peut reprendre un créneau.');
    }

    public function test_le_medecin_doit_donner_un_motif_et_ne_peut_annuler_qu_un_rendez_vous_confirme(): void
    {
        $this->travelTo($this->mardi('07:00'));
        $rdv = $this->confirme();
        $this->refus(fn () => $this->service->annulerParMedecin($rdv, '   '), 'motif');
        $this->assertSame(StatutRendezVous::Confirme, $rdv->fresh()->statut);

        $attente = $this->service->reserver($this->demande(User::factory()->create()), $this->medecin, $this->type, $this->mardi('11:00'));
        $this->refus(fn () => $this->service->annulerParMedecin($attente, 'Absent'), 'confirmé');

        $this->service->annulerParMedecin($rdv, 'Absent');
        $this->refus(fn () => $this->service->annulerParMedecin($rdv, 'Absent'), 'confirmé');
    }

    public function test_reporter_plus_de_deux_heures_avant_deplace_le_rendez_vous_au_meme_prix(): void
    {
        $this->travelTo($this->mardi('07:00'));
        $rdv = $this->confirme('10:00');
        $rdv->forceFill(['rappel_24h_envoye_le' => now(), 'prix' => '20.00'])->save();
        $this->type->update(['prix' => '35.00']);

        $this->service->reporter($rdv, $this->mardi('11:00'));
        $this->service->reporter($rdv, $this->mardi('11:20'));

        $rdv->refresh();
        $this->assertSame(StatutRendezVous::Confirme, $rdv->statut);
        $this->assertSame('2026-12-08 11:20', $rdv->debut->format('Y-m-d H:i'));
        $this->assertSame('2026-12-08 11:40', $rdv->fin->format('Y-m-d H:i'));
        $this->assertSame('20.00', $rdv->prix, 'Le prix payé ne change pas.');
        $this->assertSame(2, $rdv->reports);
        $this->assertNull($rdv->rappel_24h_envoye_le, 'Nouvelle heure : nouveaux rappels.');
        // L'ancien créneau est libre.
        $this->service->reserver($this->demande(User::factory()->create()), $this->medecin, $this->type, $this->mardi('10:00'));
    }

    public function test_a_moins_de_deux_heures_un_seul_report_est_permis(): void
    {
        $this->travelTo($this->mardi('07:00'));
        $rdv = $this->confirme('10:00');
        $this->service->reporter($rdv, $this->mardi('10:20'));

        $this->travelTo($this->mardi('09:00'));
        $this->service->reporter($rdv, $this->mardi('11:00'));

        $this->travelTo($this->mardi('10:00'));
        $this->refus(fn () => $this->service->reporter($rdv, $this->mardi('11:40')), 'qu\'une fois');
        $this->assertSame('2026-12-08 11:00', $rdv->fresh()->debut->format('Y-m-d H:i'));
        $this->assertSame(2, $rdv->fresh()->reports);
    }

    public function test_le_nombre_de_reports_tardifs_suit_la_configuration(): void
    {
        config(['doc-and-i.rendez_vous.reports_max_tardifs' => 0]);
        $this->travelTo($this->mardi('09:00'));

        $this->refus(fn () => $this->service->reporter($this->confirme('10:20'), $this->mardi('11:00')), 'déplacer');
    }

    public function test_le_report_n_accepte_qu_un_creneau_libre_propose(): void
    {
        $this->travelTo($this->mardi('07:00'));
        $rdv = $this->confirme('10:00');
        $this->confirme('10:40');

        $this->refus(fn () => $this->service->reporter($rdv, $this->mardi('10:40')), 'n\'est plus disponible');
        $this->refus(fn () => $this->service->reporter($rdv, $this->mardi('10:30')), 'n\'est plus disponible');
        $this->refus(fn () => $this->service->reporter($rdv, $this->mardi('14:00')), 'n\'est plus disponible');
        $this->refus(fn () => $this->service->reporter($rdv, $this->mardi('10:00')), 'déjà l\'heure');
        $this->assertSame(0, $rdv->fresh()->reports);
    }

    public function test_un_report_peut_chevaucher_l_ancien_horaire_du_meme_rendez_vous(): void
    {
        $this->travelTo($this->mardi('07:00'));
        $rdv = $this->confirme('10:00');
        // Type de 30 minutes : la grille 10:00, 10:30 ; l'ancien rendez-vous (10:00-10:30) ne bloque pas son propre report.
        $type30 = $this->typeConsultation($this->medecin, '20.00', 30);
        $rdv->forceFill(['type_consultation_id' => $type30->id, 'fin' => $this->mardi('10:30')])->save();

        $this->service->reporter($rdv, $this->mardi('10:30'));
        $this->assertSame('10:30', $rdv->fresh()->debut->format('H:i'));
    }

    public function test_seul_un_rendez_vous_confirme_se_reporte(): void
    {
        $this->travelTo($this->mardi('07:00'));
        $attente = $this->service->reserver($this->demande(User::factory()->create()), $this->medecin, $this->type, $this->mardi('10:00'));
        $this->refus(fn () => $this->service->reporter($attente, $this->mardi('11:00')), 'confirmé');

        $annule = $this->confirme('11:20');
        $this->service->annulerParPatient($annule);
        $this->refus(fn () => $this->service->reporter($annule, $this->mardi('11:40')), 'confirmé');
    }

    public function test_une_consultation_commencee_ne_se_reporte_plus(): void
    {
        $this->travelTo($this->mardi('10:05'));

        $this->refus(fn () => $this->service->reporter($this->confirme('10:00'), $this->mardi('11:00')), 'a déjà commencé');
    }
}
