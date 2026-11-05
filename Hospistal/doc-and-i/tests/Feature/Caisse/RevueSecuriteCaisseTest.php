<?php

namespace Tests\Feature\Caisse;

use App\Enums\MotifRemboursement;
use App\Enums\StatutRemboursement;
use App\Enums\StatutRendezVous;
use App\Models\EcartRapprochement;
use App\Models\Facture;
use App\Models\Medecin;
use App\Models\OperationCaisse;
use App\Models\Paiement;
use App\Models\Remboursement;
use App\Models\Reversement;
use App\Models\TauxChange;
use App\Models\User;
use App\Services\Caisse\ReversementService;
use App\Services\Caisse\TauxDuJourService;
use App\Services\Paiement\FacturationService;
use App\Services\Paiement\JournalCaisse;
use App\Services\Paiement\PaiementRefuse;
use App\Services\Paiement\RemboursementService;
use App\Services\Paiement\TarificationService;
use App\Sms\EnvoyeurSms;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Support\EnvoyeurSmsFactice;
use Tests\Support\FournisseurFactice;
use Tests\Support\ParcoursCaisse;
use Tests\Support\ParcoursPaiement;
use Tests\Support\ParcoursRendezVous;
use Tests\Support\PersonnelDeTest;
use Tests\TestCase;

/**
 * Constats de la revue de sécurité du lot paiement, côté caisse : chacun
 * verrouillé par un test qui échouait avant correction.
 */
class RevueSecuriteCaisseTest extends TestCase
{
    use ParcoursCaisse, ParcoursPaiement, ParcoursRendezVous, PersonnelDeTest, RefreshDatabase;

    private User $agentA;

    private User $agentB;

    private EnvoyeurSmsFactice $sms;

    private FournisseurFactice $fournisseur;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesSeeder::class);
        $this->voyagerAuLundi();
        $this->agentA = $this->personnelActive('caisse');
        $this->agentB = $this->personnelActive('caisse');
        $this->sms = new EnvoyeurSmsFactice;
        $this->app->instance(EnvoyeurSms::class, $this->sms);
        $this->fournisseur = $this->fournisseurFactice();
    }

    private function service(): RemboursementService
    {
        return app(RemboursementService::class);
    }

    private function erreurTechnique(): void
    {
        $this->fournisseur->erreurRemboursement = 'Délai dépassé';
        $this->fournisseur->refusDefinitif = false;
    }

    private function refusExplicite(): void
    {
        $this->fournisseur->erreurRemboursement = 'Compte bénéficiaire inconnu';
        $this->fournisseur->refusDefinitif = true;
    }

    // --- 1. Erreur technique : le remboursement reste ouvert ----------------------------

    public function test_une_erreur_technique_laisse_le_remboursement_valide_et_le_montant_engage(): void
    {
        $this->erreurTechnique();
        $paiement = $this->paiementEncaisse();

        $r = $this->service()->executer($this->service()->demander($paiement, '10.00', MotifRemboursement::PaiementTardif));

        $this->assertSame(StatutRemboursement::Valide, $r->statut, 'La demande est peut-être partie : rien n\'est clos.');
        $this->assertSame([1, 'Délai dépassé', null], [$r->tentatives_envoi, $r->derniere_erreur, $r->motif_echec]);
        $this->assertSame('10.00', (string) $paiement->fresh()->montant_rembourse, 'Le montant reste engagé.');
    }

    public function test_l_argent_ne_peut_pas_sortir_deux_fois_apres_un_delai_reseau(): void
    {
        // Scénario de la revue : délai réseau, l'argent part quand même ;
        // avant correction, le montant redevenait remboursable et un second
        // remboursement partait sous une nouvelle clé.
        $this->erreurTechnique();
        $paiement = $this->paiementEncaisse();
        $geste = $this->service()->gesteCommercial($paiement, '10.00', $this->agentA, 'Coupure');
        $this->service()->executer($this->service()->valider($geste, $this->agentB));

        $this->expectException(PaiementRefuse::class);
        $this->service()->gesteCommercial($paiement, '10.00', $this->agentA, 'Coupure, encore');
    }

    public function test_un_rappel_reussi_apres_une_erreur_technique_produit_avoir_et_sortie_de_caisse(): void
    {
        $this->erreurTechnique();
        $r = $this->service()->executer($this->service()->demander($this->paiementEncaisse(), '10.00', MotifRemboursement::PaiementTardif));

        $this->service()->traiterRappel(...$this->rappelRemboursement($r));

        $this->assertSame(StatutRemboursement::Reussi, $r->fresh()->statut);
        $this->assertSame(1, Facture::where('type', 'avoir')->where('remboursement_id', $r->id)->count());
        $this->assertSame(1, OperationCaisse::where('remboursement_id', $r->id)->count());
    }

    public function test_la_tache_relance_avec_la_meme_cle_puis_s_arrete_et_le_signale(): void
    {
        config(['caisse.remboursements.tentatives_max' => 3, 'caisse.remboursements.intervalle_minutes' => 5]);
        $this->erreurTechnique();
        $r = $this->service()->demander($this->paiementEncaisse(), '10.00', MotifRemboursement::PaiementTardif);

        Artisan::call('paiement:executer-remboursements');
        Artisan::call('paiement:executer-remboursements');
        $this->assertCount(1, $this->fournisseur->remboursementsDemandes, 'Pas de relance avant l\'intervalle.');

        foreach ([1, 2, 3] as $i) {
            $this->travel(6)->minutes();
            Artisan::call('paiement:executer-remboursements');
        }

        $this->assertSame(array_fill(0, 3, $r->cle_idempotence), $this->fournisseur->remboursementsDemandes, 'Trois essais, toujours la même clé.');
        $r->refresh();
        $this->assertSame(StatutRemboursement::Valide, $r->statut);
        $this->assertNotNull($r->blocage);
        $this->assertSame('10.00', (string) $r->paiement->montant_rembourse);
    }

    public function test_un_refus_explicite_seul_met_en_echec(): void
    {
        $this->refusExplicite();
        $paiement = $this->paiementEncaisse();

        $r = $this->service()->executer($this->service()->demander($paiement, '10.00', MotifRemboursement::PaiementTardif));

        $this->assertSame(StatutRemboursement::Echoue, $r->statut);
        $this->assertSame('0.00', (string) $paiement->fresh()->montant_rembourse);
    }

    public function test_un_rappel_reussi_sur_un_remboursement_echoue_reengage_le_montant_et_emet_les_pieces(): void
    {
        $this->refusExplicite();
        $paiement = $this->paiementEncaisse();
        $r = $this->service()->executer($this->service()->demander($paiement, '10.00', MotifRemboursement::PaiementTardif));
        $this->assertSame(StatutRemboursement::Echoue, $r->statut);

        $this->service()->traiterRappel(...$this->rappelRemboursement($r));

        $this->assertSame(StatutRemboursement::Reussi, $r->fresh()->statut);
        $this->assertSame('10.00', (string) $paiement->fresh()->montant_rembourse, 'Montant ré-engagé.');
        $this->assertSame(1, Facture::where('type', 'avoir')->count());
        $this->assertSame('-10.00', (string) OperationCaisse::where('remboursement_id', $r->id)->sole()->montant);
        $this->assertCount(1, $this->sms->envoyes);
    }

    public function test_un_rappel_reussi_sur_un_echoue_non_reengageable_cree_un_ecart_a_traiter(): void
    {
        Log::spy();
        $this->refusExplicite();
        $paiement = $this->paiementEncaisse();
        $r = $this->service()->executer($this->service()->demander($paiement, '10.00', MotifRemboursement::PaiementTardif));
        // Entre-temps, le montant a été engagé par une autre demande.
        $this->service()->demander($paiement, '10.00', MotifRemboursement::AnnulationMedecin);
        [$rappel, $evt] = $this->rappelRemboursement($r);

        $this->service()->traiterRappel($rappel, $evt);

        $this->assertSame(StatutRemboursement::Echoue, $r->fresh()->statut);
        $ecart = EcartRapprochement::sole();
        $this->assertSame(['statut_different', $r->id, '10.00', 'ouvert'], [$ecart->nature, $ecart->remboursement_id, (string) $ecart->montant_releve, $ecart->statut]);
        $this->assertSame(0, Facture::where('type', 'avoir')->count());
        $this->assertSame([], $this->sms->envoyes);
        Log::shouldHaveReceived('warning')->withArgs(fn ($message, $contexte) => array_keys($contexte) === ['remboursement_id', 'evenement_id']);
    }

    public function test_l_ecran_ne_dit_pas_refuse_par_l_operateur_pour_une_erreur_technique(): void
    {
        $this->erreurTechnique();
        $geste = $this->service()->gesteCommercial($this->paiementEncaisse(), '3.00', $this->agentA, 'Retard');

        $this->commePersonnel($this->agentB)->post(route('personnel.caisse.remboursements.valider', $geste->id))
            ->assertSessionHas('succes', fn ($message) => ! str_contains($message, 'refusé') && str_contains($message, 'nouvel essai'));
    }

    public function test_la_caisse_voit_les_remboursements_bloques_ou_en_echec(): void
    {
        config(['caisse.remboursements.tentatives_max' => 1]);
        $this->erreurTechnique();
        $bloque = $this->service()->executer($this->service()->demander($this->paiementEncaisse(), '10.00', MotifRemboursement::PaiementTardif));
        $this->refusExplicite();
        $echoue = $this->service()->demander($this->paiementEncaisse(), '10.00', MotifRemboursement::AnnulationMedecin);

        $this->commePersonnel($this->agentA)->get(route('personnel.caisse.remboursements'))
            ->assertInertia(fn ($page) => $page->has('aSuivre', 1)->where('aSuivre.0.id', $bloque->id)->where('aSuivre.0.raison', $bloque->fresh()->blocage));

        // Le remboursement d'annulation (RG-05) attend encore la caisse : validé puis refusé par l'opérateur.
        $this->service()->executer($this->service()->valider($echoue, $this->agentA));
        $this->commePersonnel($this->agentA)->get(route('personnel.caisse.remboursements'))
            ->assertInertia(fn ($page) => $page->has('aSuivre', 2));
    }

    // --- 2. Reversements : dettes reportées, rendez-vous passés et non annulés ------------

    private function encaissementPour(Medecin $medecin, CarbonImmutable $debutRdv, string $prix = '20.00'): Paiement
    {
        $rdv = $this->rendezVousExistant($medecin, $this->patient(), $debutRdv, type: $this->typeConsultation($medecin, $prix));
        $paiement = $this->paiementExistant($rdv, montant: $prix);
        app(FacturationService::class)->emettreFacture($paiement);
        app(JournalCaisse::class)->encaissement($paiement);

        return $paiement;
    }

    private function preparer(Medecin $medecin, string $debut, string $fin): array
    {
        return app(ReversementService::class)->preparer($medecin, CarbonImmutable::parse($debut), CarbonImmutable::parse($fin), $this->agentA);
    }

    private function annulerEtRembourser(Paiement $paiement, ?string $montant = null, StatutRendezVous $statut = StatutRendezVous::AnnuleParMedecin): void
    {
        $paiement->rendezVous->forceFill(['statut' => $statut])->save();
        $this->remboursementReussi($paiement, $montant ?? (string) $paiement->montant);
    }

    public function test_un_remboursement_apres_reversement_est_impute_au_reversement_suivant(): void
    {
        config(['doc-and-i.paiement.commission_pourcent' => '10']);
        $medecin = $this->medecin();
        $paye = $this->encaissementPour($medecin, $this->lundi('09:00'));
        $this->travelTo(CarbonImmutable::parse('2026-12-08 08:00', 'Africa/Kinshasa'));
        [$premier] = $this->preparer($medecin, '2026-12-01', '2026-12-07');
        app(ReversementService::class)->payer($premier, $this->agentB, 'VIR-1');

        // Mardi : le rendez-vous est annulé, 100 % rendu. Mercredi : 30 USD encaissés.
        $this->annulerEtRembourser($paye);
        $this->travelTo(CarbonImmutable::parse('2026-12-09 08:00', 'Africa/Kinshasa'));
        $this->encaissementPour($medecin, CarbonImmutable::parse('2026-12-09 07:30', 'Africa/Kinshasa'), '30.00');
        $this->travelTo(CarbonImmutable::parse('2026-12-12 08:00', 'Africa/Kinshasa'));

        [$second] = $this->preparer($medecin, '2026-12-08', '2026-12-11');

        // 30 − 20 = 10 brut, 1 de commission, 9 net : les −20 sont imputés.
        $this->assertSame(['10.00', '10.00', '1.00', '9.00', '0.00'],
            [(string) $second->solde_periode, (string) $second->montant_brut, (string) $second->commission, (string) $second->montant_net, (string) $second->dette_reportee]);
    }

    public function test_un_solde_negatif_devient_une_dette_reportee_jamais_un_net_negatif(): void
    {
        config(['doc-and-i.paiement.commission_pourcent' => '10']);
        $medecin = $this->medecinActive();
        $paye = $this->encaissementPour($medecin, $this->lundi('09:00'));
        $this->travelTo(CarbonImmutable::parse('2026-12-08 08:00', 'Africa/Kinshasa'));
        app(ReversementService::class)->payer($this->preparer($medecin, '2026-12-01', '2026-12-07')[0], $this->agentB, 'VIR-1');
        $this->annulerEtRembourser($paye);
        $this->travelTo(CarbonImmutable::parse('2026-12-10 08:00', 'Africa/Kinshasa'));

        [$dette] = $this->preparer($medecin, '2026-12-08', '2026-12-09');

        $this->assertSame(['sans_versement', '-20.00', '0.00', '0.00', '20.00'],
            [$dette->statut, (string) $dette->solde_periode, (string) $dette->montant_brut, (string) $dette->montant_net, (string) $dette->dette_reportee]);
        try {
            app(ReversementService::class)->payer($dette, $this->agentB, 'VIR-0');
            $this->fail('Un reversement sans versement se paie.');
        } catch (PaiementRefuse) {
        }

        // La dette est reprise par le reversement suivant.
        $this->encaissementPour($medecin, CarbonImmutable::parse('2026-12-10 07:00', 'Africa/Kinshasa'), '30.00');
        $this->travelTo(CarbonImmutable::parse('2026-12-12 08:00', 'Africa/Kinshasa'));
        [$suivant] = $this->preparer($medecin, '2026-12-10', '2026-12-11');
        $this->assertSame(['30.00', '20.00', '10.00', '1.00', '9.00', '0.00'],
            [(string) $suivant->solde_periode, (string) $suivant->dette_reprise, (string) $suivant->montant_brut, (string) $suivant->commission, (string) $suivant->montant_net, (string) $suivant->dette_reportee]);

        $this->commePersonnel($medecin->user)->get(route('personnel.medecin.reversements'))
            ->assertInertia(fn ($page) => $page->where('reversements.1.detteReportee', '20,00 USD')->where('reversements.0.detteReprise', '20,00 USD'));
    }

    public function test_seuls_les_rendez_vous_passes_et_non_annules_comptent_et_aucun_n_est_oublie(): void
    {
        config(['doc-and-i.paiement.commission_pourcent' => '10']);
        $medecin = $this->medecin();
        $this->encaissementPour($medecin, $this->lundi('09:00'), '10.00');
        $futur = $this->encaissementPour($medecin, CarbonImmutable::parse('2026-12-20 09:00', 'Africa/Kinshasa'), '50.00');
        // Annulation tardive par le patient : 50 % rendus, mais la consultation n'a pas eu lieu.
        $annule = $this->encaissementPour($medecin, $this->lundi('10:00'), '40.00');
        $this->annulerEtRembourser($annule, '20.00', StatutRendezVous::AnnuleParPatient);
        $this->travelTo(CarbonImmutable::parse('2026-12-08 08:00', 'Africa/Kinshasa'));

        [$decembre] = $this->preparer($medecin, '2026-12-01', '2026-12-07');
        $this->assertSame('10.00', (string) $decembre->montant_brut, 'Ni le rendez-vous à venir, ni l\'annulé (ni son remboursement).');

        // Le rendez-vous du 20 passé, son encaissement du 7 est repris, une seule fois.
        $this->travelTo(CarbonImmutable::parse('2026-12-22 08:00', 'Africa/Kinshasa'));
        [$suivant] = $this->preparer($medecin, '2026-12-08', '2026-12-21');
        $this->assertSame('50.00', (string) $suivant->montant_brut);
        $this->assertSame(1, DB::table('reversement_operations')->where('operation_caisse_id', OperationCaisse::where('paiement_id', $futur->id)->value('id'))->count());
    }

    public function test_un_mouvement_n_entre_que_dans_un_reversement(): void
    {
        config(['doc-and-i.paiement.commission_pourcent' => '10']);
        $medecin = $this->medecin();
        $this->encaissementPour($medecin, $this->lundi('09:00'));
        $this->travelTo(CarbonImmutable::parse('2026-12-08 08:00', 'Africa/Kinshasa'));
        [$r] = $this->preparer($medecin, '2026-12-01', '2026-12-07');

        $this->expectException(QueryException::class);
        DB::table('reversement_operations')->insert([
            'id' => (string) Str::uuid(), 'reversement_id' => $r->id,
            'operation_caisse_id' => DB::table('reversement_operations')->value('operation_caisse_id'),
        ]);
    }

    // --- 3. Taux du jour : bornes, second agent, remplacement ------------------------------

    private function tauxHier(string $taux = '2850'): void
    {
        $t = new TauxChange;
        $t->forceFill(['jour' => '2026-12-06', 'cdf_par_usd' => $taux, 'saisi_par' => $this->agentA->id])->save();
    }

    public function test_un_taux_hors_bornes_est_refuse(): void
    {
        foreach (['0.0001', '499', '10001'] as $taux) {
            try {
                app(TauxDuJourService::class)->enregistrer($taux, $this->agentA);
                $this->fail("Taux accepté : {$taux}");
            } catch (PaiementRefuse) {
            }
        }
        $this->assertSame(0, TauxChange::count());
        $this->assertSame('500.0000', (string) app(TauxDuJourService::class)->enregistrer('500', $this->agentA)->cdf_par_usd);
    }

    public function test_un_ecart_de_plus_de_10_pour_cent_attend_un_second_agent(): void
    {
        $this->tauxHier('2850');

        $taux = app(TauxDuJourService::class)->enregistrer('3200', $this->agentA);

        $this->assertSame('a_confirmer', $taux->statut);
        $this->assertNull(app(TarificationService::class)->tauxDuJour(), 'Pas de taux actif tant qu\'il n\'est pas confirmé.');

        try {
            app(TauxDuJourService::class)->confirmer($taux, $this->agentA);
            $this->fail('Le même agent confirme son propre taux.');
        } catch (PaiementRefuse) {
        }

        $this->commePersonnel($this->agentB)->post(route('personnel.caisse.taux.confirmer', $taux->id))->assertSessionHasNoErrors();
        $actif = app(TarificationService::class)->tauxDuJour();
        $this->assertSame(['3200.0000', $this->agentB->id], [(string) $actif->cdf_par_usd, $actif->confirme_par]);
    }

    public function test_un_ecart_raisonnable_est_actif_tout_de_suite(): void
    {
        $this->tauxHier('2850');

        $this->assertSame('actif', app(TauxDuJourService::class)->enregistrer('3100', $this->agentA)->statut);
        $this->assertSame('3100.0000', (string) app(TarificationService::class)->tauxDuJour()->cdf_par_usd);
    }

    public function test_un_taux_errone_deja_utilise_est_remplace_sans_toucher_l_ancien(): void
    {
        $this->tauxHier('2850');
        $errone = app(TauxDuJourService::class)->enregistrer('2600', $this->agentA);
        $paiement = $this->paiementEncaisse(prix: '26000.00', devise: 'CDF');
        DB::table('paiements')->where('id', $paiement->id)->update(['taux_change_id' => $errone->id]);

        $nouveau = app(TauxDuJourService::class)->enregistrer('2860', $this->agentA);

        $this->assertNotSame($errone->id, $nouveau->id);
        $this->assertSame(['remplace', '2600.0000'], [$errone->fresh()->statut, (string) $errone->fresh()->cdf_par_usd]);
        $this->assertSame($nouveau->id, app(TarificationService::class)->tauxDuJour()->id);
        $this->assertSame($errone->id, $paiement->fresh()->taux_change_id);
    }

    public function test_la_base_refuse_deux_taux_actifs_le_meme_jour_ou_une_confirmation_par_le_saisisseur(): void
    {
        $taux = app(TauxDuJourService::class)->enregistrer('2850', $this->agentA);

        try {
            DB::transaction(fn () => DB::table('taux_change')->insert(['id' => (string) Str::uuid(), 'jour' => '2026-12-07', 'cdf_par_usd' => '2851', 'saisi_par' => $this->agentB->id]));
            $this->fail('Deux taux actifs le même jour.');
        } catch (QueryException) {
        }

        $this->expectException(QueryException::class);
        DB::table('taux_change')->where('id', $taux->id)->update(['confirme_par' => $this->agentA->id]);
    }

    // --- 4. Francs congolais : pas de centimes en Mobile Money ------------------------------

    public function test_les_remboursements_en_francs_sont_arrondis_au_franc_inferieur(): void
    {
        $paiement = $this->paiementEncaisse(prix: '28501.00', devise: 'CDF');

        $this->assertSame('14250.00', $this->service()->montantPourTaux($paiement, 50));
        $this->assertSame('1000.00', (string) $this->service()->gesteCommercial($paiement, '1000.75', $this->agentA, 'Retard')->montant);

        $this->expectException(PaiementRefuse::class);
        $this->service()->gesteCommercial($paiement, '0.50', $this->agentA, 'Retard');
    }

    // --- 5. Le bon prestataire --------------------------------------------------------------

    public function test_un_remboursement_d_un_autre_prestataire_n_est_pas_envoye_au_pilote_actif(): void
    {
        $r = $this->service()->demander($this->paiementEncaisse(), '10.00', MotifRemboursement::PaiementTardif);
        $this->fournisseur->nom = 'autre-agregateur';

        $r = $this->service()->executer($r);
        Artisan::call('paiement:executer-remboursements');

        $this->assertSame([], $this->fournisseur->remboursementsDemandes);
        $this->assertSame(StatutRemboursement::Valide, $r->statut);
        $this->assertStringContainsString('simulation', (string) $r->blocage);
        $this->commePersonnel($this->agentA)->get(route('personnel.caisse.remboursements'))
            ->assertInertia(fn ($page) => $page->where('aSuivre.0.id', $r->id));
    }

    public function test_un_rappel_pour_un_paiement_d_un_autre_prestataire_est_ignore(): void
    {
        $r = $this->service()->executer($this->service()->demander($this->paiementEncaisse(), '10.00', MotifRemboursement::PaiementTardif));
        $this->fournisseur->nom = 'autre-agregateur';
        [$rappel, $evt] = $this->rappelRemboursement($r);

        $this->service()->traiterRappel($rappel, $evt);

        $this->assertSame(StatutRemboursement::EnCours, $r->fresh()->statut);
        $this->assertStringStartsWith('prestataire différent', $evt->fresh()->resultat);
        $this->assertSame(0, Facture::where('type', 'avoir')->count());
    }

    // --- 6. SMS après validation : une panne ne casse rien -----------------------------------

    public function test_une_panne_de_sms_ne_fait_pas_echouer_le_rappel_et_n_ecrit_pas_le_numero(): void
    {
        Log::spy();
        $this->app->instance(EnvoyeurSms::class, new class implements EnvoyeurSms
        {
            public function envoyer(string $telephone, string $message): void
            {
                throw new RuntimeException('Passerelle SMS indisponible pour '.$telephone);
            }
        });
        $paiement = $this->paiementEncaisse();
        $r = $this->service()->executer($this->service()->demander($paiement, '10.00', MotifRemboursement::PaiementTardif));

        DB::transaction(fn () => $this->service()->traiterRappel(...$this->rappelRemboursement($r)));

        $this->assertSame(StatutRemboursement::Reussi, $r->fresh()->statut);
        $telephone = $paiement->patient->telephone;
        Log::shouldHaveReceived('warning')->withArgs(fn (...$arguments) => str_contains($arguments[0], 'SMS') && ! str_contains(json_encode($arguments), substr($telephone, 4)));
    }

    public function test_le_sms_n_utilise_que_des_espaces_ordinaires(): void
    {
        $paiement = $this->paiementEncaisse(prix: '28500.00', devise: 'CDF');
        $r = $this->service()->executer($this->service()->demander($paiement, '28500.00', MotifRemboursement::PaiementTardif));

        $this->service()->traiterRappel(...$this->rappelRemboursement($r));

        $this->assertSame('MEDIHOME : remboursement de 28 500 CDF envoyé sur votre compte M-Pesa.', $this->sms->envoyes[0]['message']);
    }
}
