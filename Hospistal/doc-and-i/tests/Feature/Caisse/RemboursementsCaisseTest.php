<?php

namespace Tests\Feature\Caisse;

use App\Enums\MotifRemboursement;
use App\Enums\MoyenPaiement;
use App\Enums\StatutRemboursement;
use App\Models\Facture;
use App\Models\OperationCaisse;
use App\Models\Remboursement;
use App\Models\User;
use App\Services\Paiement\Fournisseur\RappelFournisseur;
use App\Services\Paiement\PaiementRefuse;
use App\Services\Paiement\RemboursementService;
use App\Sms\EnvoyeurSms;
use Database\Seeders\RolesSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use LogicException;
use RuntimeException;
use Tests\Support\EnvoyeurSmsFactice;
use Tests\Support\ParcoursCaisse;
use Tests\Support\ParcoursPaiement;
use Tests\Support\ParcoursRendezVous;
use Tests\Support\PersonnelDeTest;
use Tests\TestCase;

/** PAY-07, côté caisse : validation à quatre yeux, refus, gestes commerciaux, exécution, rappel. */
class RemboursementsCaisseTest extends TestCase
{
    use ParcoursCaisse, ParcoursPaiement, ParcoursRendezVous, PersonnelDeTest, RefreshDatabase;

    private User $agentA;

    private User $agentB;

    private EnvoyeurSmsFactice $sms;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesSeeder::class);
        $this->voyagerAuLundi();
        $this->agentA = $this->personnelActive('caisse');
        $this->agentB = $this->personnelActive('caisse');
        $this->sms = new EnvoyeurSmsFactice;
        $this->app->instance(EnvoyeurSms::class, $this->sms);
    }

    private function service(): RemboursementService
    {
        return app(RemboursementService::class);
    }

    private function gesteDeA(string $montant = '3.00'): Remboursement
    {
        return $this->service()->gesteCommercial($this->paiementEncaisse(), $montant, $this->agentA, 'Attente de 40 minutes');
    }

    private function valider(User $agent, Remboursement $r): TestResponse
    {
        return $this->commePersonnel($agent)->post(route('personnel.caisse.remboursements.valider', $r->id));
    }

    // --- Validation à quatre yeux --------------------------------------------------

    public function test_un_autre_agent_valide_et_le_remboursement_part_chez_l_operateur(): void
    {
        $r = $this->gesteDeA();

        $this->valider($this->agentB, $r)->assertSessionHasNoErrors()->assertSessionHas('succes');

        $r->refresh();
        $this->assertSame(StatutRemboursement::EnCours, $r->statut);
        $this->assertSame($this->agentB->id, $r->valide_par);
        $this->assertNotNull($r->valide_le);
        $this->assertStringStartsWith('SIM-R-', $r->reference_fournisseur);
    }

    public function test_le_demandeur_ne_valide_jamais_sa_propre_demande(): void
    {
        $r = $this->gesteDeA();

        $this->valider($this->agentA, $r)->assertSessionHasErrors('remboursement');
        $this->assertSame(StatutRemboursement::AValider, $r->fresh()->statut);

        $this->expectException(PaiementRefuse::class);
        $this->service()->valider($r, $this->agentA);
    }

    public function test_l_ecran_ne_propose_pas_au_demandeur_de_valider(): void
    {
        $r = $this->gesteDeA();

        $this->commePersonnel($this->agentA)->get(route('personnel.caisse.remboursements'))
            ->assertInertia(fn ($page) => $page->component('Personnel/Caisse/Remboursements')
                ->where('aValider.0.id', $r->id)->where('aValider.0.peutValider', false)
                ->where('aValider.0.justification', 'Attente de 40 minutes'));
        $this->commePersonnel($this->agentB)->get(route('personnel.caisse.remboursements'))
            ->assertInertia(fn ($page) => $page->where('aValider.0.peutValider', true));
    }

    public function test_une_demande_du_systeme_est_validee_par_n_importe_quel_agent(): void
    {
        $r = $this->service()->demander($this->paiementEncaisse(), '10.00', MotifRemboursement::AnnulationMedecin);
        $this->assertNull($r->demande_par);

        $this->valider($this->agentA, $r)->assertSessionHasNoErrors();
        $this->assertSame($this->agentA->id, $r->fresh()->valide_par);
    }

    public function test_une_double_validation_est_refusee_proprement(): void
    {
        $r = $this->gesteDeA();

        $this->valider($this->agentB, $r)->assertSessionHasNoErrors();
        $this->valider($this->personnelActive('caisse'), $r)->assertSessionHasErrors('remboursement');

        $this->assertSame($this->agentB->id, $r->fresh()->valide_par);
        $this->assertSame(1, Remboursement::count());
    }

    // --- Refus ------------------------------------------------------------------------

    public function test_un_refus_motive_rend_le_montant_remboursable(): void
    {
        $r = $this->gesteDeA('4.00');

        $this->commePersonnel($this->agentB)->post(route('personnel.caisse.remboursements.refuser', $r->id), ['motif' => 'Geste non justifié'])
            ->assertSessionHasNoErrors();

        $r->refresh();
        $this->assertSame(StatutRemboursement::Refuse, $r->statut);
        $this->assertSame('Geste non justifié', $r->motif_refus);
        $this->assertSame('0.00', (string) $r->paiement->fresh()->montant_rembourse);
    }

    public function test_un_refus_sans_motif_est_refuse(): void
    {
        $r = $this->gesteDeA();

        $this->commePersonnel($this->agentB)->post(route('personnel.caisse.remboursements.refuser', $r->id), ['motif' => ' '])
            ->assertSessionHasErrors('motif');
        $this->assertSame(StatutRemboursement::AValider, $r->fresh()->statut);
    }

    public function test_un_remboursement_deja_valide_ne_peut_plus_etre_refuse(): void
    {
        $r = $this->gesteDeA();
        $this->service()->valider($r, $this->agentB);

        $this->commePersonnel($this->agentB)->post(route('personnel.caisse.remboursements.refuser', $r->id), ['motif' => 'Trop tard'])
            ->assertSessionHasErrors('motif');
        $this->assertSame(StatutRemboursement::Valide, $r->fresh()->statut);
    }

    // --- Geste commercial -----------------------------------------------------------------

    public function test_un_geste_commercial_se_demande_depuis_le_numero_de_facture(): void
    {
        $paiement = $this->paiementEncaisse();
        $numero = $paiement->facture->numero;

        $this->commePersonnel($this->agentA)->get(route('personnel.caisse.remboursements', ['numero' => strtolower($numero)]))
            ->assertInertia(fn ($page) => $page->where('paiementTrouve.facture', $numero)->where('paiementTrouve.resteRemboursable', '10,00 USD'));

        $this->commePersonnel($this->agentA)->post(route('personnel.caisse.remboursements.geste'), [
            'numero' => $numero, 'montant' => '2,50', 'justification' => 'Coupure pendant la consultation',
        ])->assertRedirect(route('personnel.caisse.remboursements'));

        $r = Remboursement::sole();
        $this->assertSame([MotifRemboursement::GesteCommercial, StatutRemboursement::AValider, '2.50', $this->agentA->id, 'Coupure pendant la consultation'],
            [$r->motif, $r->statut, (string) $r->montant, $r->demande_par, $r->justification]);
    }

    public function test_un_geste_commercial_exige_un_motif_et_reste_dans_la_limite_du_paye(): void
    {
        $paiement = $this->paiementEncaisse();
        $numero = $paiement->facture->numero;
        $geste = fn (array $champs) => $this->commePersonnel($this->agentA)->post(route('personnel.caisse.remboursements.geste'), $champs + ['numero' => $numero]);

        $geste(['montant' => '2.00', 'justification' => ''])->assertSessionHasErrors('justification');
        $geste(['montant' => '10.01', 'justification' => 'Erreur'])->assertSessionHasErrors('montant');
        $geste(['montant' => 'deux', 'justification' => 'Erreur'])->assertSessionHasErrors('montant');
        $geste(['numero' => 'FA-2026-999999', 'montant' => '1.00', 'justification' => 'Erreur'])->assertSessionHasErrors('numero');
        $this->assertSame(0, Remboursement::count());

        $this->expectException(PaiementRefuse::class);
        $this->service()->gesteCommercial($paiement, '1.00', $this->agentA, '   ');
    }

    // --- Exécution chez le prestataire ----------------------------------------------------

    public function test_un_refus_explicite_du_prestataire_met_le_remboursement_en_echec_et_rend_le_montant(): void
    {
        $factice = $this->fournisseurFactice();
        $factice->erreurRemboursement = 'Opérateur indisponible';
        $factice->refusDefinitif = true;
        $r = $this->service()->demander($this->paiementEncaisse(), '10.00', MotifRemboursement::PaiementTardif);

        $r = $this->service()->executer($r);

        $this->assertSame(StatutRemboursement::Echoue, $r->statut);
        $this->assertSame('Opérateur indisponible', $r->motif_echec);
        $this->assertSame('0.00', (string) $r->paiement->fresh()->montant_rembourse);
    }

    public function test_executer_un_remboursement_non_valide_ne_fait_rien(): void
    {
        $factice = $this->fournisseurFactice();
        $r = $this->gesteDeA();

        $this->assertSame(StatutRemboursement::AValider, $this->service()->executer($r)->statut);
        $this->assertSame([], $factice->remboursementsDemandes, 'Rien n\'est envoyé sans validation.');
    }

    public function test_la_tache_planifiee_envoie_les_remboursements_valides_une_seule_fois(): void
    {
        $factice = $this->fournisseurFactice();
        $auto = $this->service()->demander($this->paiementEncaisse(), '10.00', MotifRemboursement::PaiementTardif);
        $enAttente = $this->gesteDeA();

        $this->assertSame(0, Artisan::call('paiement:executer-remboursements'));
        Artisan::call('paiement:executer-remboursements');

        $this->assertSame(StatutRemboursement::EnCours, $auto->fresh()->statut);
        $this->assertSame(StatutRemboursement::AValider, $enAttente->fresh()->statut);
        $this->assertSame([$auto->cle_idempotence], $factice->remboursementsDemandes);
    }

    public function test_la_tache_est_planifiee_chaque_minute(): void
    {
        $evenements = collect(app(Schedule::class)->events())
            ->filter(fn ($e) => str_contains($e->command, 'paiement:executer-remboursements'));

        $this->assertCount(1, $evenements);
        $this->assertSame('* * * * *', $evenements->first()->expression);
    }

    // --- Rappel du prestataire -----------------------------------------------------------------

    public function test_un_rappel_reussi_emet_l_avoir_sort_de_caisse_et_previent_le_patient(): void
    {
        $paiement = $this->paiementEncaisse(prix: '5.00');
        $r = $this->service()->executer($this->service()->demander($paiement, '5.00', MotifRemboursement::PaiementTardif));
        [$rappel, $evt] = $this->rappelRemboursement($r);

        $this->service()->traiterRappel($rappel, $evt);

        $r->refresh();
        $this->assertSame(StatutRemboursement::Reussi, $r->statut);
        $this->assertNotNull($r->termine_le);
        $avoir = Facture::where('type', 'avoir')->sole();
        $this->assertSame([$r->id, '5.00'], [$avoir->remboursement_id, (string) $avoir->montant]);
        $this->assertSame('-5.00', (string) OperationCaisse::where('type', 'remboursement')->sole()->montant);
        $this->assertSame([['telephone' => $paiement->patient->telephone, 'message' => 'MEDIHOME : remboursement de 5,00 USD envoyé sur votre compte M-Pesa.']], $this->sms->envoyes);
        $this->assertSame([$r->id, 'réussi'], [$evt->fresh()->remboursement_id, $evt->fresh()->resultat]);
        $this->assertNotNull($evt->fresh()->traite_le);
    }

    public function test_un_remboursement_sur_carte_le_dit_au_patient(): void
    {
        $paiement = $this->paiementEncaisse(moyen: MoyenPaiement::Carte);
        $r = $this->service()->executer($this->service()->demander($paiement, '10.00', MotifRemboursement::PaiementTardif));

        $this->service()->traiterRappel(...$this->rappelRemboursement($r));

        $this->assertSame('MEDIHOME : remboursement de 10,00 USD envoyé sur votre carte.', $this->sms->envoyes[0]['message']);
    }

    public function test_le_sms_ne_part_qu_apres_la_validation_de_la_transaction(): void
    {
        $r = $this->service()->executer($this->service()->demander($this->paiementEncaisse(), '10.00', MotifRemboursement::PaiementTardif));
        [$rappel, $evt] = $this->rappelRemboursement($r);

        try {
            DB::transaction(function () use ($rappel, $evt) {
                $this->service()->traiterRappel($rappel, $evt);
                throw new RuntimeException('coupure avant la validation');
            });
        } catch (RuntimeException) {
        }

        $this->assertSame([], $this->sms->envoyes, 'Rien n\'est annoncé au patient si l\'écriture est annulée.');
        $this->assertSame(StatutRemboursement::EnCours, $r->fresh()->statut);
    }

    public function test_un_rappel_rejoue_ne_rembourse_ni_ne_previent_deux_fois(): void
    {
        $r = $this->service()->executer($this->service()->demander($this->paiementEncaisse(), '10.00', MotifRemboursement::PaiementTardif));

        $this->service()->traiterRappel(...$this->rappelRemboursement($r));
        [$rappel, $evt] = $this->rappelRemboursement($r);
        $this->service()->traiterRappel($rappel, $evt);

        $this->assertSame(1, Facture::where('type', 'avoir')->count());
        $this->assertSame(1, OperationCaisse::where('type', 'remboursement')->count());
        $this->assertCount(1, $this->sms->envoyes);
        $this->assertStringStartsWith('déjà traité', $evt->fresh()->resultat);
    }

    public function test_un_rappel_au_montant_ou_a_la_devise_differents_est_ignore(): void
    {
        Log::spy();
        $r = $this->service()->executer($this->service()->demander($this->paiementEncaisse(), '10.00', MotifRemboursement::PaiementTardif));

        foreach ([['9.99', 'USD'], ['10.00', 'CDF']] as [$montant, $devise]) {
            [$rappel, $evt] = $this->rappelRemboursement($r, montant: $montant, devise: $devise);
            $this->service()->traiterRappel($rappel, $evt);
            $this->assertSame('montant ou devise différent : ignoré', $evt->fresh()->resultat);
        }

        $this->assertSame(StatutRemboursement::EnCours, $r->fresh()->statut);
        $this->assertSame(0, Facture::where('type', 'avoir')->count());
        $this->assertSame([], $this->sms->envoyes);
        Log::shouldHaveReceived('warning')->withArgs(fn ($message, $contexte) => array_keys($contexte) === ['remboursement_id', 'evenement_id']);
    }

    public function test_un_rappel_d_echec_rend_le_montant_remboursable(): void
    {
        $paiement = $this->paiementEncaisse();
        $r = $this->service()->executer($this->service()->demander($paiement, '10.00', MotifRemboursement::PaiementTardif));

        $this->service()->traiterRappel(...$this->rappelRemboursement($r, reussi: false));

        $this->assertSame(StatutRemboursement::Echoue, $r->fresh()->statut);
        $this->assertSame('Compte du bénéficiaire fermé', $r->fresh()->motif_echec);
        $this->assertSame('0.00', (string) $paiement->fresh()->montant_rembourse);
        $this->assertSame([], $this->sms->envoyes);
    }

    public function test_un_rappel_arrive_avant_la_fin_de_l_envoi_est_traite(): void
    {
        // Prestataire très rapide : le rappel arrive alors que le remboursement est encore « validé ».
        $r = $this->service()->demander($this->paiementEncaisse(), '10.00', MotifRemboursement::PaiementTardif);

        $this->service()->traiterRappel(...$this->rappelRemboursement($r));

        $this->assertSame(StatutRemboursement::Reussi, $r->fresh()->statut);
        $this->assertSame(StatutRemboursement::Reussi, $this->service()->executer($r)->statut, 'L\'envoi qui suit ne change plus rien.');
    }

    public function test_un_rappel_pour_un_remboursement_inconnu_est_seulement_note(): void
    {
        $r = $this->service()->demander($this->paiementEncaisse(), '10.00', MotifRemboursement::PaiementTardif);
        [$rappel, $evt] = $this->rappelRemboursement($r);
        $inconnu = new RappelFournisseur($rappel->identifiantEvenement, 'remboursement', 'cle-inconnue', 'X', true, '10.00', 'USD', null, []);

        $this->service()->traiterRappel($inconnu, $evt);

        $this->assertSame('remboursement inconnu', $evt->fresh()->resultat);
        $this->assertSame(StatutRemboursement::Valide, $r->fresh()->statut);
    }

    public function test_un_remboursement_termine_ne_revient_pas_en_arriere(): void
    {
        $r = $this->remboursementReussi($this->paiementEncaisse(), '10.00');

        $this->expectException(LogicException::class);
        $r->transitionVers(StatutRemboursement::Echoue);
    }

    public function test_ni_le_journal_ni_les_sms_ne_contiennent_le_numero_du_payeur(): void
    {
        Log::spy();
        $paiement = $this->paiementEncaisse();
        $r = $this->service()->executer($this->service()->demander($paiement, '10.00', MotifRemboursement::PaiementTardif));
        [$rappel, $evt] = $this->rappelRemboursement($r, montant: '1.00');
        $this->service()->traiterRappel($rappel, $evt);
        $this->service()->traiterRappel(...$this->rappelRemboursement($r));

        // Le numéro débité par Mobile Money n'est écrit nulle part ; le SMS va
        // au téléphone du compte et ne dit ni consultation, ni médecin, ni motif.
        Log::shouldHaveReceived('warning')->withArgs(function (...$arguments) use ($paiement) {
            $texte = json_encode($arguments);

            return ! str_contains($texte, '+243') && ! str_contains($texte, (string) $paiement->telephone_payeur);
        });
        $message = $this->sms->envoyes[0]['message'];
        $this->assertStringNotContainsString('243', $message);
        foreach (['consultation', 'Dr', 'Toux', 'médecin'] as $mot) {
            $this->assertStringNotContainsStringIgnoringCase($mot, $message);
        }
    }

    // --- Accès ---------------------------------------------------------------------------------

    public function test_ni_un_patient_ni_un_medecin_ne_valident_un_remboursement(): void
    {
        $r = $this->service()->demander($this->paiementEncaisse(), '10.00', MotifRemboursement::AnnulationMedecin);

        $this->actingAs($r->paiement->patient)->post(route('personnel.caisse.remboursements.valider', $r->id))->assertForbidden();
        $this->commePersonnel($this->medecinActive()->user)->post(route('personnel.caisse.remboursements.valider', $r->id))->assertForbidden();
        $this->post(route('personnel.deconnexion'));
        $this->post(route('personnel.caisse.remboursements.valider', $r->id))->assertRedirect(route('personnel.connexion'));

        $this->assertSame(StatutRemboursement::AValider, $r->fresh()->statut);
    }
}
