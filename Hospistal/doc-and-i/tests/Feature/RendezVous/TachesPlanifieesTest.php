<?php

namespace Tests\Feature\RendezVous;

use App\Enums\StatutRendezVous;
use App\Models\Medecin;
use App\Models\Specialite;
use App\Models\User;
use App\Services\RendezVous\RappelsService;
use App\Sms\EnvoyeurSms;
use Database\Seeders\RolesSeeder;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Support\EnvoyeurSmsFactice;
use Tests\Support\ParcoursRendezVous;
use Tests\TestCase;

/** Expiration des attentes de paiement (RDV-05) et rappels par SMS (RDV-06, NOT-02). */
class TachesPlanifieesTest extends TestCase
{
    use ParcoursRendezVous, RefreshDatabase;

    private EnvoyeurSmsFactice $sms;

    private Medecin $medecin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesSeeder::class);
        $this->sms = new EnvoyeurSmsFactice;
        $this->app->instance(EnvoyeurSms::class, $this->sms);
        $psychiatrie = Specialite::create(['nom' => 'Psychiatrie', 'slug' => 'psychiatrie']);
        $this->medecin = $this->medecin([], $psychiatrie);
        $this->medecin->user->forceFill(['nom' => 'MUKENDI'])->save();
    }

    private function planifiee(string $commande): ?Event
    {
        return collect(app(Schedule::class)->events())
            ->first(fn ($evenement) => str_contains((string) $evenement->command, $commande));
    }

    public function test_la_commande_expire_les_attentes_echues_seulement(): void
    {
        $this->voyagerAuLundi('07:00');
        $echue = $this->rendezVousExistant($this->medecin, User::factory()->create(), $this->lundi('10:00'), 20, StatutRendezVous::EnAttentePaiement, now()->subSecond());
        $encours = $this->rendezVousExistant($this->medecin, User::factory()->create(), $this->lundi('11:00'), 20, StatutRendezVous::EnAttentePaiement, now()->addMinute());

        $this->artisan('rdv:expirer-attentes')->assertSuccessful();

        $this->assertSame(StatutRendezVous::Expire, $echue->fresh()->statut);
        $this->assertSame('ouverte', $echue->demande->fresh()->statut);
        $this->assertSame(StatutRendezVous::EnAttentePaiement, $encours->fresh()->statut);
    }

    public function test_l_expiration_tourne_chaque_minute_sans_chevauchement(): void
    {
        $evenement = $this->planifiee('rdv:expirer-attentes');

        $this->assertNotNull($evenement, 'rdv:expirer-attentes doit être planifiée (routes/console.php).');
        $this->assertSame('* * * * *', $evenement->expression);
        $this->assertTrue($evenement->withoutOverlapping);
    }

    public function test_les_rappels_tournent_toutes_les_cinq_minutes_sans_chevauchement(): void
    {
        $evenement = $this->planifiee('rdv:envoyer-rappels');

        $this->assertNotNull($evenement, 'rdv:envoyer-rappels doit être planifiée (routes/console.php).');
        $this->assertSame('*/5 * * * *', $evenement->expression);
        $this->assertTrue($evenement->withoutOverlapping);
    }

    public function test_rappel_la_veille_puis_une_heure_avant_une_seule_fois_chacun(): void
    {
        $patient = User::factory()->create();
        $rdv = $this->rendezVousExistant($this->medecin, $patient, $this->lundi('10:30')->addDay());

        $this->voyagerAuLundi('10:00');
        $this->artisan('rdv:envoyer-rappels')->assertSuccessful();
        $this->assertCount(0, $this->sms->envoyes, 'Plus de 24 h avant : rien.');

        $this->voyagerAuLundi('10:31');
        $this->artisan('rdv:envoyer-rappels');
        $this->artisan('rdv:envoyer-rappels');
        $this->assertCount(1, $this->sms->envoyes);
        $this->assertSame($patient->telephone, $this->sms->envoyes[0]['telephone']);
        $this->assertSame('Doc&I : rappel, votre consultation est demain a 10:30. Ouvrez l\'application quelques minutes avant.', $this->sms->envoyes[0]['message']);
        $this->assertNotNull($rdv->fresh()->rappel_24h_envoye_le);

        $this->travelTo($this->lundi('09:35')->addDay());
        $this->artisan('rdv:envoyer-rappels');
        $this->artisan('rdv:envoyer-rappels');
        $this->assertCount(2, $this->sms->envoyes);
        $this->assertSame('Doc&I : rappel, votre consultation commence a 10:30. Ouvrez l\'application quelques minutes avant.', $this->sms->envoyes[1]['message']);
        $this->assertNotNull($rdv->fresh()->rappel_1h_envoye_le);

        $this->travelTo($this->lundi('10:25')->addDay());
        $this->artisan('rdv:envoyer-rappels');
        $this->assertCount(2, $this->sms->envoyes);
    }

    public function test_un_rendez_vous_pris_moins_d_une_heure_avant_ne_recoit_que_le_dernier_rappel(): void
    {
        $this->voyagerAuLundi('09:45');
        $this->rendezVousExistant($this->medecin, User::factory()->create(), $this->lundi('10:30'));

        $this->artisan('rdv:envoyer-rappels');

        $this->assertCount(1, $this->sms->envoyes);
        $this->assertStringContainsString('commence a 10:30', $this->sms->envoyes[0]['message']);
    }

    public function test_un_rappel_le_jour_meme_dit_aujourd_hui(): void
    {
        $this->voyagerAuLundi('07:00');
        $this->rendezVousExistant($this->medecin, User::factory()->create(), $this->lundi('15:00'));

        $this->artisan('rdv:envoyer-rappels');

        $this->assertSame('Doc&I : rappel, votre consultation est aujourd\'hui a 15:00. Ouvrez l\'application quelques minutes avant.', $this->sms->envoyes[0]['message']);
    }

    public function test_aucun_rappel_pour_un_rendez_vous_annule_expire_ou_non_paye(): void
    {
        $this->voyagerAuLundi('07:00');
        foreach ([StatutRendezVous::AnnuleParPatient, StatutRendezVous::AnnuleParMedecin, StatutRendezVous::Expire] as $i => $statut) {
            $this->rendezVousExistant($this->medecin, User::factory()->create(), $this->lundi('07:30')->addMinutes(30 * $i), 20, $statut);
        }
        $this->rendezVousExistant($this->medecin, User::factory()->create(), $this->lundi('09:00'), 20, StatutRendezVous::EnAttentePaiement);

        $this->artisan('rdv:envoyer-rappels');

        $this->assertCount(0, $this->sms->envoyes);
    }

    public function test_le_sms_ne_contient_aucune_donnee_medicale(): void
    {
        $this->voyagerAuLundi('07:00');
        $rdv = $this->rendezVousExistant($this->medecin, User::factory()->create(), $this->lundi('07:45'));
        $rdv->demande->forceFill(['motif' => 'Idées noires', 'symptomes' => 'Insomnie'])->save();

        $this->artisan('rdv:envoyer-rappels');

        $message = $this->sms->envoyes[0]['message'];
        foreach (['Psychiatrie', 'psychiatr', 'MUKENDI', 'Idées', 'Insomnie', $rdv->typeConsultation->nom] as $interdit) {
            $this->assertStringNotContainsStringIgnoringCase($interdit, $message);
        }
    }

    public function test_deux_executions_simultanees_n_envoient_pas_deux_fois(): void
    {
        $this->voyagerAuLundi('07:00');
        $rdv = $this->rendezVousExistant($this->medecin, User::factory()->create(), $this->lundi('07:45'));
        $service = app(RappelsService::class);

        // Le premier passage réserve l'envoi ; le second, arrivé juste après, ne le peut plus.
        $this->assertTrue($service->reserverEnvoi($rdv->id, 'rappel_1h_envoye_le'));
        $this->assertFalse($service->reserverEnvoi($rdv->id, 'rappel_1h_envoye_le'));

        $this->artisan('rdv:envoyer-rappels');
        $this->assertCount(0, $this->sms->envoyes);
    }

    public function test_un_envoi_en_echec_sera_retente(): void
    {
        $this->voyagerAuLundi('07:00');
        $rdv = $this->rendezVousExistant($this->medecin, User::factory()->create(), $this->lundi('07:45'));
        $this->app->instance(EnvoyeurSms::class, new class implements EnvoyeurSms
        {
            public function envoyer(string $telephone, string $message): void
            {
                throw new RuntimeException('Opérateur injoignable');
            }
        });

        $this->artisan('rdv:envoyer-rappels');
        $this->assertNull($rdv->fresh()->rappel_1h_envoye_le);

        $this->app->instance(EnvoyeurSms::class, $this->sms);
        $this->artisan('rdv:envoyer-rappels');
        $this->assertCount(1, $this->sms->envoyes);
        $this->assertNotNull($rdv->fresh()->rappel_1h_envoye_le);
    }
}
