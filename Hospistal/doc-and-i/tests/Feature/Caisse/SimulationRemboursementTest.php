<?php

namespace Tests\Feature\Caisse;

use App\Enums\MotifRemboursement;
use App\Enums\StatutRemboursement;
use App\Models\Facture;
use App\Models\OperationCaisse;
use App\Models\Remboursement;
use App\Services\Paiement\RemboursementService;
use App\Services\Paiement\SimulationPaiement;
use App\Sms\EnvoyeurSms;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Tests\Support\EnvoyeurSmsFactice;
use Tests\Support\ParcoursCaisse;
use Tests\Support\ParcoursPaiement;
use Tests\Support\ParcoursRendezVous;
use Tests\Support\PersonnelDeTest;
use Tests\TestCase;

/**
 * Développement : la caisse déclenche le rappel signé qu'enverrait
 * l'opérateur pour un remboursement « en cours », comme le patient le fait
 * pour un paiement. Sans cela, en local, un remboursement validé ne se
 * termine jamais (PAY-07, PAY-08).
 */
class SimulationRemboursementTest extends TestCase
{
    use ParcoursCaisse, ParcoursPaiement, ParcoursRendezVous, PersonnelDeTest, RefreshDatabase;

    private EnvoyeurSmsFactice $sms;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesSeeder::class);
        $this->voyagerAuLundi();
        $this->sms = new EnvoyeurSmsFactice;
        $this->app->instance(EnvoyeurSms::class, $this->sms);
        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    /** Remboursement de règle, envoyé à l'opérateur (pilote « simulation »). */
    private function remboursementEnCours(): Remboursement
    {
        $service = app(RemboursementService::class);
        $r = $service->executer($service->demander($this->paiementEncaisse(), '10.00', MotifRemboursement::PaiementTardif));
        $this->assertSame(StatutRemboursement::EnCours, $r->statut);

        return $r;
    }

    private function url(Remboursement $r): string
    {
        return '/personnel/caisse/remboursements/'.$r->id.'/simulation';
    }

    public function test_la_caisse_simule_un_remboursement_reussi_par_le_meme_chemin_que_l_operateur(): void
    {
        $r = $this->remboursementEnCours();
        $caisse = $this->personnelActive('caisse');

        $this->commePersonnel($caisse)->get('/personnel/caisse/remboursements')->assertOk()
            ->assertInertia(fn ($page) => $page->where('recents.0.simulable', true));

        $this->commePersonnel($caisse)->post($this->url($r), ['resultat' => 'reussi'])->assertRedirect()->assertSessionHasNoErrors();
        // Double envoi : le remboursement n'est plus « en cours », rien de plus.
        $this->commePersonnel($caisse)->post($this->url($r), ['resultat' => 'reussi'])->assertSessionHasErrors('remboursement');

        $this->assertSame(StatutRemboursement::Reussi, $r->fresh()->statut);
        $this->assertSame(1, Facture::where('type', 'avoir')->count());
        $this->assertSame(1, OperationCaisse::where('type', 'remboursement')->count());
        $this->assertCount(1, array_filter($this->sms->envoyes, fn ($s) => str_contains($s['message'], 'remboursement de 10,00 USD')));
    }

    public function test_un_refus_simule_libere_le_montant(): void
    {
        $r = $this->remboursementEnCours();

        $this->commePersonnel($this->personnelActive('caisse'))->post($this->url($r), ['resultat' => 'echoue'])->assertRedirect();

        $this->assertSame(StatutRemboursement::Echoue, $r->fresh()->statut);
        $this->assertSame('0.00', (string) $r->paiement->fresh()->montant_rembourse);
        $this->assertSame(0, Facture::where('type', 'avoir')->count());
    }

    public function test_un_remboursement_qui_n_est_pas_en_cours_ne_se_simule_pas(): void
    {
        $r = app(RemboursementService::class)->demander($this->paiementEncaisse(), '10.00', MotifRemboursement::AnnulationPatient);

        $this->commePersonnel($this->personnelActive('caisse'))->post($this->url($r), ['resultat' => 'reussi'])
            ->assertSessionHasErrors('remboursement');
        $this->assertSame(StatutRemboursement::AValider, $r->fresh()->statut);
    }

    public function test_un_autre_role_ne_simule_rien(): void
    {
        $r = $this->remboursementEnCours();

        $this->actingAs($r->paiement->patient)->post($this->url($r), ['resultat' => 'reussi'])->assertForbidden();
        $this->commePersonnel($this->medecinActive()->user)->post($this->url($r), ['resultat' => 'reussi'])->assertForbidden();
        $this->commePersonnel($this->personnelActive('support'))->post($this->url($r), ['resultat' => 'reussi'])->assertForbidden();

        $this->assertSame(StatutRemboursement::EnCours, $r->fresh()->statut);
    }

    public function test_la_simulation_est_absente_hors_local_et_tests(): void
    {
        $r = $this->remboursementEnCours();
        $this->app->detectEnvironment(fn () => 'staging');

        try {
            $this->assertFalse(SimulationPaiement::disponible());

            // Les routes chargées hors local et tests ne déclarent pas la simulation.
            $routeur = new Router($this->app['events'], $this->app);
            $original = $this->app['router'];
            $this->app->instance('router', $routeur);
            Route::clearResolvedInstance('router');
            try {
                require base_path('routes/personnel.php');
                $routeur->getRoutes()->refreshNameLookups();
                $this->assertNotNull($routeur->getRoutes()->getByName('personnel.caisse.remboursements.valider'));
                $this->assertNull($routeur->getRoutes()->getByName('personnel.caisse.remboursements.simuler'));
            } finally {
                $this->app->instance('router', $original);
                Route::clearResolvedInstance('router');
            }

            // Et le contrôleur revérifie, même si la route existait.
            $this->commePersonnel($this->personnelActive('caisse'))->post($this->url($r), ['resultat' => 'reussi'])->assertNotFound();
            $this->assertSame(StatutRemboursement::EnCours, $r->fresh()->statut);
        } finally {
            $this->app->detectEnvironment(fn () => 'testing');
        }
    }
}
