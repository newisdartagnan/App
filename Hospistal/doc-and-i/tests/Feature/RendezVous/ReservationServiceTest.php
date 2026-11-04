<?php

namespace Tests\Feature\RendezVous;

use App\Enums\StatutRendezVous;
use App\Models\DemandeConsultation;
use App\Models\Medecin;
use App\Models\RendezVous;
use App\Models\TypeConsultation;
use App\Models\User;
use App\Services\RendezVous\CreneauxService;
use App\Services\RendezVous\ReservationRefusee;
use App\Services\RendezVous\ReservationService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\ParcoursRendezVous;
use Tests\TestCase;

/** RDV-05 : réservation avec blocage de 15 minutes, confirmation, expiration (RG-03, RG-13). */
class ReservationServiceTest extends TestCase
{
    use ParcoursRendezVous, RefreshDatabase;

    private ReservationService $service;

    private Medecin $medecin;

    private TypeConsultation $type;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesSeeder::class);
        $this->voyagerAuLundi('07:00');
        $this->service = app(ReservationService::class);
        $this->medecin = $this->medecin([[1, '09:00', '10:00']]);
        $this->type = $this->typeConsultation($this->medecin, '15.00', 20, 'USD');
    }

    private function reserver(?User $patient = null, string $heure = '09:00', ?DemandeConsultation $demande = null): RendezVous
    {
        $demande ??= $this->demande($patient ?? User::factory()->create());

        return $this->service->reserver($demande, $this->medecin, $this->type, $this->lundi($heure));
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

    public function test_la_reservation_bloque_le_creneau_quinze_minutes_et_fige_le_prix(): void
    {
        $patient = User::factory()->create();
        $demande = $this->demande($patient);

        $rdv = $this->reserver($patient, '09:20', $demande);

        $this->assertSame(StatutRendezVous::EnAttentePaiement, $rdv->statut);
        $this->assertSame($patient->id, $rdv->patient_id);
        $this->assertSame('2026-12-07 09:20', $rdv->debut->format('Y-m-d H:i'));
        $this->assertSame('2026-12-07 09:40', $rdv->fin->format('Y-m-d H:i'));
        $this->assertSame('2026-12-07 07:15', $rdv->fresh()->expire_le->format('Y-m-d H:i'));
        $this->assertSame('reservee', $demande->fresh()->statut);
        $this->assertSame($this->type->specialite_id, $demande->fresh()->specialite_id);

        // RG-13 : une révision du prix ne change rien au rendez-vous déjà pris.
        $this->type->update(['prix' => '99.00', 'devise' => 'CDF']);
        $this->assertSame('15.00', $rdv->fresh()->prix);
        $this->assertSame('USD', $rdv->fresh()->devise);
    }

    public function test_le_delai_de_blocage_suit_la_configuration(): void
    {
        config(['doc-and-i.rendez_vous.delai_paiement_minutes' => 5]);

        $this->assertSame('2026-12-07 07:05', $this->reserver()->fresh()->expire_le->format('Y-m-d H:i'));
    }

    public function test_un_creneau_deja_pris_est_refuse_proprement(): void
    {
        $this->reserver(User::factory()->create(), '09:00');

        $this->refus(fn () => $this->reserver(User::factory()->create(), '09:00'), 'n\'est plus disponible');
        $this->assertSame(1, RendezVous::count());
    }

    public function test_un_creneau_chevauche_par_un_rendez_vous_plus_long_est_refuse(): void
    {
        $this->rendezVousExistant($this->medecin, User::factory()->create(), $this->lundi('09:10'), 30);

        $this->refus(fn () => $this->reserver(null, '09:20'), 'n\'est plus disponible');
    }

    public function test_seul_un_creneau_propose_se_reserve(): void
    {
        foreach (['09:05', '11:00', '07:20'] as $heure) {
            $this->refus(fn () => $this->reserver(null, $heure), 'n\'est plus disponible');
        }

        $this->medecin->absences()->create(['debut' => $this->lundi('09:00'), 'fin' => $this->lundi('10:00')]);
        $this->refus(fn () => $this->reserver(null, '09:40'), 'n\'est plus disponible');
        $this->assertSame(0, RendezVous::count());
    }

    public function test_un_type_sans_prix_ne_se_reserve_pas(): void
    {
        $this->type->update(['prix' => null]);

        $this->refus(fn () => $this->reserver(), 'prix');
    }

    public function test_un_medecin_non_verifie_ne_se_reserve_pas(): void
    {
        $this->medecin->forceFill(['ordre_verifie_le' => null])->save();

        $this->refus(fn () => $this->reserver(), 'ne reçoit pas');
    }

    public function test_une_demande_orientee_vers_les_urgences_ne_se_reserve_pas(): void
    {
        $demande = $this->demande(User::factory()->create(), 'orientee_urgence');

        $this->refus(fn () => $this->reserver(null, '09:00', $demande), 'urgence');
        $this->assertSame(0, RendezVous::count());
    }

    public function test_double_envoi_une_demande_ne_donne_qu_un_rendez_vous(): void
    {
        $patient = User::factory()->create();
        $demande = $this->demande($patient);
        $this->reserver($patient, '09:00', $demande);

        $this->refus(fn () => $this->reserver($patient, '09:00', $demande), 'déjà un rendez-vous');
        $this->refus(fn () => $this->reserver($patient, '09:20', $demande), 'déjà un rendez-vous');
        $this->assertSame(1, RendezVous::count());
    }

    public function test_un_patient_ne_bloque_qu_un_creneau_a_la_fois(): void
    {
        $patient = User::factory()->create();
        $this->reserver($patient, '09:00');

        // Autre médecin, autre demande : seul l'index unique de la base l'arrête, sans erreur 500.
        $autre = $this->medecin([[1, '09:00', '10:00']]);
        $typeAutre = $this->typeConsultation($autre, '10.00');

        $this->refus(
            fn () => $this->service->reserver($this->demande($patient), $autre, $typeAutre, $this->lundi('09:00')),
            'déjà un créneau en attente de paiement'
        );
        $this->assertSame(1, RendezVous::count());
    }

    public function test_une_attente_echue_du_patient_est_expiree_avant_la_nouvelle_reservation(): void
    {
        $patient = User::factory()->create();
        $ancien = $this->reserver($patient, '09:00');

        $this->travel(16)->minutes();
        $nouveau = $this->reserver($patient, '09:20');

        $this->assertSame(StatutRendezVous::Expire, $ancien->fresh()->statut);
        $this->assertSame('ouverte', $ancien->demande->fresh()->statut, 'La demande peut être réservée de nouveau.');
        $this->assertSame(StatutRendezVous::EnAttentePaiement, $nouveau->statut);
    }

    public function test_le_creneau_d_une_attente_echue_peut_etre_repris_par_un_autre_patient(): void
    {
        $ancien = $this->reserver(User::factory()->create(), '09:00');

        $this->travel(15)->minutes();
        $nouveau = $this->reserver(User::factory()->create(), '09:00');

        $this->assertSame(StatutRendezVous::Expire, $ancien->fresh()->statut);
        $this->assertSame(StatutRendezVous::EnAttentePaiement, $nouveau->statut);
    }

    public function test_l_index_unique_de_la_base_devient_un_refus_propre(): void
    {
        // Simule la course : la vérification sous verrou n'a rien vu (autre
        // connexion), la base refuse l'insertion ; le patient lit un message.
        $creneaux = new class extends CreneauxService
        {
            public function estPropose(Medecin $medecin, TypeConsultation $type, \DateTimeInterface $debut, ?string $ignorer = null): bool
            {
                return true;
            }
        };
        $service = new ReservationService($creneaux);
        $this->rendezVousExistant($this->medecin, User::factory()->create(), $this->lundi('09:00'), 20, StatutRendezVous::Confirme, null, $this->type);

        $this->refus(
            fn () => $service->reserver($this->demande(User::factory()->create()), $this->medecin, $this->type, $this->lundi('09:00')),
            'vient d\'être pris'
        );
        $this->assertSame(1, RendezVous::count());
    }

    public function test_la_reservation_prend_le_verrou_du_medecin(): void
    {
        // Une autre connexion tient le verrou consultatif de ce médecin : la
        // réservation attend (ici, abandonne au bout de 200 ms).
        config(['database.connections.pgsql_concurrente' => config('database.connections.pgsql')]);
        $concurrente = DB::connection('pgsql_concurrente');
        $concurrente->beginTransaction();
        $concurrente->select('SELECT pg_advisory_xact_lock(hashtext(?))', ['medecin:'.$this->medecin->id]);

        DB::statement("SET lock_timeout = '200ms'");
        try {
            $this->reserver();
            $this->fail('La réservation aurait dû attendre le verrou du médecin.');
        } catch (QueryException $e) {
            $this->assertSame('55P03', $e->getCode(), 'lock_not_available');
        } finally {
            DB::statement('SET lock_timeout = 0');
            $concurrente->rollBack();
            $concurrente->disconnect();
        }

        $this->assertSame(0, RendezVous::count());
    }

    public function test_le_paiement_confirme_le_rendez_vous_dans_le_delai(): void
    {
        $rdv = $this->reserver();
        $this->travel(14)->minutes();

        $confirme = $this->service->confirmerPaiement($rdv);

        $this->assertSame(StatutRendezVous::Confirme, $confirme->statut);
        $this->assertSame(StatutRendezVous::Confirme, $rdv->fresh()->statut);
    }

    public function test_un_paiement_recu_deux_fois_ne_change_rien(): void
    {
        $rdv = $this->reserver();
        $this->service->confirmerPaiement($rdv);

        $this->assertSame(StatutRendezVous::Confirme, $this->service->confirmerPaiement($rdv)->statut);
    }

    public function test_un_paiement_tardif_est_refuse_et_libere_le_creneau(): void
    {
        $rdv = $this->reserver();
        $this->travel(15)->minutes();

        $this->refus(fn () => $this->service->confirmerPaiement($rdv), 'délai de paiement est dépassé');

        $this->assertSame(StatutRendezVous::Expire, $rdv->fresh()->statut);
        $this->assertSame('ouverte', $rdv->demande->fresh()->statut);
    }

    public function test_un_rendez_vous_annule_ne_se_confirme_pas(): void
    {
        $rdv = $this->reserver();
        $this->service->annulerParPatient($rdv);

        $this->refus(fn () => $this->service->confirmerPaiement($rdv), 'ne peut plus être confirmé');
        $this->assertSame(StatutRendezVous::AnnuleParPatient, $rdv->fresh()->statut);
    }

    public function test_l_expiration_ne_touche_que_les_attentes_echues(): void
    {
        $echue = $this->reserver(User::factory()->create(), '09:00');
        $this->travel(10)->minutes();
        $encours = $this->reserver(User::factory()->create(), '09:20');
        $confirme = $this->rendezVousExistant($this->medecin, User::factory()->create(), $this->lundi('09:40'));
        $this->travel(5)->minutes();

        $this->assertSame(1, $this->service->expirerAttentesEchues());

        $this->assertSame(StatutRendezVous::Expire, $echue->fresh()->statut);
        $this->assertSame(StatutRendezVous::EnAttentePaiement, $encours->fresh()->statut);
        $this->assertSame(StatutRendezVous::Confirme, $confirme->fresh()->statut);
        $this->assertSame(0, $this->service->expirerAttentesEchues(), 'Rejouer ne change rien.');
    }

    public function test_les_heures_recues_en_utc_sont_ramenees_a_kinshasa(): void
    {
        $rdv = $this->service->reserver($this->demande(User::factory()->create()), $this->medecin, $this->type, CarbonImmutable::parse('2026-12-07T08:00:00Z'));

        $this->assertSame('2026-12-07 09:00', $rdv->fresh()->debut->format('Y-m-d H:i'));
        $this->assertSame('2026-12-07 09:00:00', DB::table('rendez_vous')->value('debut'));
    }
}
