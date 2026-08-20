<?php

namespace Tests\Feature;

use App\Models\Billetage;
use App\Models\Caution;
use App\Models\Establishment;
use App\Models\ImputationAcompte;
use App\Models\Paiement;
use App\Models\Patient;
use App\Models\Service;
use App\Models\User;
use App\Models\Visit;
use App\Services\AcompteService;
use App\Services\ConventionService;
use App\Services\DeviseService;
use App\Services\FacturationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * La devise n'est pas décorative : elle conditionne le montant réellement
 * encaissé, imputé, remboursé et compté au guichet.
 */
class DevisesTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Establishment $etab;

    protected Patient $patient;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->user = User::where('email', 'admin@dpi-rdc.local')->firstOrFail();
        $this->actingAs($this->user);
        $this->etab = Establishment::firstOrFail();

        $this->patient = Patient::create([
            'establishment_id' => $this->etab->id,
            'dossier_number' => 'PAT-2026-009900',
            'nom' => 'BADIBANGA', 'postnom' => 'LUBOYA', 'prenom' => 'Guy',
            'sexe' => 'M',
            'date_naissance' => now()->subYears(44)->toDateString(),
            'type_prise_en_charge' => 'prive',
        ]);
    }

    protected function sejour(): Visit
    {
        return Visit::create([
            'patient_id' => $this->patient->id,
            'establishment_id' => $this->etab->id,
            'user_id' => $this->user->id,
            'type' => 'hospitalisation',
            'statut' => 'en_cours',
            'date_entree' => now()->subDays(2),
            'service_id' => Service::where('is_active', true)->first()?->id,
            'motif_consultation' => 'Surveillance',
        ]);
    }

    // ═══════════════════════════════════════════════════════════
    // Référentiel
    // ═══════════════════════════════════════════════════════════

    public function test_les_taux_du_referentiel_sont_ceux_de_letablissement(): void
    {
        $devises = app(DeviseService::class);

        $this->assertSame('CDF', $devises->pivot());
        $this->assertSame(1.0, $devises->taux('CDF'));
        $this->assertSame(2300.0, $devises->taux('USD'));
        $this->assertSame(2681.50, $devises->taux('EUR'));
    }

    public function test_la_conversion_passe_par_le_franc_congolais(): void
    {
        $devises = app(DeviseService::class);

        $this->assertSame(230000.0, $devises->versCdf(100, 'USD'));
        $this->assertSame(268150.0, $devises->versCdf(100, 'EUR'));
        $this->assertSame(100.0, $devises->depuisCdf(230000, 'USD'));

        // 100 € valent 268 150 CDF, soit 116,59 $ à 2 300.
        $this->assertSame(116.59, $devises->convertir(100, 'EUR', 'USD'));
    }

    public function test_un_montant_est_affiche_avec_sa_contre_valeur(): void
    {
        $devises = app(DeviseService::class);

        $this->assertSame('100,00 $ (230 000 CDF)', $devises->formaterAvecContreValeur(100, 'USD'));
        $this->assertSame('50 000 CDF', $devises->formaterAvecContreValeur(50000, 'CDF'));
    }

    // ═══════════════════════════════════════════════════════════
    // Acomptes en devises
    // ═══════════════════════════════════════════════════════════

    public function test_un_acompte_en_dollars_est_stocke_avec_son_taux_et_sa_contre_valeur(): void
    {
        $visite = $this->sejour();

        $this->post(route('acomptes.store', $visite), [
            'montant' => 100, 'devise' => 'USD',
            'mode_paiement' => 'especes', 'type' => 'acompte',
        ])->assertSessionHas('success');

        $acompte = Caution::where('visit_id', $visite->id)->firstOrFail();

        $this->assertSame('USD', $acompte->devise);
        $this->assertSame(100.0, (float) $acompte->montant);
        $this->assertSame(2300.0, $acompte->tauxApplique());
        $this->assertSame(230000.0, (float) $acompte->montant_cdf);
        $this->assertSame('100,00 $ (230 000 CDF)', $acompte->montantFormate());
    }

    public function test_deux_acomptes_en_dollars_ne_sont_plus_comptes_en_francs(): void
    {
        $visite = $this->sejour();
        $acomptes = app(AcompteService::class);

        $acomptes->encaisser($visite, 100, 'USD');
        $acomptes->encaisser($visite, 50, 'USD');

        // Le solde du séjour est exprimé en monnaie de compte…
        $this->assertSame(345000.0, $acomptes->soldeDisponible($visite->id));

        // … mais le guichet détient bien 150 dollars, pas 150 francs.
        $this->assertSame(['USD' => 150.0], $acomptes->soldeParDevise($this->patient->id));
    }

    public function test_un_acompte_en_dollars_solde_une_facture_en_francs_a_sa_juste_valeur(): void
    {
        $visite = $this->sejour();

        // 100 $ = 230 000 CDF, largement de quoi couvrir trois journées.
        app(AcompteService::class)->encaisser($visite, 100, 'USD');

        $facture = app(FacturationService::class)->creerFactureHospitalisation($visite);
        $facture->refresh();

        $tarif = (float) config('dpi.tarifs_cdf.hospitalisation_jour');
        $du = 3 * $tarif;

        $this->assertSame($du, (float) $facture->patient_part);
        $this->assertSame($du, (float) $facture->acompte_impute, 'La facture est couverte en francs.');
        $this->assertSame('payee', $facture->statut);
        $this->assertSame(0.0, $facture->soldeRestant());

        // L'acompte s'est vidé en dollars, à hauteur de la contre-valeur.
        $acompte = Caution::where('visit_id', $visite->id)->firstOrFail();

        $this->assertSame(round($du / 2300, 2), (float) $acompte->montant_impute);
        $this->assertSame($du, (float) $acompte->montant_impute_cdf);
        $this->assertSame(100.0 - round($du / 2300, 2), $acompte->resteDisponible());
    }

    public function test_limputation_trace_les_deux_devises(): void
    {
        $visite = $this->sejour();
        app(AcompteService::class)->encaisser($visite, 100, 'USD');

        $facture = app(FacturationService::class)->creerFactureHospitalisation($visite);

        $imputation = ImputationAcompte::where('facture_id', $facture->id)->firstOrFail();

        // Ce qui est porté sur la facture est en francs…
        $this->assertSame('CDF', $imputation->devise);
        $this->assertSame((float) $facture->fresh()->acompte_impute, (float) $imputation->montant);

        // … et ce qui a été prélevé sur l'acompte est en dollars.
        $this->assertSame(round((float) $imputation->montant_cdf / 2300, 2), (float) $imputation->montant_acompte);
        $this->assertStringContainsString('$', $imputation->preleveFormate());
        $this->assertStringContainsString('CDF', $imputation->montantFormate());
    }

    public function test_un_reliquat_en_dollars_se_rembourse_en_dollars(): void
    {
        $visite = $this->sejour();
        app(AcompteService::class)->encaisser($visite, 200, 'USD');
        app(FacturationService::class)->creerFactureHospitalisation($visite);

        $rendus = app(AcompteService::class)->rembourser($visite);

        $this->assertArrayHasKey('USD', $rendus);
        $this->assertArrayNotHasKey('CDF', $rendus);
        $this->assertGreaterThan(0, $rendus['USD']);
        $this->assertSame(0.0, app(AcompteService::class)->soldeDisponible($visite->id));
    }

    public function test_le_guichet_mobilise_des_acomptes_de_devises_differentes(): void
    {
        $visite = $this->sejour();
        $acomptes = app(AcompteService::class);

        // 10 $ = 23 000 CDF, complétés par 20 000 CDF.
        $acomptes->encaisser($visite, 10, 'USD');
        $acomptes->encaisser($visite, 20000, 'CDF');

        $this->assertSame(43000.0, $acomptes->soldeDisponible($visite->id));
        $this->assertSame(['USD' => 10.0, 'CDF' => 20000.0], $acomptes->soldeParDevise($this->patient->id));

        $facture = app(FacturationService::class)->creerFactureHospitalisation($visite);
        $facture->refresh();

        // Le séjour coûte 105 000 CDF : les deux acomptes y passent en entier.
        $this->assertSame(43000.0, (float) $facture->acompte_impute);
        $this->assertSame(0.0, $acomptes->soldeDisponible($visite->id));
        $this->assertSame(2, ImputationAcompte::where('facture_id', $facture->id)->count());
    }

    public function test_une_revision_du_taux_ne_reecrit_pas_un_acompte_deja_verse(): void
    {
        $visite = $this->sejour();
        app(AcompteService::class)->encaisser($visite, 100, 'USD');

        // Le dollar monte après coup.
        config(['dpi.devises.USD.taux_cdf' => 2800]);

        $acompte = Caution::where('visit_id', $visite->id)->firstOrFail();

        $this->assertSame(2300.0, $acompte->tauxApplique(), 'Le taux du versement est figé.');
        $this->assertSame(230000.0, (float) $acompte->montant_cdf);
        $this->assertSame(230000.0, app(AcompteService::class)->soldeDisponible($visite->id));
    }

    // ═══════════════════════════════════════════════════════════
    // Paiements
    // ═══════════════════════════════════════════════════════════

    public function test_un_encaissement_en_dollars_est_enregistre_comme_tel(): void
    {
        $visite = $this->sejour();
        $facture = app(FacturationService::class)->creerFactureHospitalisation($visite);

        $this->post(route('caisse.encaisser', $facture), [
            'montant' => 50, 'devise' => 'USD', 'mode_paiement' => 'especes',
        ])->assertSessionMissing('errors');

        $paiement = Paiement::where('facture_id', $facture->id)->firstOrFail();

        $this->assertSame('USD', $paiement->devise);
        $this->assertSame(50.0, (float) $paiement->montant);
        $this->assertSame(2300.0, (float) $paiement->taux_change);
        $this->assertSame(115000.0, (float) $paiement->montant_cdf);
        $this->assertStringContainsString('115 000 CDF', $paiement->montantFormate());
    }

    public function test_un_reglement_partiel_ne_solde_pas_la_facture(): void
    {
        $visite = $this->sejour();
        $facture = app(FacturationService::class)->creerFactureHospitalisation($visite);

        $du = (float) $facture->patient_part;

        // 10 $ = 23 000 CDF sur 105 000 dus.
        $this->post(route('caisse.encaisser', $facture), [
            'montant' => 10, 'devise' => 'USD', 'mode_paiement' => 'especes',
        ]);

        $facture->refresh();

        $this->assertSame('partiellement_payee', $facture->statut);
        $this->assertSame(23000.0, $facture->montantPaye());
        $this->assertSame($du - 23000.0, $facture->soldeRestant());
    }

    public function test_un_paiement_mixte_solde_la_facture(): void
    {
        $visite = $this->sejour();
        $facture = app(FacturationService::class)->creerFactureHospitalisation($visite);

        $du = (float) $facture->patient_part; // 105 000 CDF

        $this->post(route('caisse.encaisser', $facture), [
            'montant' => 40, 'devise' => 'USD', 'mode_paiement' => 'especes',
        ]);
        $this->post(route('caisse.encaisser', $facture->fresh()), [
            'montant' => $du - 92000, 'devise' => 'CDF', 'mode_paiement' => 'especes',
        ]);

        $facture->refresh();

        $this->assertSame($du, $facture->montantPaye());
        $this->assertSame('payee', $facture->statut);
        $this->assertSame(0.0, $facture->soldeRestant());
    }

    public function test_la_facture_porte_sa_devise_et_son_taux(): void
    {
        $facture = app(FacturationService::class)->creerFactureHospitalisation($this->sejour());

        $this->assertSame('CDF', $facture->deviseFacture());
        $this->assertSame(1.0, $facture->tauxApplique());
        $this->assertStringContainsString('CDF', $facture->formater(1000));
    }

    // ═══════════════════════════════════════════════════════════
    // Billetage
    // ═══════════════════════════════════════════════════════════

    public function test_les_coupures_du_franc_congolais_sarretent_a_50(): void
    {
        $coupures = Billetage::coupuresPour('CDF');

        $this->assertSame(50, min($coupures), 'La plus petite coupure en circulation est 50 CDF.');
        $this->assertSame(20000, max($coupures));
        $this->assertNotContains(1, $coupures);
        $this->assertNotContains(10, $coupures);
        $this->assertNotContains(20, $coupures);
    }

    public function test_les_trois_devises_sont_comptables_au_guichet(): void
    {
        $this->assertNotEmpty(Billetage::coupuresPour('USD'));
        $this->assertNotEmpty(Billetage::coupuresPour('EUR'));
        $this->assertContains(500, Billetage::coupuresPour('EUR'));

        $this->get(route('caisse.billetage'))
            ->assertOk()
            ->assertSee('Franc congolais')
            ->assertSee('Dollar américain')
            ->assertSee('Euro')
            ->assertSee('50 CDF')
            ->assertDontSee('>1 CDF<', false);
    }

    public function test_le_theorique_du_billetage_ne_melange_pas_les_devises(): void
    {
        $visite = $this->sejour();
        $facture = app(FacturationService::class)->creerFactureHospitalisation($visite);

        // Un encaissement en dollars ne doit pas gonfler le tiroir de francs.
        $this->post(route('caisse.encaisser', $facture), [
            'montant' => 50, 'devise' => 'USD', 'mode_paiement' => 'especes',
        ]);

        $conventions = app(ConventionService::class);
        $debut = now()->startOfDay()->toDateTimeString();
        $fin = now()->endOfDay()->toDateTimeString();

        $this->assertSame(0.0, $conventions->recettesEspeces($debut, $fin, 'CDF'));
        $this->assertSame(50.0, $conventions->recettesEspeces($debut, $fin, 'USD'));
    }

    public function test_un_billetage_en_euros_est_accepte(): void
    {
        $this->post(route('caisse.billetage.store'), [
            'devise' => 'EUR',
            'debut' => now()->startOfDay()->format('Y-m-d H:i:s'),
            'fin' => now()->endOfDay()->format('Y-m-d H:i:s'),
            'coupures' => [50 => 3, 20 => 2],
        ])->assertSessionHas('success');

        $billetage = Billetage::where('devise', 'EUR')->firstOrFail();

        $this->assertSame(190.0, (float) $billetage->total_compte);
    }
}
