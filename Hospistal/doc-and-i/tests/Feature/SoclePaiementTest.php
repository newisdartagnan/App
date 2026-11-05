<?php

namespace Tests\Feature;

use App\Enums\MotifRemboursement;
use App\Enums\StatutPaiement;
use App\Enums\StatutRemboursement;
use App\Models\Facture;
use App\Models\OperationCaisse;
use App\Models\Paiement;
use App\Models\Remboursement;
use App\Services\Paiement\FacturationService;
use App\Services\Paiement\Fournisseur\ErreurFournisseur;
use App\Services\Paiement\Fournisseur\FournisseurPaiement;
use App\Services\Paiement\Fournisseur\FournisseurSimulation;
use App\Services\Paiement\Fournisseur\RappelInvalide;
use App\Services\Paiement\Fournisseur\SignatureRappel;
use App\Services\Paiement\JournalCaisse;
use App\Services\Paiement\PaiementRefuse;
use App\Services\Paiement\RemboursementService;
use App\Services\Paiement\SimulationPaiement;
use App\Services\Paiement\TarificationService;
use App\Support\Montant;
use Database\Seeders\RolesSeeder;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use LogicException;
use RuntimeException;
use Tests\Support\ParcoursPaiement;
use Tests\Support\ParcoursRendezVous;
use Tests\Support\PersonnelDeTest;
use Tests\TestCase;

/** Socle du lot paiement : garanties portées par la base, le modèle et les contrats. */
class SoclePaiementTest extends TestCase
{
    use ParcoursPaiement;
    use ParcoursRendezVous;
    use PersonnelDeTest;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesSeeder::class);
        $this->voyagerAuLundi();
    }

    private function rdvPaye(string $prix = '10.00'): array
    {
        $medecin = $this->medecin();
        $type = $this->typeConsultation($medecin, $prix);
        $rdv = $this->rendezVousExistant($medecin, $this->patient(), $this->lundi('09:00'), type: $type);

        return [$rdv, $this->paiementExistant($rdv)];
    }

    // --- Montants ------------------------------------------------------------

    public function test_les_montants_se_calculent_en_centimes_entiers(): void
    {
        $this->assertSame(1000, Montant::enCentimes('10.00'));
        $this->assertSame(1050, Montant::enCentimes('10.5'));
        $this->assertSame(-250, Montant::enCentimes('-2.50'));
        $this->assertSame('0.07', Montant::depuisCentimes(7));
        $this->assertSame('-12.30', Montant::depuisCentimes(-1230));
        $this->assertSame("28\u{202F}500 CDF", Montant::afficher('28500.00', 'CDF'));
        $this->assertSame('10,00 USD', Montant::afficher('10.00', 'USD'));

        $this->expectException(\InvalidArgumentException::class);
        Montant::enCentimes('10,00');
    }

    // --- PAY-01 : tarifs et taux du jour -------------------------------------

    public function test_le_tarif_reprend_le_prix_fige_du_rendez_vous(): void
    {
        [$rdv] = $this->rdvPaye('12.50');
        $tarif = app(TarificationService::class)->pourRendezVous($rdv, 'USD');

        $this->assertSame(['12.50', 'USD', '12.50', 'USD'], [$tarif->montant, $tarif->devise, $tarif->montantReference, $tarif->deviseReference]);
        $this->assertNull($tarif->taux);
    }

    public function test_sans_prix_fixe_le_paiement_est_refuse(): void
    {
        $medecin = $this->medecin();
        $rdv = $this->rendezVousExistant($medecin, $this->patient(), $this->lundi('09:00'), type: $this->typeConsultation($medecin, null));

        $this->expectException(PaiementRefuse::class);
        app(TarificationService::class)->pourRendezVous($rdv, 'USD');
    }

    public function test_en_francs_congolais_le_taux_du_jour_est_applique_et_arrondi_au_franc_superieur(): void
    {
        [$rdv] = $this->rdvPaye('10.01');
        $taux = $this->tauxDuJour('2850.5000');

        $tarif = app(TarificationService::class)->pourRendezVous($rdv, 'CDF');

        // 10,01 × 2 850,5 = 28 533,505 → 28 534 CDF.
        $this->assertSame('28534.00', $tarif->montant);
        $this->assertSame('CDF', $tarif->devise);
        $this->assertTrue($taux->is($tarif->taux));
    }

    public function test_sans_taux_du_jour_le_paiement_en_francs_est_refuse(): void
    {
        [$rdv] = $this->rdvPaye();
        $this->tauxDuJour();
        $this->travelTo($this->lundi()->addDay());

        $this->expectException(PaiementRefuse::class);
        app(TarificationService::class)->pourRendezVous($rdv, 'CDF');
    }

    public function test_une_devise_inconnue_est_refusee(): void
    {
        [$rdv] = $this->rdvPaye();

        $this->expectException(PaiementRefuse::class);
        app(TarificationService::class)->pourRendezVous($rdv, 'EUR');
    }

    // --- Paiement : états et garanties de la base ----------------------------

    public function test_le_paiement_refuse_les_transitions_interdites(): void
    {
        [, $paiement] = $this->rdvPaye();

        $this->expectException(LogicException::class);
        $paiement->transitionVers(StatutPaiement::Echoue);
    }

    public function test_un_debit_confirme_apres_expiration_reste_encaissable(): void
    {
        $this->assertTrue(StatutPaiement::Expire->peutPasserA(StatutPaiement::Reussi));
        $this->assertTrue(StatutPaiement::Echoue->peutPasserA(StatutPaiement::Reussi), 'Rappel signé reçu après un échec de notre côté : l\'argent est parti.');
        $this->assertFalse(StatutPaiement::Echoue->peutPasserA(StatutPaiement::Expire));
        $this->assertSame([], StatutPaiement::Reussi->transitionsAutorisees());
    }

    public function test_la_base_refuse_deux_tentatives_en_cours_pour_un_meme_rendez_vous(): void
    {
        [$rdv] = $this->rdvPaye();
        $this->paiementExistant($rdv, StatutPaiement::EnAttente);

        $this->expectException(QueryException::class);
        $this->paiementExistant($rdv, StatutPaiement::Initie);
    }

    public function test_la_base_refuse_une_cle_d_idempotence_reutilisee(): void
    {
        [$rdv, $paiement] = $this->rdvPaye();
        $doublon = $this->paiementExistant($rdv, StatutPaiement::Echoue);

        $this->expectException(QueryException::class);
        DB::table('paiements')->where('id', $doublon->id)->update(['cle_idempotence' => $paiement->cle_idempotence]);
    }

    public function test_la_base_refuse_un_paiement_mobile_money_sans_numero(): void
    {
        [, $paiement] = $this->rdvPaye();

        $this->expectException(QueryException::class);
        DB::table('paiements')->where('id', $paiement->id)->update(['telephone_payeur' => null]);
    }

    public function test_le_numero_du_payeur_n_est_jamais_serialise(): void
    {
        [, $paiement] = $this->rdvPaye();

        $this->assertArrayNotHasKey('telephone_payeur', $paiement->toArray());
        $this->assertStringNotContainsString('+243810000001', $paiement->toJson());
    }

    public function test_aucun_attribut_du_paiement_n_est_remplissable_en_masse(): void
    {
        $this->expectException(MassAssignmentException::class);
        (new Paiement)->fill(['statut' => 'reussi']);
    }

    // --- PAY-07 : remboursements --------------------------------------------

    public function test_un_remboursement_d_annulation_attend_la_caisse_et_engage_le_montant(): void
    {
        [, $paiement] = $this->rdvPaye();
        $service = app(RemboursementService::class);

        $r = $service->demander($paiement, $service->montantPourTaux($paiement, 50), MotifRemboursement::AnnulationPatient);

        $this->assertSame(StatutRemboursement::AValider, $r->statut);
        $this->assertFalse($r->automatique);
        $this->assertSame('5.00', (string) $r->montant);
        $this->assertSame('5.00', (string) $paiement->fresh()->montant_rembourse);
    }

    public function test_un_remboursement_de_regle_nait_valide(): void
    {
        [, $paiement] = $this->rdvPaye();

        $r = app(RemboursementService::class)->demander($paiement, '10.00', MotifRemboursement::PaiementTardif);

        $this->assertSame(StatutRemboursement::Valide, $r->statut);
        $this->assertTrue($r->automatique);
        $this->assertNull($r->demande_par);
    }

    public function test_une_demande_rejouee_ne_rembourse_pas_deux_fois(): void
    {
        [, $paiement] = $this->rdvPaye();
        $service = app(RemboursementService::class);

        $premier = $service->demander($paiement, '10.00', MotifRemboursement::AnnulationMedecin);
        $second = $service->demander($paiement, '10.00', MotifRemboursement::AnnulationMedecin);

        $this->assertTrue($premier->is($second));
        $this->assertSame(1, Remboursement::count());
        $this->assertSame('10.00', (string) $paiement->fresh()->montant_rembourse);
    }

    public function test_le_cumul_des_remboursements_ne_depasse_pas_le_montant_paye(): void
    {
        [, $paiement] = $this->rdvPaye();
        $caisse = $this->personnelActive('caisse');
        $service = app(RemboursementService::class);
        $service->demander($paiement, '6.00', MotifRemboursement::GesteCommercial, $caisse);

        try {
            $service->demander($paiement, '4.01', MotifRemboursement::GesteCommercial, $caisse);
            $this->fail('Dépassement accepté.');
        } catch (PaiementRefuse) {
        }

        $service->demander($paiement, '4.00', MotifRemboursement::GesteCommercial, $caisse);
        $this->assertSame('10.00', (string) $paiement->fresh()->montant_rembourse);

        // La base aussi, quel que soit le code.
        $this->expectException(QueryException::class);
        DB::table('paiements')->where('id', $paiement->id)->update(['montant_rembourse' => '10.01']);
    }

    public function test_un_paiement_non_encaisse_ne_se_rembourse_pas(): void
    {
        [$rdv] = $this->rdvPaye();
        $echoue = $this->paiementExistant($rdv, StatutPaiement::Echoue);

        $this->expectException(PaiementRefuse::class);
        app(RemboursementService::class)->demander($echoue, '10.00', MotifRemboursement::AnnulationMedecin);
    }

    public function test_un_geste_commercial_exige_un_demandeur_identifie(): void
    {
        [, $paiement] = $this->rdvPaye();

        $this->expectException(PaiementRefuse::class);
        app(RemboursementService::class)->demander($paiement, '1.00', MotifRemboursement::GesteCommercial);
    }

    public function test_la_base_refuse_qu_un_agent_valide_sa_propre_demande(): void
    {
        [, $paiement] = $this->rdvPaye();
        $caisse = $this->personnelActive('caisse');
        $r = app(RemboursementService::class)->demander($paiement, '1.00', MotifRemboursement::GesteCommercial, $caisse);

        $this->expectException(QueryException::class);
        DB::table('remboursements')->where('id', $r->id)->update(['valide_par' => $caisse->id]);
    }

    public function test_un_remboursement_refuse_libere_son_montant(): void
    {
        [, $paiement] = $this->rdvPaye();
        $service = app(RemboursementService::class);
        $r = $service->demander($paiement, '10.00', MotifRemboursement::AnnulationPatient);

        DB::transaction(function () use ($r, $service) {
            $r = Remboursement::whereKey($r->id)->lockForUpdate()->first();
            $r->transitionVers(StatutRemboursement::Refuse);
            $r->save();
            $service->desengager($r);
        });

        $this->assertSame('0.00', (string) $paiement->fresh()->montant_rembourse);
        // Le motif est de nouveau libre.
        $this->assertNotNull($service->demander($paiement, '10.00', MotifRemboursement::AnnulationPatient));
    }

    public function test_le_remboursement_refuse_les_transitions_interdites(): void
    {
        [, $paiement] = $this->rdvPaye();
        $r = app(RemboursementService::class)->demander($paiement, '10.00', MotifRemboursement::AnnulationPatient);

        $this->expectException(LogicException::class);
        $r->transitionVers(StatutRemboursement::Reussi);
    }

    // --- PAY-05, PAY-08 : factures et avoirs ---------------------------------

    public function test_les_factures_sont_numerotees_sans_trou_et_emises_une_fois(): void
    {
        [, $p1] = $this->rdvPaye();
        [, $p2] = $this->rdvPaye();
        $facturation = app(FacturationService::class);

        $f1 = $facturation->emettreFacture($p1);
        $this->assertTrue($f1->is($facturation->emettreFacture($p1)), 'Idempotent.');
        $f2 = $facturation->emettreFacture($p2);

        $this->assertSame(['FA-2026-000001', 'FA-2026-000002'], [$f1->numero, $f2->numero]);
        $this->assertSame('10.00', (string) $f1->montant);
        $this->assertStringStartsWith('Téléconsultation du ', $f1->libelle);
    }

    public function test_une_transaction_annulee_rend_son_numero(): void
    {
        [, $p1] = $this->rdvPaye();
        $facturation = app(FacturationService::class);

        try {
            DB::transaction(function () use ($facturation, $p1) {
                $facturation->emettreFacture($p1);
                throw new RuntimeException('coupure');
            });
        } catch (RuntimeException) {
        }

        $this->assertSame('FA-2026-000001', $facturation->emettreFacture($p1)->numero);
    }

    public function test_une_facture_ne_se_modifie_ni_ne_se_supprime(): void
    {
        [, $paiement] = $this->rdvPaye();
        $facture = app(FacturationService::class)->emettreFacture($paiement);

        try {
            $facture->forceFill(['montant' => '1.00'])->save();
            $this->fail('Modification acceptée par le modèle.');
        } catch (LogicException) {
        }

        foreach (['UPDATE factures SET montant = 1', 'DELETE FROM factures'] as $sql) {
            try {
                DB::transaction(fn () => DB::statement($sql));
                $this->fail("La base a accepté : {$sql}");
            } catch (QueryException $e) {
                $this->assertStringContainsString('pièce comptable non modifiable', $e->getMessage());
            }
        }
    }

    public function test_un_avoir_annule_la_facture_d_un_remboursement_reussi(): void
    {
        [, $paiement] = $this->rdvPaye();
        $r = app(RemboursementService::class)->demander($paiement, '10.00', MotifRemboursement::PaiementTardif);
        $r->forceFill(['statut' => StatutRemboursement::Reussi, 'termine_le' => now()])->save();

        $avoir = app(FacturationService::class)->emettreAvoir($r);

        $this->assertSame('AV-2026-000001', $avoir->numero);
        $this->assertTrue($avoir->estAvoir());
        $this->assertSame($paiement->facture->id, $avoir->facture_origine_id);
        $this->assertTrue($avoir->is(app(FacturationService::class)->emettreAvoir($r)), 'Idempotent.');
        $this->assertSame(2, Facture::count());
    }

    // --- PAY-09 : journal de caisse -----------------------------------------

    public function test_le_journal_ecrit_chaque_mouvement_une_fois_et_ne_se_modifie_pas(): void
    {
        [$rdv, $paiement] = $this->rdvPaye();
        $journal = app(JournalCaisse::class);

        $ligne = $journal->encaissement($paiement);
        $journal->encaissement($paiement);

        $r = app(RemboursementService::class)->demander($paiement, '10.00', MotifRemboursement::PaiementTardif);
        $r->forceFill(['statut' => StatutRemboursement::Reussi, 'termine_le' => now()])->save();
        $sortie = $journal->sortie($r);
        $journal->sortie($r);

        $this->assertSame(2, OperationCaisse::count());
        $this->assertSame([$rdv->medecin_id, '10.00', '2026-12-07'], [$ligne->medecin_id, (string) $ligne->montant, $ligne->jour->toDateString()]);
        $this->assertSame('-10.00', (string) $sortie->montant);

        $this->expectException(QueryException::class);
        DB::statement('UPDATE operations_caisse SET montant = 1');
    }

    // --- PAY-04 : signature des rappels et pilote de simulation -------------

    public function test_la_signature_refuse_un_corps_modifie_une_signature_fausse_ou_perimee(): void
    {
        $signature = new SignatureRappel(str_repeat('s', 32), 300);
        $entete = $signature->signer('{"a":1}', 1_000_000);

        $signature->verifier('{"a":1}', $entete, 1_000_100);

        foreach ([
            ['{"a":2}', $entete, 1_000_100],
            ['{"a":1}', null, 1_000_100],
            ['{"a":1}', 't=1000000,v1='.str_repeat('0', 64), 1_000_100],
            ['{"a":1}', $entete, 1_000_301],
            ['{"a":1}', (new SignatureRappel(str_repeat('x', 32)))->signer('{"a":1}', 1_000_000), 1_000_100],
        ] as $i => [$corps, $e, $t]) {
            try {
                $signature->verifier($corps, $e, $t);
                $this->fail("Cas {$i} accepté.");
            } catch (RappelInvalide) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_un_secret_trop_court_est_refuse(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SignatureRappel('court');
    }

    public function test_la_simulation_lit_ses_propres_rappels_signes(): void
    {
        $simulation = $this->simulation();
        $rappel = $simulation->rappelSigne('paiement', 'cle-1', 'SIM-P-1', true, '10.00', 'USD', evenement: 'evt-1');

        $requete = Request::create('/paiement/rappel/simulation', 'POST', server: [
            'HTTP_'.strtoupper(str_replace('-', '_', SignatureRappel::ENTETE)) => $rappel['entete'],
        ], content: $rappel['corps']);
        $lu = $simulation->lireRappel($requete);

        $this->assertSame(['evt-1', 'paiement', 'cle-1', 'SIM-P-1', true, '10.00', 'USD'],
            [$lu->identifiantEvenement, $lu->type, $lu->referenceMarchand, $lu->referenceFournisseur, $lu->reussi, $lu->montant, $lu->devise]);
    }

    public function test_la_simulation_rend_la_meme_reference_a_une_reprise(): void
    {
        [, $paiement] = $this->rdvPaye();

        $this->assertSame(
            $this->simulation()->initier($paiement)->referenceFournisseur,
            $this->simulation()->initier($paiement)->referenceFournisseur,
        );
    }

    public function test_la_simulation_est_interdite_en_production(): void
    {
        $this->app->forgetInstance(FournisseurPaiement::class);
        $this->app->detectEnvironment(fn () => 'production');

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('interdit en production');
            app(FournisseurPaiement::class);
        } finally {
            $this->app->detectEnvironment(fn () => 'testing');
        }
    }

    public function test_la_simulation_est_interdite_sur_un_serveur_de_recette(): void
    {
        // Un serveur de recette ouvert à de vrais patients n'est pas en
        // « production » mais ne doit pas confirmer de paiements sans argent.
        $this->app->forgetInstance(FournisseurPaiement::class);
        $this->app->detectEnvironment(fn () => 'staging');

        try {
            $this->assertFalse(FournisseurSimulation::environnementAutorise());
            $this->assertFalse(SimulationPaiement::disponible());
            $this->expectException(RuntimeException::class);
            app(FournisseurPaiement::class);
        } finally {
            $this->app->detectEnvironment(fn () => 'testing');
        }
    }

    public function test_une_erreur_du_prestataire_est_technique_sauf_refus_explicite(): void
    {
        $this->assertFalse((new ErreurFournisseur('délai'))->refusDefinitif);
        $this->assertFalse(ErreurFournisseur::injoignable('coupure')->refusDefinitif);
        $this->assertTrue(ErreurFournisseur::refus('solde insuffisant')->refusDefinitif);
    }

    public function test_sans_pilote_configure_rien_ne_se_paie(): void
    {
        config(['doc-and-i.paiement.fournisseur' => null]);
        $this->app->forgetInstance(FournisseurPaiement::class);

        $this->expectException(\InvalidArgumentException::class);
        app(FournisseurPaiement::class);
    }

    public function test_le_pilote_de_test_est_la_simulation(): void
    {
        $this->assertInstanceOf(FournisseurSimulation::class, app(FournisseurPaiement::class));
    }
}
