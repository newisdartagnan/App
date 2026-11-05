<?php

namespace Tests\Feature\Paiement;

use App\Enums\MotifRemboursement;
use App\Enums\StatutPaiement;
use App\Enums\StatutRemboursement;
use App\Enums\StatutRendezVous;
use App\Models\EvenementPaiement;
use App\Models\Facture;
use App\Models\Medecin;
use App\Models\OperationCaisse;
use App\Models\Paiement;
use App\Models\Remboursement;
use App\Models\RendezVous;
use App\Models\User;
use App\Services\Paiement\Fournisseur\FournisseurSimulation;
use App\Services\Paiement\Fournisseur\RappelFournisseur;
use App\Services\Paiement\Fournisseur\SignatureRappel;
use App\Services\Paiement\RemboursementService;
use App\Services\RendezVous\CreneauxService;
use App\Services\RendezVous\ReservationService;
use App\Sms\EnvoyeurSms;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\EnvoyeurSmsFactice;
use Tests\Support\ParcoursPaiement;
use Tests\Support\ParcoursRendezVous;
use Tests\TestCase;

/** PAY-04, RG-01 à RG-03 : le rappel signé de l'opérateur, seul à confirmer un paiement. */
class RappelPaiementTest extends TestCase
{
    use ParcoursPaiement, ParcoursRendezVous, RefreshDatabase;

    private EnvoyeurSmsFactice $sms;

    private User $patient;

    private Medecin $medecin;

    private RendezVous $rdv;

    private Paiement $paiement;

    /** @var list<string> */
    private array $journal = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesSeeder::class);
        $this->voyagerAuLundi('07:00');
        $this->sms = new EnvoyeurSmsFactice;
        $this->app->instance(EnvoyeurSms::class, $this->sms);
        Event::listen(MessageLogged::class, fn (MessageLogged $m) => $this->journal[] = $m->message.' '.json_encode($m->context));

        $this->patient = $this->patient();
        $this->medecin = $this->medecin([[1, '09:00', '12:00']]);
        $this->medecin->user->forceFill(['nom' => 'MUKENDI'])->save();
        $type = $this->typeConsultation($this->medecin, '10.00');
        $this->rdv = $this->rendezVousExistant($this->medecin, $this->patient, $this->lundi('09:00'), 20, StatutRendezVous::EnAttentePaiement, type: $type);
        $this->rdv->demande->forceFill(['motif' => 'Douleurs abdominales'])->save();
        $this->paiement = $this->paiementExistant($this->rdv, StatutPaiement::EnAttente);
    }

    private function rappel(?Paiement $paiement = null, bool $reussi = true, ?string $montant = null, ?string $devise = null, ?string $evenement = null, string $type = RappelFournisseur::PAIEMENT): array
    {
        $paiement ??= $this->paiement;

        return $this->simulation()->rappelSigne($type, $paiement->cle_idempotence, (string) $paiement->reference_fournisseur, $reussi,
            $montant ?? (string) $paiement->montant, $devise ?? $paiement->devise, $reussi ? null : 'Solde insuffisant', $evenement);
    }

    public function test_un_rappel_reussi_encaisse_facture_journalise_et_confirme_le_rendez_vous(): void
    {
        $this->envoyerRappel($this->rappel())->assertOk()->assertJson(['statut' => 'recu']);

        $paiement = $this->paiement->fresh();
        $this->assertSame(StatutPaiement::Reussi, $paiement->statut);
        $this->assertNotNull($paiement->confirme_le);
        $this->assertSame(StatutRendezVous::Confirme, $this->rdv->fresh()->statut);

        $facture = Facture::sole();
        $this->assertSame('FA-2026-000001', $facture->numero);
        $this->assertSame($this->patient->id, $facture->patient_id);
        $this->assertSame('10.00', $facture->montant);
        $this->assertSame(1, OperationCaisse::where('type', 'encaissement')->where('paiement_id', $paiement->id)->count());

        $evenement = EvenementPaiement::sole();
        $this->assertSame($paiement->id, $evenement->paiement_id);
        $this->assertNotNull($evenement->traite_le);

        $this->assertCount(1, $this->sms->envoyes);
        $this->assertSame($this->patient->telephone, $this->sms->envoyes[0]['telephone']);
        $this->assertSame('MEDIHOME : paiement de 10,00 USD reçu (reçu FA-2026-000001). Votre rendez-vous du 07/12 à 09:00 est confirmé.', $this->sms->envoyes[0]['message']);
    }

    public function test_un_rappel_rejoue_repond_200_sans_aucun_effet(): void
    {
        $rappel = $this->rappel(evenement: 'evt-unique-1');

        $this->envoyerRappel($rappel)->assertOk();
        $this->envoyerRappel($rappel)->assertOk();
        // Même événement, signé de nouveau plus tard : toujours sans effet.
        $this->travel(1)->minutes();
        $this->envoyerRappel($this->rappel(evenement: 'evt-unique-1'))->assertOk();

        $this->assertSame(1, EvenementPaiement::count());
        $this->assertSame(1, Facture::count());
        $this->assertSame(1, OperationCaisse::count());
        $this->assertCount(1, $this->sms->envoyes);
    }

    public function test_un_second_rappel_reussi_pour_le_meme_paiement_ne_refacture_pas(): void
    {
        $this->envoyerRappel($this->rappel())->assertOk();
        $this->envoyerRappel($this->rappel())->assertOk();

        $this->assertSame(2, EvenementPaiement::count());
        $this->assertSame(1, EvenementPaiement::where('resultat', 'Paiement déjà encaissé : sans effet.')->count());
        $this->assertSame(1, Facture::count());
        $this->assertCount(1, $this->sms->envoyes);
    }

    /** @return array<string, array{0: callable(array): ?string}> */
    public static function signaturesInvalides(): array
    {
        return [
            'absente' => [fn (array $r) => ''],
            'fausse' => [fn (array $r) => 't='.time().',v1='.str_repeat('a', 64)],
            'périmée' => [fn (array $r) => (new SignatureRappel('secret-de-test-des-rappels-de-paiement-0123456789'))->signer($r['corps'], time() - 3600)],
            'autre secret' => [fn (array $r) => (new SignatureRappel(str_repeat('x', 40)))->signer($r['corps'])],
        ];
    }

    #[DataProvider('signaturesInvalides')]
    public function test_un_rappel_mal_signe_est_rejete_sans_rien_stocker(callable $entete): void
    {
        $rappel = $this->rappel();

        $this->envoyerRappel($rappel, $entete($rappel))->assertStatus(401);

        $this->assertSame(0, EvenementPaiement::count());
        $this->assertSame(StatutPaiement::EnAttente, $this->paiement->fresh()->statut);
        $this->assertSame(StatutRendezVous::EnAttentePaiement, $this->rdv->fresh()->statut);
        $this->assertSame(0, Facture::count());
        $journal = implode("\n", $this->journal);
        $this->assertStringContainsString('Rappel de paiement rejeté', $journal);
        $this->assertStringNotContainsString($this->paiement->cle_idempotence, $journal, 'La charge utile ne va pas au journal.');
    }

    public function test_un_corps_modifie_apres_signature_est_rejete(): void
    {
        $rappel = $this->rappel();
        $rappel['corps'] = str_replace('"10.00"', '"1.00"', $rappel['corps']);

        $this->envoyerRappel($rappel)->assertStatus(401);
        $this->assertSame(0, EvenementPaiement::count());
    }

    public function test_un_autre_nom_de_prestataire_repond_404(): void
    {
        $rappel = $this->rappel();

        $this->call('POST', '/paiement/rappel/autre-agregateur', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_MEDIHOME_SIGNATURE' => $rappel['entete'],
        ], $rappel['corps'])->assertNotFound();

        $this->assertSame(0, EvenementPaiement::count());
    }

    public function test_un_montant_ou_une_devise_differents_ne_confirment_rien(): void
    {
        $this->envoyerRappel($this->rappel(montant: '9.99'))->assertOk();
        $this->envoyerRappel($this->rappel(devise: 'CDF'))->assertOk();

        $this->assertSame(StatutPaiement::EnAttente, $this->paiement->fresh()->statut);
        $this->assertSame(StatutRendezVous::EnAttentePaiement, $this->rdv->fresh()->statut);
        $this->assertSame(0, Facture::count());
        $this->assertSame(2, EvenementPaiement::where('paiement_id', $this->paiement->id)->where('resultat', 'like', 'Montant ou devise différent%')->count());
        $this->assertCount(0, $this->sms->envoyes);
    }

    public function test_une_reference_inconnue_est_notee_sans_rien_confirmer(): void
    {
        $rappel = $this->simulation()->rappelSigne(RappelFournisseur::PAIEMENT, 'cle-inconnue-0000000000', 'SIM-X', true, '10.00', 'USD');

        $this->envoyerRappel($rappel)->assertOk();

        $evenement = EvenementPaiement::sole();
        $this->assertNull($evenement->paiement_id);
        $this->assertStringContainsString('Référence inconnue', $evenement->resultat);
        $this->assertSame(StatutPaiement::EnAttente, $this->paiement->fresh()->statut);
    }

    public function test_la_reference_d_un_autre_prestataire_ne_correspond_pas(): void
    {
        $this->paiement->forceFill(['fournisseur' => 'autre'])->save();

        $this->envoyerRappel($this->rappel())->assertOk();

        $this->assertStringContainsString('Référence inconnue', EvenementPaiement::sole()->resultat);
        $this->assertSame(StatutPaiement::EnAttente, $this->paiement->fresh()->statut);
    }

    public function test_un_echec_est_enregistre_et_le_patient_peut_reessayer(): void
    {
        $this->envoyerRappel($this->rappel(reussi: false))->assertOk();

        $paiement = $this->paiement->fresh();
        $this->assertSame(StatutPaiement::Echoue, $paiement->statut);
        $this->assertSame('Solde insuffisant', $paiement->motif_echec);
        $this->assertSame(StatutRendezVous::EnAttentePaiement, $this->rdv->fresh()->statut);
        $this->assertSame(0, Facture::count());

        $this->actingAs($this->patient)->get(route('paiement.suivi', $paiement))
            ->assertInertia(fn ($page) => $page->component('Paiement/Suivi')->where('peutReessayer', true)->where('paiement.statut', 'echoue'));
    }

    public function test_un_echec_apres_une_reussite_ne_defait_rien(): void
    {
        $this->envoyerRappel($this->rappel())->assertOk();
        $this->envoyerRappel($this->rappel(reussi: false))->assertOk();

        $this->assertSame(StatutPaiement::Reussi, $this->paiement->fresh()->statut);
        $this->assertSame(StatutRendezVous::Confirme, $this->rdv->fresh()->statut);
    }

    public function test_une_reussite_apres_un_echec_n_est_pas_encaissee_et_reste_a_rapprocher(): void
    {
        $this->envoyerRappel($this->rappel(reussi: false))->assertOk();
        $this->envoyerRappel($this->rappel())->assertOk();

        $this->assertSame(StatutPaiement::Echoue, $this->paiement->fresh()->statut, 'Transition interdite : échoué → réussi.');
        $this->assertSame(0, Facture::count());
        // Pas d'ordre entre les deux événements (même horodatage figé, identifiants UUID) : on cherche le second par son contenu.
        $this->assertStringContainsString('écart à rapprocher', EvenementPaiement::whereNotNull('resultat')->pluck('resultat')->implode(' | '));
    }

    public function test_rg03_paiement_tardif_creneau_encore_libre_le_rendez_vous_est_repris(): void
    {
        $this->travel(20)->minutes();
        $this->artisan('rdv:expirer-attentes');
        $this->assertSame(StatutRendezVous::Expire, $this->rdv->fresh()->statut);

        $this->envoyerRappel($this->rappel())->assertOk();

        $this->assertSame(StatutPaiement::Reussi, $this->paiement->fresh()->statut);
        $this->assertSame(StatutRendezVous::Confirme, $this->rdv->fresh()->statut);
        $this->assertSame('reservee', $this->rdv->demande->fresh()->statut);
        $this->assertSame(0, Remboursement::count());
        $this->assertStringContainsString('est confirmé', $this->sms->envoyes[0]['message']);
    }

    public function test_rg03_attente_echue_non_encore_expiree_est_reprise_aussi(): void
    {
        $this->travel(20)->minutes();

        $this->envoyerRappel($this->rappel())->assertOk();

        $this->assertSame(StatutRendezVous::Confirme, $this->rdv->fresh()->statut);
    }

    public function test_rg03_paiement_tardif_creneau_pris_remboursement_automatique(): void
    {
        $this->travel(20)->minutes();
        $this->artisan('rdv:expirer-attentes');
        $this->rendezVousExistant($this->medecin, User::factory()->create(), $this->lundi('09:00'));

        $this->envoyerRappel($this->rappel())->assertOk();

        $paiement = $this->paiement->fresh();
        $this->assertSame(StatutPaiement::Reussi, $paiement->statut, 'L\'argent est encaissé : il est facturé puis rendu.');
        $this->assertSame(StatutRendezVous::Expire, $this->rdv->fresh()->statut);
        $this->assertSame(1, Facture::count());
        $this->assertSame(1, OperationCaisse::count());

        $remboursement = Remboursement::sole();
        $this->assertSame(MotifRemboursement::PaiementTardif, $remboursement->motif);
        $this->assertSame(StatutRemboursement::Valide, $remboursement->statut);
        $this->assertTrue($remboursement->automatique);
        $this->assertSame('10.00', $remboursement->montant);
        $this->assertStringContainsString('vous serez remboursé', $this->sms->envoyes[0]['message']);
    }

    public function test_rg03_creneau_devenu_trop_proche_remboursement_automatique(): void
    {
        $this->travelTo($this->lundi('08:45'));

        $this->envoyerRappel($this->rappel())->assertOk();

        $this->assertSame(StatutRendezVous::Expire, $this->rdv->fresh()->statut);
        $this->assertSame(MotifRemboursement::PaiementTardif, Remboursement::sole()->motif);
    }

    public function test_rg03_demande_reservee_ailleurs_entre_temps_remboursement_automatique(): void
    {
        $this->travel(20)->minutes();
        $this->artisan('rdv:expirer-attentes');
        $this->rdv->demande->fresh()->forceFill(['statut' => 'reservee'])->save();

        $this->envoyerRappel($this->rappel())->assertOk();

        $this->assertSame(StatutRendezVous::Expire, $this->rdv->fresh()->statut);
        $this->assertSame(MotifRemboursement::PaiementTardif, Remboursement::sole()->motif);
    }

    public function test_un_rendez_vous_annule_par_le_patient_pendant_le_paiement_est_rembourse(): void
    {
        $this->actingAs($this->patient)->post(route('rdv.annuler', $this->rdv))->assertSessionHasNoErrors();

        $this->envoyerRappel($this->rappel())->assertOk();

        $this->assertSame(StatutRendezVous::AnnuleParPatient, $this->rdv->fresh()->statut);
        $this->assertSame(MotifRemboursement::PaiementTardif, Remboursement::sole()->motif);
        $this->assertSame('10.00', Remboursement::sole()->montant);
    }

    public function test_un_second_paiement_reussi_est_rembourse_comme_paiement_en_double(): void
    {
        $this->envoyerRappel($this->rappel())->assertOk();
        // Deuxième débit (par exemple lancé depuis l'application de l'opérateur).
        $second = $this->paiementExistant($this->rdv, StatutPaiement::Expire);

        $this->envoyerRappel($this->rappel($second))->assertOk();

        $this->assertSame(StatutPaiement::Reussi, $second->fresh()->statut);
        $this->assertSame(2, Facture::count(), 'Chaque encaissement a sa facture.');
        $this->assertSame(2, OperationCaisse::count(), 'Et sa ligne de journal.');
        $remboursement = Remboursement::sole();
        $this->assertSame($second->id, $remboursement->paiement_id);
        $this->assertSame(MotifRemboursement::PaiementEnDouble, $remboursement->motif);
        $this->assertSame(StatutRendezVous::Confirme, $this->rdv->fresh()->statut);
        $this->assertStringContainsString('déjà payé', $this->sms->envoyes[1]['message']);
    }

    public function test_un_debit_confirme_apres_l_expiration_de_la_tentative_reste_encaisse(): void
    {
        $this->travel(11)->minutes();
        $this->artisan('paiement:expirer-tentatives');
        $this->assertSame(StatutPaiement::Expire, $this->paiement->fresh()->statut);

        $this->envoyerRappel($this->rappel())->assertOk();

        $this->assertSame(StatutPaiement::Reussi, $this->paiement->fresh()->statut);
        $this->assertSame(StatutRendezVous::Confirme, $this->rdv->fresh()->statut);
    }

    public function test_un_plantage_n_enregistre_rien_et_l_operateur_renverra(): void
    {
        // Plantage après la facture et le journal, avant la fin de la transaction.
        $reservations = Mockery::mock(ReservationService::class.'[confirmerApresPaiement]', [app(CreneauxService::class)]);
        $reservations->shouldReceive('confirmerApresPaiement')->andThrow(new \RuntimeException('Base indisponible'));
        $this->app->instance(ReservationService::class, $reservations);
        $this->withoutExceptionHandling();

        try {
            $this->envoyerRappel($this->rappel());
            $this->fail('Le plantage doit remonter (réponse 500 à l\'opérateur).');
        } catch (\RuntimeException $e) {
            $this->assertSame('Base indisponible', $e->getMessage());
        }

        $this->assertSame(0, EvenementPaiement::count());
        $this->assertSame(0, Facture::count());
        $this->assertSame(0, OperationCaisse::count());
        $this->assertSame(StatutPaiement::EnAttente, $this->paiement->fresh()->statut);
        $this->assertCount(0, $this->sms->envoyes);
    }

    public function test_un_rappel_de_remboursement_est_aiguille_vers_le_service_des_remboursements(): void
    {
        $service = Mockery::mock(RemboursementService::class);
        $service->shouldReceive('traiterRappel')->once()
            ->withArgs(fn (RappelFournisseur $r, EvenementPaiement $e) => $r->type === 'remboursement' && $e->type === 'remboursement');
        $this->app->instance(RemboursementService::class, $service);

        $this->envoyerRappel($this->rappel(type: RappelFournisseur::REMBOURSEMENT))->assertOk();

        $this->assertSame('remboursement', EvenementPaiement::sole()->type);
        $this->assertSame(StatutPaiement::EnAttente, $this->paiement->fresh()->statut, 'Un rappel de remboursement ne touche pas au paiement.');
    }

    public function test_un_rappel_de_remboursement_signe_passe_par_la_meme_adresse_et_ne_rembourse_qu_une_fois(): void
    {
        // Intégration des deux lots : paiement confirmé par rappel, remboursement
        // exécuté par la caisse, puis rappel signé du remboursement.
        $this->envoyerRappel($this->rappel())->assertOk();
        $service = app(RemboursementService::class);
        $remboursement = $service->executer($service->demander($this->paiement->fresh(), '10.00', MotifRemboursement::PaiementTardif));
        $this->assertSame(StatutRemboursement::EnCours, $remboursement->statut);

        $rappel = $this->simulation()->rappelSigne(RappelFournisseur::REMBOURSEMENT, $remboursement->cle_idempotence,
            (string) $remboursement->reference_fournisseur, true, '10.00', 'USD', evenement: 'evt-remboursement-1');
        $this->envoyerRappel($rappel)->assertOk();
        $this->envoyerRappel($rappel)->assertOk();

        $this->assertSame(StatutRemboursement::Reussi, $remboursement->fresh()->statut);
        $this->assertSame(1, Facture::where('type', 'avoir')->count());
        $this->assertSame(1, OperationCaisse::where('type', 'remboursement')->count());
        $this->assertSame(1, EvenementPaiement::where('type', 'remboursement')->count());
        $this->assertSame(1, collect($this->sms->envoyes)->filter(fn ($sms) => str_contains($sms['message'], 'remboursement de 10,00 USD'))->count());
    }

    public function test_ni_numero_de_telephone_ni_donnee_de_sante_dans_les_journaux_et_les_sms(): void
    {
        $this->envoyerRappel($this->rappel())->assertOk();
        $this->envoyerRappel($this->rappel(montant: '1.00'))->assertOk();
        $this->envoyerRappel($this->rappel(), 'mauvaise signature')->assertStatus(401);

        $tout = implode("\n", $this->journal)."\n".implode("\n", array_column($this->sms->envoyes, 'message'));
        foreach ([$this->paiement->telephone_payeur, substr((string) $this->paiement->telephone_payeur, 4), 'Douleurs', 'abdominales', 'MUKENDI', $this->rdv->typeConsultation->nom, $this->medecin->specialite->nom] as $interdit) {
            $this->assertStringNotContainsString($interdit, $tout);
        }
    }

    public function test_la_route_du_rappel_n_exige_ni_session_ni_jeton_csrf_mais_une_limite_de_debit(): void
    {
        $route = app('router')->getRoutes()->getByName('paiement.rappel');

        $this->assertContains('throttle:paiement-rappel', $route->gatherMiddleware());
        $this->assertNotContains('auth', $route->gatherMiddleware());
        // Les tests ne vérifient pas le jeton CSRF : on contrôle l'exemption déclarée.
        $this->assertContains('paiement/rappel/*', app(ValidateCsrfToken::class)->getExcludedPaths());
        $this->envoyerRappel($this->rappel())->assertOk();
        $this->assertSame(FournisseurSimulation::NOM, $this->simulation()->nom());
    }
}
