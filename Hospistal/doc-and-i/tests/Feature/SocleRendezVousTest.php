<?php

namespace Tests\Feature;

use App\Enums\StatutRendezVous;
use App\Models\DemandeConsultation;
use App\Models\Medecin;
use App\Models\RendezVous;
use App\Models\TypeConsultation;
use App\Models\User;
use App\Sms\EnvoyeurSms;
use Database\Seeders\ReferentielMedicalSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\Support\EnvoyeurSmsFactice;
use Tests\TestCase;

/** Socle du lot rendez-vous : garanties portées par la base et le modèle. */
class SocleRendezVousTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesSeeder::class, ReferentielMedicalSeeder::class]);
    }

    private function rendezVous(Medecin $medecin, User $patient, string $debut, StatutRendezVous $statut = StatutRendezVous::Confirme): RendezVous
    {
        $type = TypeConsultation::where('specialite_id', $medecin->specialite_id)->firstOrFail();
        $demande = new DemandeConsultation(['motif' => 'Fièvre', 'tri_reponses' => [], 'tri_alerte' => false]);
        $demande->patient_id = $patient->id;
        $demande->save();

        $rdv = new RendezVous;
        $rdv->forceFill([
            'demande_id' => $demande->id,
            'patient_id' => $patient->id,
            'medecin_id' => $medecin->id,
            'type_consultation_id' => $type->id,
            'debut' => $debut,
            'fin' => \Carbon\CarbonImmutable::parse($debut)->addMinutes(20),
            'statut' => $statut,
            'devise' => 'USD',
        ])->save();

        return $rdv;
    }

    public function test_le_referentiel_propose_les_specialites_sans_fixer_de_prix(): void
    {
        $this->assertSame(count(ReferentielMedicalSeeder::SPECIALITES), \App\Models\Specialite::count());
        $this->assertSame(0, TypeConsultation::whereNotNull('prix')->count(), 'Les prix sont une décision du client.');

        $this->seed(ReferentielMedicalSeeder::class);
        $this->assertSame(count(ReferentielMedicalSeeder::SPECIALITES), \App\Models\Specialite::count(), 'Le seeder se rejoue sans doublon.');
    }

    public function test_la_base_refuse_deux_rendez_vous_occupants_au_meme_creneau(): void
    {
        $medecin = Medecin::factory()->create();
        $this->rendezVous($medecin, User::factory()->create(), '2026-12-01 09:00:00');

        $this->expectException(QueryException::class);
        $this->rendezVous($medecin, User::factory()->create(), '2026-12-01 09:00:00');
    }

    public function test_un_creneau_libere_par_annulation_peut_etre_repris(): void
    {
        $medecin = Medecin::factory()->create();
        $this->rendezVous($medecin, User::factory()->create(), '2026-12-01 09:00:00', StatutRendezVous::AnnuleParPatient);

        $this->rendezVous($medecin, User::factory()->create(), '2026-12-01 09:00:00');
        $this->assertSame(2, RendezVous::count());
    }

    public function test_un_patient_ne_bloque_qu_un_creneau_a_la_fois_en_attente_de_paiement(): void
    {
        $medecin = Medecin::factory()->create();
        $patient = User::factory()->create();
        $this->rendezVous($medecin, $patient, '2026-12-01 09:00:00', StatutRendezVous::EnAttentePaiement);

        $this->expectException(QueryException::class);
        $this->rendezVous($medecin, $patient, '2026-12-01 10:00:00', StatutRendezVous::EnAttentePaiement);
    }

    public function test_le_modele_refuse_une_transition_interdite(): void
    {
        $rdv = $this->rendezVous(Medecin::factory()->create(), User::factory()->create(), '2026-12-01 09:00:00', StatutRendezVous::Termine);

        $this->expectException(LogicException::class);
        $rdv->transitionVers(StatutRendezVous::Confirme);
    }

    public function test_aucun_attribut_du_rendez_vous_n_est_affectable_par_formulaire(): void
    {
        $this->expectException(\Illuminate\Database\Eloquent\MassAssignmentException::class);
        (new RendezVous)->fill(['statut' => 'confirme', 'prix' => 0]);
    }

    public function test_seuls_les_medecins_actifs_et_verifies_sont_reservables(): void
    {
        $verifie = Medecin::factory()->create();
        Medecin::factory()->nonVerifie()->create();
        Medecin::factory()->create(['actif' => false]);

        $this->assertEquals([$verifie->id], Medecin::reservables()->pluck('id')->all());
    }

    public function test_un_compte_du_personnel_ne_se_connecte_pas_par_code_sms(): void
    {
        $sms = new EnvoyeurSmsFactice;
        $this->app->instance(EnvoyeurSms::class, $sms);
        $medecin = Medecin::factory()->create();

        $this->post(route('connexion.demander'), ['telephone' => $medecin->user->telephone]);
        $this->post(route('connexion.verifier'), ['code' => $sms->dernierCode()])
            ->assertSessionHasErrors('code');

        $this->assertGuest();
    }

    public function test_le_personnel_n_entre_pas_dans_l_espace_patient(): void
    {
        $this->actingAs(Medecin::factory()->create()->user)
            ->get(route('espace'))
            ->assertForbidden();
    }
}
