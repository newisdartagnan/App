<?php

namespace Tests\Feature\RendezVous;

use App\Enums\StatutRendezVous;
use App\Models\Specialite;
use App\Models\User;
use App\Services\RendezVous\CreneauxService;
use App\Services\RendezVous\ReservationRefusee;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ParcoursRendezVous;
use Tests\TestCase;

/** RDV-04 : créneaux libres d'un médecin, heure de Kinshasa. */
class CreneauxServiceTest extends TestCase
{
    use ParcoursRendezVous, RefreshDatabase;

    private CreneauxService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesSeeder::class);
        $this->service = app(CreneauxService::class);
    }

    public function test_les_plages_hebdomadaires_sont_decoupees_selon_la_duree_du_type_sur_l_horizon(): void
    {
        $this->voyagerAuLundi('07:00');
        $medecin = $this->medecin([[1, '09:00', '10:00']]);
        $type = $this->typeConsultation($medecin, '10.00', 20);

        $this->assertSame([
            '2026-12-07 09:00', '2026-12-07 09:20', '2026-12-07 09:40',
            '2026-12-14 09:00', '2026-12-14 09:20', '2026-12-14 09:40',
        ], $this->heures($this->service->creneaux($medecin, $type)), 'Horizon de 14 jours : le lundi 21 n\'y est pas.');
    }

    public function test_un_creneau_qui_deborderait_de_la_plage_n_est_pas_propose(): void
    {
        $this->voyagerAuLundi('07:00');
        $medecin = $this->medecin([[1, '09:00', '09:50']]);
        $type = $this->typeConsultation($medecin, '10.00', 20);

        $this->assertSame(
            ['2026-12-07 09:00', '2026-12-07 09:20', '2026-12-14 09:00', '2026-12-14 09:20'],
            $this->heures($this->service->creneaux($medecin, $type)),
            '09:40 + 20 min dépasserait 09:50.'
        );
    }

    public function test_l_horizon_suit_la_configuration(): void
    {
        $this->voyagerAuLundi('07:00');
        config(['doc-and-i.rendez_vous.horizon_jours' => 21]);
        $medecin = $this->medecin([[1, '09:00', '09:20']]);
        $type = $this->typeConsultation($medecin, '10.00', 20);

        $this->assertSame(['2026-12-07 09:00', '2026-12-14 09:00', '2026-12-21 09:00'], $this->heures($this->service->creneaux($medecin, $type)));
    }

    public function test_les_creneaux_passes_ou_trop_proches_ne_sont_pas_proposes(): void
    {
        $this->voyagerAuLundi('09:15');
        $medecin = $this->medecin([[1, '09:00', '10:20']]);
        $type = $this->typeConsultation($medecin, '10.00', 20);

        // 09:00 est passé ; 09:20 et 09:40 commencent dans moins de 30 minutes.
        $this->assertSame(['2026-12-07 10:00', '2026-12-14 09:00'], array_slice($this->heures($this->service->creneaux($medecin, $type)), 0, 2));

        $this->voyagerAuLundi('09:10');
        $this->assertSame('2026-12-07 09:40', $this->heures($this->service->creneaux($medecin, $type))[0], '09:40 commence dans 30 minutes : accepté.');
    }

    public function test_une_absence_du_medecin_retire_les_creneaux_qu_elle_touche(): void
    {
        $this->voyagerAuLundi('07:00');
        $medecin = $this->medecin([[1, '09:00', '10:00']]);
        $type = $this->typeConsultation($medecin, '10.00', 20);
        $medecin->absences()->create(['debut' => $this->lundi('09:30'), 'fin' => $this->lundi('09:45')]);
        $medecin->absences()->create(['debut' => $this->lundi('00:00')->addDays(7), 'fin' => $this->lundi('23:59')->addDays(7)]);

        $this->assertSame(['2026-12-07 09:00'], $this->heures($this->service->creneaux($medecin, $type)));
    }

    public function test_un_rendez_vous_occupant_retire_tout_creneau_qu_il_chevauche_quelle_que_soit_sa_duree(): void
    {
        $this->voyagerAuLundi('07:00');
        $medecin = $this->medecin([[1, '09:00', '10:00']]);
        $type = $this->typeConsultation($medecin, '10.00', 20);
        // Une consultation de 30 minutes à 09:10 touche 09:00-09:20 et 09:20-09:40.
        $this->rendezVousExistant($medecin, User::factory()->create(), $this->lundi('09:10'), 30);

        $this->assertSame(['2026-12-07 09:40'], array_slice($this->heures($this->service->creneaux($medecin, $type)), 0, 1));
        $this->assertCount(4, $this->service->creneaux($medecin, $type));
    }

    public function test_une_attente_de_paiement_bloque_le_creneau_tant_qu_elle_n_est_pas_echue(): void
    {
        $this->voyagerAuLundi('07:00');
        $medecin = $this->medecin([[1, '09:00', '09:40']]);
        $type = $this->typeConsultation($medecin, '10.00', 20);
        $this->rendezVousExistant($medecin, User::factory()->create(), $this->lundi('09:00'), 20, StatutRendezVous::EnAttentePaiement, now()->addMinutes(15));
        $this->rendezVousExistant($medecin, User::factory()->create(), $this->lundi('09:20'), 20, StatutRendezVous::EnAttentePaiement, now()->subMinute());

        $this->assertSame(['2026-12-07 09:20'], array_slice($this->heures($this->service->creneaux($medecin, $type)), 0, 1), 'Une attente échue libère le créneau.');

        $this->travel(16)->minutes();
        $this->assertSame(['2026-12-07 09:00', '2026-12-07 09:20'], array_slice($this->heures($this->service->creneaux($medecin, $type)), 0, 2));
    }

    public function test_un_rendez_vous_annule_ou_expire_libere_le_creneau(): void
    {
        $this->voyagerAuLundi('07:00');
        $medecin = $this->medecin([[1, '09:00', '09:40']]);
        $type = $this->typeConsultation($medecin, '10.00', 20);
        $this->rendezVousExistant($medecin, User::factory()->create(), $this->lundi('09:00'), 20, StatutRendezVous::AnnuleParPatient);
        $this->rendezVousExistant($medecin, User::factory()->create(), $this->lundi('09:20'), 20, StatutRendezVous::Expire);

        $this->assertSame(['2026-12-07 09:00', '2026-12-07 09:20'], array_slice($this->heures($this->service->creneaux($medecin, $type)), 0, 2));
    }

    public function test_les_rendez_vous_d_un_autre_medecin_ne_comptent_pas(): void
    {
        $this->voyagerAuLundi('07:00');
        $medecin = $this->medecin([[1, '09:00', '09:20']]);
        $autre = $this->medecin([[1, '09:00', '09:20']]);
        $type = $this->typeConsultation($medecin, '10.00', 20);
        $this->rendezVousExistant($autre, User::factory()->create(), $this->lundi('09:00'));

        $this->assertSame('2026-12-07 09:00', $this->heures($this->service->creneaux($medecin, $type))[0]);
    }

    public function test_les_heures_sont_celles_de_kinshasa_sans_changement_d_heure(): void
    {
        // 29 mars 2026, 07:00 UTC : l'Europe passe à l'heure d'été, pas Kinshasa (UTC+1 toute l'année).
        $this->travelTo(CarbonImmutable::parse('2026-03-29 06:00:00', 'UTC'));
        $medecin = $this->medecin([[1, '09:00', '09:20'], [7, '09:00', '09:20']]);
        $type = $this->typeConsultation($medecin, '10.00', 20);

        $creneaux = $this->service->creneaux($medecin, $type);

        $this->assertSame('2026-03-29T09:00:00+01:00', $creneaux[0]->toIso8601String(), 'Dimanche 29 mars, 09:00 à Kinshasa.');
        $this->assertSame('2026-03-29 08:00:00', $creneaux[0]->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-03-30T09:00:00+01:00', $creneaux[1]->toIso8601String());
        foreach ($creneaux as $creneau) {
            $this->assertSame('09:00', $creneau->format('H:i'));
            $this->assertSame('+01:00', $creneau->format('P'));
        }
    }

    public function test_seuls_les_creneaux_proposes_sont_reconnus(): void
    {
        $this->voyagerAuLundi('07:00');
        $medecin = $this->medecin([[1, '09:00', '10:00']]);
        $type = $this->typeConsultation($medecin, '10.00', 20);

        $this->assertTrue($this->service->estPropose($medecin, $type, $this->lundi('09:20')));
        $this->assertTrue($this->service->estPropose($medecin, $type, CarbonImmutable::parse('2026-12-07T08:20:00Z')), 'Même instant exprimé en UTC.');
        $this->assertFalse($this->service->estPropose($medecin, $type, $this->lundi('09:05')), 'Hors de la grille.');
        $this->assertFalse($this->service->estPropose($medecin, $type, $this->lundi('11:00')), 'Hors des plages.');
        $this->assertFalse($this->service->estPropose($medecin, $type, $this->lundi('09:00')->subWeek()), 'Dans le passé.');
        $this->assertFalse($this->service->estPropose($medecin, $type, $this->lundi('09:00')->addWeeks(3)), 'Au-delà de l\'horizon.');
        $this->assertFalse($this->service->estPropose($medecin, $type, $this->lundi('09:00')->addDay()), 'Mardi : pas de plage.');
    }

    public function test_un_rendez_vous_peut_etre_ignore_pour_son_propre_report(): void
    {
        $this->voyagerAuLundi('07:00');
        $medecin = $this->medecin([[1, '09:00', '09:40']]);
        $type = $this->typeConsultation($medecin, '10.00', 20);
        $rdv = $this->rendezVousExistant($medecin, User::factory()->create(), $this->lundi('09:00'), 20, StatutRendezVous::Confirme, null, $type);

        $this->assertFalse($this->service->estPropose($medecin, $type, $this->lundi('09:00')));
        $this->assertTrue($this->service->estPropose($medecin, $type, $this->lundi('09:00'), $rdv->id));
    }

    public function test_premier_medecin_disponible_reunit_les_medecins_de_la_specialite(): void
    {
        $this->voyagerAuLundi('07:00');
        $premier = $this->medecin([[1, '10:00', '10:20']]);
        $second = $this->medecin([[1, '09:00', '09:20'], [1, '10:00', '10:20']]);
        $nonVerifie = $this->medecin([[1, '08:00', '08:20']], null, []);
        $nonVerifie->forceFill(['ordre_verifie_le' => null])->save();
        $type = $this->typeConsultation($premier, '10.00', 20);

        $creneaux = $this->service->premiersDisponibles($type);

        $this->assertSame('2026-12-07 09:00', $creneaux[0]['debut']->format('Y-m-d H:i'));
        $this->assertSame($second->id, $creneaux[0]['medecin']->id);
        $this->assertSame('2026-12-07 10:00', $creneaux[1]['debut']->format('Y-m-d H:i'));
        $this->assertCount(4, $creneaux, 'Une heure n\'apparaît qu\'une fois, même si deux médecins sont libres.');
        $this->assertNotContains($nonVerifie->id, array_map(fn ($c) => $c['medecin']->id, $creneaux));
    }

    public function test_un_type_sans_prix_ou_un_medecin_non_verifie_ne_se_reserve_pas(): void
    {
        $medecin = $this->medecin();

        try {
            $this->service->verifierOffre($medecin, $this->typeConsultation($medecin, null));
            $this->fail('Un type sans prix ne doit pas se réserver.');
        } catch (ReservationRefusee $e) {
            $this->assertStringContainsString('prix', $e->getMessage());
        }

        $medecin->forceFill(['ordre_verifie_le' => null])->save();
        $this->expectException(ReservationRefusee::class);
        $this->service->verifierOffre($medecin, $this->typeConsultation($medecin, '10.00'));
    }

    public function test_un_type_d_une_autre_specialite_ou_inactif_ne_se_reserve_pas_chez_ce_medecin(): void
    {
        $medecin = $this->medecin();
        $autreSpecialite = Specialite::create(['nom' => 'Dermatologie', 'slug' => 'dermatologie']);

        foreach ([$this->typeConsultation($autreSpecialite, '10.00'), $this->typeConsultation($medecin, '10.00', 20, 'USD', false)] as $type) {
            try {
                $this->service->verifierOffre($medecin, $type);
                $this->fail('Type refusé attendu.');
            } catch (ReservationRefusee) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
