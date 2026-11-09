<?php

namespace Tests\Feature\Laboratoire;

use App\Enums\StatutBonExamen;
use App\Models\AlerteMedecin;
use App\Models\EntreeAccesDossier;
use App\Models\Partenaire;
use App\Models\ResultatExamen;
use App\Models\User;
use App\Sms\EnvoyeurSms;
use Database\Seeders\RolesSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Support\EnvoyeurSmsFactice;
use Tests\Support\ParcoursConsultation;
use Tests\Support\ParcoursLaboratoire;
use Tests\Support\ParcoursPaiement;
use Tests\Support\ParcoursRendezVous;
use Tests\Support\PersonnelDeTest;
use Tests\TestCase;

/** PRE-08, RG-10 : escalades au coordinateur à 2 h (critique) et à 48 h (validation), validation à la place du médecin. */
class EscaladesTest extends TestCase
{
    use ParcoursConsultation, ParcoursLaboratoire, ParcoursPaiement, ParcoursRendezVous, PersonnelDeTest, RefreshDatabase;

    private Partenaire $labo;

    private User $compte;

    private User $coordinateur;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesSeeder::class);
        $this->preparerConsultation('09:05');
        $this->app->instance(EnvoyeurSms::class, new EnvoyeurSmsFactice);
        $this->labo = $this->laboratoire();
        $this->compte = $this->compteLaboratoire($this->labo);
        $this->coordinateur = $this->personnelActive('coordinateur');
    }

    private function escalader(): void
    {
        $this->assertSame(0, Artisan::call('laboratoire:escalader-alertes'));
    }

    public function test_un_resultat_critique_non_traite_sous_2_h_est_escalade_une_fois(): void
    {
        $bon = $this->bonEnCours($this->labo, $this->compte, [$this->examen()]);
        $this->deposer($this->labo, $this->compte, $bon, critique: true);
        $critique = AlerteMedecin::where('type', 'resultat_critique')->sole();

        $this->travel(119)->minutes();
        $this->escalader();
        $this->assertNull($critique->fresh()->escaladee_le);
        $this->assertSame(0, DB::table('alertes_coordination')->count());

        $this->travel(2)->minutes();
        $this->escalader();
        $this->assertNotNull($critique->fresh()->escaladee_le);
        $escalade = DB::table('alertes_coordination')->sole();
        $this->assertSame(['critique_non_traite', $bon->id, $critique->id], [$escalade->type, $escalade->bon_id, $escalade->alerte_medecin_id]);
        $this->assertStringNotContainsString('Glycémie', $escalade->message);

        $this->escalader();
        $this->assertSame(1, DB::table('alertes_coordination')->count(), 'Relancée, la tâche ne double rien.');

        $this->commePersonnel($this->coordinateur)->get(route('personnel.accueil'))->assertRedirect(route('personnel.coordination.accueil'));
        $this->commePersonnel($this->coordinateur)->get(route('personnel.coordination.accueil'))->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->component('Laboratoire/Coordination')->has('escalades', 1)->where('escalades.0.type', 'critique_non_traite'));

        // Lecture possible (journalisée), validation non : ce n'est pas un retard de 48 h.
        $resultat = ResultatExamen::sole();
        $this->commePersonnel($this->coordinateur)->get(route('personnel.coordination.bon', $bon->id))->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->where('peutValider', false));
        $this->assertSame(1, EntreeAccesDossier::where('lecteur_id', $this->coordinateur->id)->count());
        $this->commePersonnel($this->coordinateur)->post(route('personnel.coordination.resultats.valider', $resultat->id))->assertForbidden();

        $this->commePersonnel($this->coordinateur)->post(route('personnel.coordination.escalades.traiter', $escalade->id));
        $this->assertNotNull(DB::table('alertes_coordination')->value('traitee_le'));
    }

    public function test_une_alerte_critique_traitee_a_temps_n_est_pas_escaladee(): void
    {
        $bon = $this->bonEnCours($this->labo, $this->compte, [$this->examen()]);
        $this->deposer($this->labo, $this->compte, $bon, critique: true);
        $critique = AlerteMedecin::where('type', 'resultat_critique')->sole();
        $this->commeMedecinRdv()->post(route('personnel.medecin.alertes.marquer', [$critique->id, 'traitee']));

        $this->travel(3)->hours();
        $this->escalader();

        $this->assertSame(0, DB::table('alertes_coordination')->where('type', 'critique_non_traite')->count());
    }

    public function test_a_valider_depuis_48_h_le_coordinateur_valide_a_la_place_du_medecin_trace(): void
    {
        $bon = $this->bonEnCours($this->labo, $this->compte, [$this->examen()]);
        $resultat = $this->deposer($this->labo, $this->compte, $bon);

        $this->travel(47)->hours();
        $this->escalader();
        $this->commePersonnel($this->coordinateur)->post(route('personnel.coordination.resultats.valider', $resultat->id))->assertForbidden();

        $this->travel(2)->hours();
        $this->escalader();
        $this->assertSame('validation_en_retard', DB::table('alertes_coordination')->value('type'));
        $this->assertNotNull(AlerteMedecin::where('type', 'resultat_a_valider')->sole()->escaladee_le);

        $this->commePersonnel($this->coordinateur)->get(route('personnel.coordination.bon', $bon->id))
            ->assertInertia(fn (AssertableInertia $p) => $p->where('peutValider', true)->where('enRemplacement', true));
        $this->commePersonnel($this->coordinateur)->post(route('personnel.coordination.resultats.valider', $resultat->id), ['commentaire' => 'Validé en l\'absence du médecin.'])
            ->assertSessionHasNoErrors();

        $resultat->refresh();
        $this->assertSame([$this->coordinateur->id, true], [$resultat->valide_par, $resultat->valide_en_remplacement]);
        $this->assertSame(StatutBonExamen::Valide, $bon->fresh()->statut);
        $this->assertNotNull(DB::table('alertes_coordination')->value('traitee_le'), 'L\'escalade est close.');
        $this->assertSame($this->coordinateur->id, DB::table('alertes_coordination')->value('traitee_par'));

        // Un autre rôle ne valide toujours pas.
        $this->assertNotSame(302, $this->commePersonnel($this->personnelActive('caisse'))->post(route('personnel.coordination.resultats.valider', $resultat->id))->status());
    }

    public function test_la_tache_est_planifiee_toutes_les_cinq_minutes(): void
    {
        $evenement = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, 'laboratoire:escalader-alertes'));

        $this->assertNotNull($evenement);
        $this->assertSame('*/5 * * * *', $evenement->expression);
    }
}
