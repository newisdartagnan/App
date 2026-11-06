<?php

namespace Tests\Feature\Teleconsultation;

use App\Enums\MotifRemboursement;
use App\Enums\StatutConsultation;
use App\Enums\StatutRendezVous;
use App\Models\Consultation;
use App\Models\Medecin;
use App\Models\MessageConsultation;
use App\Models\RendezVous;
use App\Models\User;
use App\Services\Teleconsultation\AccesConsultation;
use App\Services\Teleconsultation\AccesRefuse;
use App\Services\Teleconsultation\ServeursIce;
use Database\Seeders\RolesSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Support\ParcoursRendezVous;
use Tests\Support\PersonnelDeTest;
use Tests\TestCase;

/** Socle de la téléconsultation : qui entre dans la salle, quand, et ce que la base garantit. */
class SocleTeleconsultationTest extends TestCase
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
        $this->rdv = $this->rendezVousExistant($this->medecin, $this->patient, $this->lundi('09:00'));
    }

    private function acces(): AccesConsultation
    {
        return app(AccesConsultation::class);
    }

    public function test_seuls_le_patient_et_son_medecin_entrent(): void
    {
        $this->assertSame('patient', $this->acces()->role($this->patient, $this->rdv));
        $this->assertSame('medecin', $this->acces()->role($this->medecin->user, $this->rdv));

        $autres = [$this->patient(), $this->medecinActive()->user, $this->personnelActive('caisse'), $this->personnelActive('admin_fonctionnel')];
        foreach ($autres as $autre) {
            $this->assertNull($this->acces()->role($autre, $this->rdv));
            try {
                $this->acces()->entrer($autre, $this->rdv);
                $this->fail('Entrée acceptée pour un tiers.');
            } catch (AccesRefuse) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame(0, Consultation::count());
    }

    public function test_un_medecin_desactive_n_entre_plus(): void
    {
        $this->medecin->user->forceFill(['desactive_le' => now()])->save();

        $this->assertNull($this->acces()->role($this->medecin->user->fresh(), $this->rdv));
    }

    public function test_la_salle_ouvre_dix_minutes_avant_et_ferme_une_heure_apres_la_fin(): void
    {
        $this->travelTo($this->lundi('08:49'));
        try {
            $this->acces()->entrer($this->patient, $this->rdv);
            $this->fail('Salle ouverte trop tôt.');
        } catch (AccesRefuse $e) {
            $this->assertStringContainsString('ouvre 10 minutes avant', $e->getMessage());
        }

        $this->travelTo($this->lundi('08:50'));
        [$salle] = $this->acces()->entrer($this->patient, $this->rdv);
        $this->assertSame(StatutConsultation::EnAttente, $salle->statut);

        $this->travelTo($this->lundi('10:21'));
        $this->expectException(AccesRefuse::class);
        $this->acces()->entrer($this->medecin->user, $this->rdv);
    }

    public function test_un_rendez_vous_non_confirme_n_a_pas_de_salle(): void
    {
        foreach ([StatutRendezVous::EnAttentePaiement, StatutRendezVous::AnnuleParPatient, StatutRendezVous::Expire] as $i => $statut) {
            $rdv = $this->rendezVousExistant($this->medecin, $this->patient(), $this->lundi('09:00')->addMinutes(30 * ($i + 1)), statut: $statut);
            $this->travelTo($rdv->debut);
            try {
                $this->acces()->entrer($rdv->patient, $rdv);
                $this->fail("Salle ouverte pour un rendez-vous {$statut->value}.");
            } catch (AccesRefuse) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame(0, Consultation::count());
    }

    public function test_la_consultation_est_en_cours_quand_les_deux_sont_venus_et_une_reconnexion_rouvre_la_meme(): void
    {
        [$a] = $this->acces()->entrer($this->patient, $this->rdv);
        [$b, $role] = $this->acces()->entrer($this->medecin->user, $this->rdv);

        $this->assertTrue($a->is($b));
        $this->assertSame('medecin', $role);
        $this->assertSame(StatutConsultation::EnCours, $b->statut);
        $this->assertNotNull($b->debut_le);

        // Coupure puis retour du patient (TLC-04, RG-07) : même salle, reconnexion comptée.
        [$c] = $this->acces()->entrer($this->patient, $this->rdv);
        $this->assertTrue($c->is($a));
        $this->assertSame(1, $c->reconnexions);
        $this->assertSame(1, Consultation::count());
    }

    public function test_une_consultation_close_ne_se_rouvre_pas(): void
    {
        [$salle] = $this->acces()->entrer($this->patient, $this->rdv);
        $this->acces()->entrer($this->medecin->user, $this->rdv);
        DB::transaction(function () use ($salle) {
            $s = Consultation::whereKey($salle->id)->lockForUpdate()->first();
            $s->transitionVers(StatutConsultation::Terminee);
            $s->save();
        });

        $this->expectException(AccesRefuse::class);
        $this->acces()->entrer($this->patient, $this->rdv);
    }

    public function test_les_transitions_interdites_sont_refusees(): void
    {
        [$salle] = $this->acces()->entrer($this->patient, $this->rdv);

        $this->assertFalse(StatutConsultation::EnCours->peutPasserA(StatutConsultation::PatientAbsent));
        $this->assertSame([], StatutConsultation::Terminee->transitionsAutorisees());
        $this->expectException(LogicException::class);
        $salle->transitionVers(StatutConsultation::Terminee);
    }

    public function test_un_message_de_consultation_ne_se_modifie_ni_ne_se_supprime(): void
    {
        [$salle] = $this->acces()->entrer($this->patient, $this->rdv);
        $message = new MessageConsultation;
        $message->forceFill(['consultation_id' => $salle->id, 'auteur_id' => $this->patient->id, 'texte' => 'Bonjour docteur'])->save();

        foreach (['UPDATE messages_consultation SET texte = \'x\'', 'DELETE FROM messages_consultation'] as $sql) {
            try {
                DB::transaction(fn () => DB::statement($sql));
                $this->fail("La base a accepté : {$sql}");
            } catch (QueryException $e) {
                $this->assertStringContainsString('non modifiable', $e->getMessage());
            }
        }

        // Ni vide : un texte ou une photo.
        $this->expectException(QueryException::class);
        (new MessageConsultation)->forceFill(['consultation_id' => $salle->id, 'auteur_id' => $this->patient->id])->save();
    }

    public function test_les_identifiants_turn_sont_ephemeres_et_signes(): void
    {
        config([
            'doc-and-i.teleconsultation.stun' => 'stun:turn.exemple.cd:3478',
            'doc-and-i.teleconsultation.turn' => 'turn:turn.exemple.cd:3478?transport=udp, turns:turn.exemple.cd:5349',
            'doc-and-i.teleconsultation.turn_secret' => str_repeat('s', 32),
        ]);

        [$stun, $turn] = app(ServeursIce::class)->pour($this->patient);

        $this->assertSame(['stun:turn.exemple.cd:3478'], $stun['urls']);
        $this->assertCount(2, $turn['urls']);
        [$expire, $id] = explode(':', $turn['username']);
        $this->assertSame($this->patient->id, $id);
        $this->assertGreaterThan(time(), (int) $expire);
        $this->assertSame(base64_encode(hash_hmac('sha1', $turn['username'], str_repeat('s', 32), true)), $turn['credential']);

        // Sans secret suffisant, pas de TURN (jamais d'identifiant permanent).
        config(['doc-and-i.teleconsultation.turn_secret' => 'court']);
        $this->assertCount(1, app(ServeursIce::class)->pour($this->patient));
    }

    public function test_l_absence_du_medecin_est_un_remboursement_automatique(): void
    {
        $this->assertTrue(MotifRemboursement::AbsenceMedecin->estAutomatique());
    }
}
