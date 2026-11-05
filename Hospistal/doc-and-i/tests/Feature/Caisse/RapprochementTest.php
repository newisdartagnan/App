<?php

namespace Tests\Feature\Caisse;

use App\Enums\StatutPaiement;
use App\Models\EcartRapprochement;
use App\Models\LigneReleve;
use App\Models\User;
use App\Services\Caisse\RapprochementService;
use App\Services\Paiement\Fournisseur\LigneReleveFournisseur;
use App\Services\Paiement\Fournisseur\RappelFournisseur;
use App\Services\Paiement\PaiementRefuse;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\FournisseurFactice;
use Tests\Support\ParcoursCaisse;
use Tests\Support\ParcoursPaiement;
use Tests\Support\ParcoursRendezVous;
use Tests\Support\PersonnelDeTest;
use Tests\TestCase;

/** PAY-06 : rapprochement quotidien avec le relevé de l'agrégateur. */
class RapprochementTest extends TestCase
{
    use ParcoursCaisse, ParcoursPaiement, ParcoursRendezVous, PersonnelDeTest, RefreshDatabase;

    private FournisseurFactice $fournisseur;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesSeeder::class);
        $this->voyagerAuLundi();
        $this->fournisseur = $this->fournisseurFactice();
    }

    private function ligne(string $type, ?string $reference, ?string $marchand, string $montant, string $devise = 'USD', string $statut = 'reussi'): LigneReleveFournisseur
    {
        return new LigneReleveFournisseur($type, $reference, $marchand, $montant, $devise, $statut);
    }

    /** Le relevé du lundi : un paiement concordant, un manquant, un inconnu, un montant faux, un statut différent, un remboursement manquant. */
    private function journeeAvecEcarts(): array
    {
        $concordant = $this->paiementEncaisse();
        $absentDuReleve = $this->paiementEncaisse();
        $montantFaux = $this->paiementEncaisse(prix: '15.00');
        $echoueChezNous = $this->paiementExistant(
            $this->rendezVousExistant($this->medecin(), $this->patient(), $this->lundi('16:00')), StatutPaiement::Echoue);
        $rembourse = $this->remboursementReussi($this->paiementEncaisse(), '10.00');

        $this->fournisseur->releve = [
            $this->ligne(RappelFournisseur::PAIEMENT, $concordant->reference_fournisseur, $concordant->cle_idempotence, '10.00'),
            $this->ligne(RappelFournisseur::PAIEMENT, 'OPE-INCONNUE-1', null, '7.00'),
            $this->ligne(RappelFournisseur::PAIEMENT, $montantFaux->reference_fournisseur, $montantFaux->cle_idempotence, '14.00'),
            $this->ligne(RappelFournisseur::PAIEMENT, $echoueChezNous->reference_fournisseur, $echoueChezNous->cle_idempotence, '10.00'),
        ];
        // Le paiement remboursé est au relevé, son remboursement n'y est pas.
        $this->fournisseur->releve[] = $this->ligne(RappelFournisseur::PAIEMENT, $rembourse->paiement->reference_fournisseur, null, '10.00');

        return compact('concordant', 'absentDuReleve', 'montantFaux', 'echoueChezNous', 'rembourse');
    }

    public function test_chaque_difference_avec_le_releve_devient_un_ecart(): void
    {
        $cas = $this->journeeAvecEcarts();

        $resultat = app(RapprochementService::class)->rapprocher($this->lundi());

        $this->assertSame(['lignes' => 5, 'ecarts' => 5], $resultat);
        $ecarts = EcartRapprochement::orderBy('nature')->get()->map(fn ($e) => [$e->nature, $e->paiement_id ?? $e->remboursement_id ?? $e->reference_fournisseur])->all();
        $this->assertEqualsCanonicalizing([
            ['absent_chez_nous', 'OPE-INCONNUE-1'],
            ['absent_du_releve', $cas['absentDuReleve']->id],
            ['absent_du_releve', $cas['rembourse']->id],
            ['montant_different', $cas['montantFaux']->id],
            ['statut_different', $cas['echoueChezNous']->id],
        ], $ecarts);

        $montant = EcartRapprochement::where('nature', 'montant_different')->sole();
        $this->assertSame(['15.00', '14.00', 'USD', 'ouvert', '2026-12-07'],
            [(string) $montant->montant_attendu, (string) $montant->montant_releve, $montant->devise, $montant->statut, $montant->jour->toDateString()]);
    }

    public function test_relance_le_rapprochement_n_importe_ni_ne_cree_de_doublon(): void
    {
        $this->journeeAvecEcarts();
        $service = app(RapprochementService::class);
        $service->rapprocher($this->lundi());
        $traite = EcartRapprochement::where('nature', 'absent_chez_nous')->sole();
        $service->traiter($traite, $this->personnelActive('caisse'), 'Opération d\'un autre marchand');

        $second = $service->rapprocher($this->lundi());

        $this->assertSame(0, $second['ecarts']);
        $this->assertSame(5, EcartRapprochement::count());
        $this->assertSame(5, LigneReleve::count(), 'Import idempotent : une ligne par référence du prestataire.');
        $this->assertSame('traite', $traite->fresh()->statut, 'Un écart traité ne se rouvre pas.');
    }

    public function test_une_journee_sans_difference_ne_cree_aucun_ecart(): void
    {
        $p = $this->paiementEncaisse();
        $this->fournisseur->releve = [$this->ligne(RappelFournisseur::PAIEMENT, $p->reference_fournisseur, $p->cle_idempotence, '10.00')];

        $this->assertSame(0, app(RapprochementService::class)->rapprocher($this->lundi())['ecarts']);
    }

    public function test_la_commande_rapproche_la_veille_par_defaut_en_heure_de_kinshasa(): void
    {
        $this->paiementEncaisse();
        $this->travelTo(CarbonImmutable::parse('2026-12-08 02:17', 'Africa/Kinshasa'));

        $this->assertSame(0, Artisan::call('paiement:rapprocher'));

        $this->assertSame('2026-12-07', EcartRapprochement::sole()->jour->toDateString());
        $this->assertStringContainsString('07/12/2026', Artisan::output());
    }

    public function test_la_commande_accepte_un_jour_et_refuse_une_date_invalide(): void
    {
        $this->paiementEncaisse();

        $this->assertSame(0, Artisan::call('paiement:rapprocher', ['jour' => '2026-12-07']));
        $this->assertSame(1, EcartRapprochement::count());
        $this->assertNotSame(0, Artisan::call('paiement:rapprocher', ['jour' => '07/12/2026']));
        $this->assertNotSame(0, Artisan::call('paiement:rapprocher', ['jour' => '2026-02-30']));
    }

    public function test_la_commande_est_planifiee_chaque_nuit_heure_de_kinshasa(): void
    {
        $evenement = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains($e->command, 'paiement:rapprocher'));

        $this->assertNotNull($evenement);
        $this->assertSame('17 2 * * *', $evenement->expression);
        $this->assertSame('Africa/Kinshasa', $evenement->timezone);
    }

    public function test_un_ecart_se_traite_avec_un_commentaire_une_seule_fois(): void
    {
        $this->paiementEncaisse();
        app(RapprochementService::class)->rapprocher($this->lundi());
        $ecart = EcartRapprochement::sole();
        $agent = $this->personnelActive('caisse');
        $traiter = fn (string $commentaire) => $this->commePersonnel($agent)->post(route('personnel.caisse.rapprochement.traiter', $ecart->id), ['commentaire' => $commentaire]);

        $traiter('')->assertSessionHasErrors('commentaire');
        $this->assertSame('ouvert', $ecart->fresh()->statut);

        $traiter('Paiement confirmé par l\'opérateur, relevé du lendemain')->assertSessionHasNoErrors();
        $ecart->refresh();
        $this->assertSame(['traite', $agent->id], [$ecart->statut, $ecart->traite_par]);

        $traiter('Encore')->assertSessionHasErrors('commentaire');
        $this->assertSame('Paiement confirmé par l\'opérateur, relevé du lendemain', $ecart->fresh()->commentaire);

        $this->expectException(PaiementRefuse::class);
        app(RapprochementService::class)->traiter($ecart, $agent, 'Encore');
    }

    public function test_l_ecran_liste_les_ecarts_ouverts_pour_la_caisse_seulement(): void
    {
        $this->paiementEncaisse();
        app(RapprochementService::class)->rapprocher($this->lundi());

        $this->commePersonnel($this->personnelActive('caisse'))->get(route('personnel.caisse.rapprochement'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Personnel/Caisse/Rapprochement')->has('ouverts', 1)
                ->where('ouverts.0.nature', 'Chez nous, absent du relevé de l\'opérateur'));

        $this->commePersonnel($this->medecinActive()->user)->get(route('personnel.caisse.rapprochement'))->assertForbidden();
        $this->actingAs(User::factory()->create())->get(route('personnel.caisse.rapprochement'))->assertForbidden();
    }
}
