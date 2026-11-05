<?php

namespace Tests\Feature\Paiement;

use App\Enums\StatutPaiement;
use App\Enums\StatutRendezVous;
use App\Models\EvenementPaiement;
use App\Models\Facture;
use App\Models\Medecin;
use App\Models\Paiement;
use App\Models\RendezVous;
use App\Models\User;
use App\Services\Paiement\FacturationService;
use App\Services\Paiement\SimulationPaiement;
use App\Sms\EnvoyeurSms;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Tests\Support\EnvoyeurSmsFactice;
use Tests\Support\ParcoursPaiement;
use Tests\Support\ParcoursRendezVous;
use Tests\Support\PersonnelDeTest;
use Tests\TestCase;

/** PAY-04, PAY-05 : suivi du paiement, simulation locale, « Mes paiements », factures en PDF. */
class EcransPaiementTest extends TestCase
{
    use ParcoursPaiement, ParcoursRendezVous, PersonnelDeTest, RefreshDatabase;

    private User $patient;

    private Medecin $medecin;

    private RendezVous $rdv;

    private Paiement $paiement;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesSeeder::class);
        $this->voyagerAuLundi('07:00');
        $this->app->instance(EnvoyeurSms::class, new EnvoyeurSmsFactice);
        $this->patient = $this->patient();
        $this->medecin = $this->medecin([[1, '09:00', '12:00']]);
        $this->rdv = $this->rendezVousExistant($this->medecin, $this->patient, $this->lundi('09:00'), 20, StatutRendezVous::EnAttentePaiement,
            type: $this->typeConsultation($this->medecin, '10.00'));
        $this->paiement = $this->paiementExistant($this->rdv, StatutPaiement::EnAttente);
    }

    private function facture(): Facture
    {
        $this->envoyerRappel($this->simulation()->rappelSigne('paiement', $this->paiement->cle_idempotence,
            (string) $this->paiement->reference_fournisseur, true, '10.00', 'USD'))->assertOk();

        return Facture::sole();
    }

    public function test_la_page_de_suivi_attend_la_confirmation_du_telephone(): void
    {
        $this->actingAs($this->patient)->get(route('paiement.suivi', $this->paiement))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Paiement/Suivi')
                ->where('paiement.en_cours', true)
                ->where('paiement.montant', '10,00 USD')
                ->where('paiement.moyen', 'M-Pesa')
                ->where('paiement.telephone', '+243 81 ••• •• 01')
                ->where('paiement.facture', null)
                ->where('peutReessayer', false)
                ->where('simulation', true));
    }

    public function test_apres_le_rappel_la_page_montre_le_recu(): void
    {
        $facture = $this->facture();

        $this->actingAs($this->patient)->get(route('paiement.suivi', $this->paiement))
            ->assertInertia(fn ($page) => $page
                ->where('paiement.statut', 'reussi')
                ->where('paiement.facture.numero', $facture->numero)
                ->where('rendezVous.statut', 'confirme')
                ->where('simulation', false));
    }

    public function test_la_simulation_passe_par_le_meme_traitement_que_le_vrai_rappel(): void
    {
        $this->actingAs($this->patient)
            ->post(route('paiement.simulation', $this->paiement), ['resultat' => 'reussi'])
            ->assertRedirect(route('paiement.suivi', $this->paiement));

        $this->assertSame(StatutPaiement::Reussi, $this->paiement->fresh()->statut);
        $this->assertSame(StatutRendezVous::Confirme, $this->rdv->fresh()->statut);
        $this->assertSame(1, EvenementPaiement::count(), 'Un événement signé enregistré, comme un vrai rappel.');
        $this->assertSame(1, Facture::count());
    }

    public function test_la_simulation_d_un_echec(): void
    {
        $this->actingAs($this->patient)->post(route('paiement.simulation', $this->paiement), ['resultat' => 'echoue']);

        $this->assertSame(StatutPaiement::Echoue, $this->paiement->fresh()->statut);
        $this->assertSame(StatutRendezVous::EnAttentePaiement, $this->rdv->fresh()->statut);
    }

    public function test_la_simulation_est_refusee_pour_le_paiement_d_un_autre_ou_un_paiement_clos(): void
    {
        $this->actingAs($this->patient())->post(route('paiement.simulation', $this->paiement), ['resultat' => 'reussi'])->assertNotFound();
        $this->assertSame(StatutPaiement::EnAttente, $this->paiement->fresh()->statut);

        $this->paiement->forceFill(['statut' => StatutPaiement::Echoue])->save();
        $this->actingAs($this->patient)->post(route('paiement.simulation', $this->paiement), ['resultat' => 'reussi'])
            ->assertSessionHasErrors('paiement');
        $this->assertSame(StatutPaiement::Echoue, $this->paiement->fresh()->statut);
    }

    public function test_la_simulation_est_absente_en_production(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        $this->assertFalse(SimulationPaiement::disponible());

        // Les routes chargées en production ne déclarent pas la simulation.
        $routeur = new Router($this->app['events'], $this->app);
        $original = $this->app['router'];
        $this->app->instance('router', $routeur);
        Route::clearResolvedInstance('router');
        try {
            require base_path('routes/paiement.php');
            $routeur->getRoutes()->refreshNameLookups();
            $this->assertNotNull($routeur->getRoutes()->getByName('paiement.suivi'));
            $this->assertNull($routeur->getRoutes()->getByName('paiement.simulation'));
        } finally {
            $this->app->instance('router', $original);
            Route::clearResolvedInstance('router');
        }

        // Et le contrôleur revérifie, même si la route existait.
        $this->withoutMiddleware(ValidateCsrfToken::class)
            ->actingAs($this->patient)->post('/paiements/'.$this->paiement->id.'/simulation', ['resultat' => 'reussi'])->assertNotFound();
        $this->assertSame(StatutPaiement::EnAttente, $this->paiement->fresh()->statut);
    }

    public function test_la_simulation_est_absente_avec_un_vrai_prestataire(): void
    {
        config(['doc-and-i.paiement.fournisseur' => 'agregateur']);

        $this->assertFalse(SimulationPaiement::disponible());
    }

    public function test_mes_paiements_ne_montre_que_les_miens(): void
    {
        $facture = $this->facture();
        $autre = $this->patient();
        $autreRdv = $this->rendezVousExistant($this->medecin, $autre, $this->lundi('10:00'));
        $this->paiementExistant($autreRdv);

        $this->actingAs($this->patient)->get(route('paiement.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Paiement/MesPaiements')
                ->has('paiements', 1)
                ->where('paiements.0.id', $this->paiement->id)
                ->where('paiements.0.statut_libelle', 'Payé')
                ->has('documents', 1)
                ->where('documents.0.numero', $facture->numero)
                ->where('documents.0.type', 'Facture'));
    }

    public function test_le_patient_telecharge_sa_facture_en_pdf(): void
    {
        $facture = $this->facture();

        $reponse = $this->actingAs($this->patient)->get(route('paiement.facture', $facture));

        $reponse->assertOk();
        $reponse->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringContainsString($facture->numero.'.pdf', $reponse->headers->get('Content-Disposition'));
        $this->assertStringContainsString('no-store', $reponse->headers->get('Cache-Control'));
        $this->assertStringStartsWith('%PDF-', $reponse->getContent());
    }

    public function test_la_page_pdf_ne_charge_aucune_ressource_externe_et_porte_les_mentions(): void
    {
        $facture = $this->facture();

        $html = view('pdf.facture', [
            'facture' => $facture->load(['patient', 'origine']),
            'type' => 'Facture',
            'emise' => '07/12/2026 à 07:00',
            'patient' => $this->patient->nomComplet(),
            'montant' => '10,00 USD',
            'moyen' => 'M-Pesa',
        ])->render();

        foreach (['MEDI', 'HOME', $facture->numero, '07/12/2026', $this->patient->nomComplet(), $facture->libelle, '10,00 USD', 'M-Pesa'] as $mention) {
            $this->assertStringContainsString(e($mention), $html);
        }
        $this->assertDoesNotMatchRegularExpression('#(src|href)\s*=|url\(|@import|https?://#i', $html);
    }

    public function test_un_avoir_se_telecharge_aussi(): void
    {
        $facture = $this->facture();
        $avoir = new Facture;
        $avoir->forceFill([
            'numero' => app(FacturationService::class)->numeroSuivant('AV'),
            'type' => 'avoir', 'patient_id' => $this->patient->id, 'paiement_id' => $this->paiement->id,
            'facture_origine_id' => $facture->id, 'libelle' => 'Avoir sur la facture '.$facture->numero,
            'montant' => '10.00', 'devise' => 'USD', 'moyen' => 'mpesa', 'emise_le' => now(),
        ])->save();

        $this->actingAs($this->patient)->get(route('paiement.facture', $avoir))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->get(route('paiement.index'))->assertInertia(fn ($page) => $page->has('documents', 2));
    }

    public function test_un_autre_patient_n_accede_ni_au_paiement_ni_a_la_facture(): void
    {
        $facture = $this->facture();
        $intrus = $this->patient();

        $this->actingAs($intrus);
        $this->get(route('paiement.suivi', $this->paiement))->assertNotFound();
        $this->get(route('paiement.facture', $facture))->assertNotFound();
        $this->get(route('paiement.payer', $this->rdv))->assertNotFound();
        $this->get('/paiements/abc')->assertNotFound();
        $this->get('/factures/abc')->assertNotFound();
    }

    public function test_le_personnel_et_les_visiteurs_n_entrent_pas(): void
    {
        $facture = $this->facture();
        $caisse = $this->personnelActive('caisse');

        foreach ([route('paiement.suivi', $this->paiement), route('paiement.facture', $facture), route('paiement.index')] as $adresse) {
            $this->commePersonnel($caisse)->get($adresse)->assertForbidden();
        }

        auth()->logout();
        $this->get(route('paiement.index'))->assertRedirect(route('connexion'));
        $this->get(route('paiement.facture', $facture))->assertRedirect(route('connexion'));
    }

    public function test_le_rendez_vous_montre_son_paiement_son_recu_et_l_etat_du_remboursement(): void
    {
        $facture = $this->facture();
        // Lundi 07:00, rendez-vous à 09:00 : exactement 2 h avant, moitié remboursée (RG-04).
        $this->actingAs($this->patient)->post(route('rdv.annuler', $this->rdv))->assertSessionHasNoErrors();

        $this->get(route('rdv.voir', $this->rdv))
            ->assertInertia(fn ($page) => $page
                ->has('paiements', 1)
                ->where('paiements.0.statut', 'reussi')
                ->where('paiements.0.facture.numero', $facture->numero)
                ->has('paiements.0.remboursements', 1)
                ->where('paiements.0.remboursements.0.montant', '5,00 USD')
                ->where('paiements.0.remboursements.0.statut', 'À valider par la caisse')
                ->where('paiements.0.remboursements.0.motif', 'Annulation par le patient'));
    }
}
