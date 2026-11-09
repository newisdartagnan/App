<?php

namespace Tests\Feature\Laboratoire;

use App\Enums\MotifRemboursement;
use App\Enums\MoyenPaiement;
use App\Enums\StatutBonExamen;
use App\Enums\StatutPaiement;
use App\Models\Facture;
use App\Models\OperationCaisse;
use App\Models\Paiement;
use App\Models\Remboursement;
use App\Services\Laboratoire\BonsPatient;
use App\Services\Laboratoire\LaboratoireRefuse;
use App\Services\Paiement\Fournisseur\RappelFournisseur;
use App\Services\Paiement\PaiementRefuse;
use App\Services\Paiement\PaiementService;
use App\Sms\EnvoyeurSms;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\Support\EnvoyeurSmsFactice;
use Tests\Support\ParcoursConsultation;
use Tests\Support\ParcoursLaboratoire;
use Tests\Support\ParcoursPaiement;
use Tests\Support\ParcoursRendezVous;
use Tests\Support\PersonnelDeTest;
use Tests\TestCase;

/** RG-01, PRE-05 : le patient choisit son laboratoire, paie le bon, obtient son code de retrait ; « Mes examens ». */
class PaiementBonExamenTest extends TestCase
{
    use ParcoursConsultation, ParcoursLaboratoire, ParcoursPaiement, ParcoursRendezVous, PersonnelDeTest, RefreshDatabase;

    private EnvoyeurSmsFactice $sms;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesSeeder::class);
        $this->preparerConsultation('09:05');
        $this->sms = new EnvoyeurSmsFactice;
        $this->app->instance(EnvoyeurSms::class, $this->sms);
    }

    public function test_le_patient_choisit_le_laboratoire_avant_de_payer_puis_paie_par_le_meme_circuit(): void
    {
        $labo = $this->laboratoire('Eyano Centre Biologique (test)');
        $bon = $this->bonPrescrit([$this->examen()]);
        $this->sms->envoyes = [];

        // Sans laboratoire, pas de paiement.
        $this->commePatientRdv()->post(route('examens.payer', $bon->id), ['moyen' => 'mpesa', 'telephone' => '0810000001', 'cle' => (string) Str::uuid()])
            ->assertSessionHasErrors(['paiement' => 'Choisissez d\'abord le laboratoire où faire vos examens.']);

        $this->commePatientRdv()->post(route('examens.laboratoire', $bon->id), ['laboratoire_id' => $labo->id])->assertSessionHasNoErrors();
        $this->assertSame($labo->id, $bon->fresh()->laboratoire_id);

        $cle = (string) Str::uuid();
        $reponse = $this->commePatientRdv()->post(route('examens.payer', $bon->id), ['moyen' => 'mpesa', 'telephone' => '0810000001', 'cle' => $cle]);
        $paiement = Paiement::sole();
        $reponse->assertRedirect(route('paiement.suivi', $paiement));
        $this->assertSame(['examen', $bon->id, null, '5.00', 'USD', StatutPaiement::EnAttente], [$paiement->objet, $paiement->bon_examen_id, $paiement->rendez_vous_id, (string) $paiement->montant, $paiement->devise, $paiement->statut]);
        $this->assertSame(StatutBonExamen::Prescrit, $bon->fresh()->statut, 'Rien sur la parole du navigateur.');

        // Double envoi : même tentative.
        $this->commePatientRdv()->post(route('examens.payer', $bon->id), ['moyen' => 'mpesa', 'telephone' => '0810000001', 'cle' => $cle]);
        $this->assertSame(1, Paiement::count());

        $this->envoyerRappel($this->simulation()->rappelSigne(RappelFournisseur::PAIEMENT, $cle, (string) $paiement->fresh()->reference_fournisseur, true, '5.00', 'USD'))->assertOk();

        $bon->refresh();
        $this->assertSame(StatutBonExamen::Paye, $bon->statut);
        $this->assertNotNull($bon->paye_le);
        $this->assertNotNull($bon->transmis_le);
        $facture = Facture::sole();
        $this->assertSame(['Examens de laboratoire — bon '.$bon->numero, null], [$facture->libelle, $facture->medecin_id]);
        $this->assertNull(OperationCaisse::sole()->medecin_id, 'Pas d\'honoraires du médecin sur un examen.');
        $this->assertCount(1, $this->sms->envoyes);
        $this->assertSame('MEDIHOME : paiement de 5,00 USD reçu (reçu '.$facture->numero.'). Ouvrez l\'application pour obtenir votre code de retrait.', $this->sms->envoyes[0]['message']);
    }

    public function test_un_rappel_rejoue_ou_un_second_paiement_ne_paie_pas_deux_fois(): void
    {
        $bon = $this->bonPrescrit([$this->examen()], $this->laboratoire());
        $paiement = $this->payerBon($bon);

        // Rejeu du même rappel : sans effet.
        $this->envoyerRappel($this->simulation()->rappelSigne(RappelFournisseur::PAIEMENT, $paiement->cle_idempotence, (string) $paiement->reference_fournisseur, true, '5.00', 'USD'))->assertOk();
        $this->assertSame(1, Facture::count());

        // Un nouveau paiement d'un bon déjà payé est refusé.
        $this->expectException(PaiementRefuse::class);
        app(PaiementService::class)->initierBon($bon->fresh(), $this->patientRdv, MoyenPaiement::MPesa, '0810000001', (string) Str::uuid());
    }

    public function test_un_paiement_arrive_alors_que_le_bon_est_deja_paye_est_rembourse(): void
    {
        $bon = $this->bonPrescrit([$this->examen()], $this->laboratoire());
        $this->payerBon($bon);
        // Une seconde tentative partie avant (autre appareil), confirmée ensuite par l'opérateur.
        $second = new Paiement;
        $second->forceFill([
            'patient_id' => $this->patientRdv->id, 'bon_examen_id' => $bon->id, 'objet' => 'examen', 'moyen' => MoyenPaiement::MPesa,
            'telephone_payeur' => '+243810000001', 'montant' => '5.00', 'devise' => 'USD', 'montant_reference' => '5.00', 'devise_reference' => 'USD',
            'statut' => StatutPaiement::EnAttente, 'cle_idempotence' => (string) Str::uuid(), 'fournisseur' => 'simulation', 'reference_fournisseur' => 'SIM-P-SECOND',
        ])->save();

        $this->envoyerRappel($this->simulation()->rappelSigne(RappelFournisseur::PAIEMENT, $second->cle_idempotence, 'SIM-P-SECOND', true, '5.00', 'USD'))->assertOk();

        $this->assertSame(StatutBonExamen::Paye, $bon->fresh()->statut);
        $this->assertSame([$second->id, MotifRemboursement::PaiementEnDouble], [Remboursement::sole()->paiement_id, Remboursement::sole()->motif]);
    }

    public function test_un_autre_patient_ne_touche_pas_au_bon(): void
    {
        $bon = $this->bonPrescrit([$this->examen()]);
        $autre = $this->patient();

        $this->actingAs($autre)->post(route('examens.laboratoire', $bon->id), ['laboratoire_id' => $this->laboratoire()->id])->assertNotFound();
        $this->actingAs($autre)->post(route('examens.payer', $bon->id), ['moyen' => 'mpesa', 'telephone' => '0810000001', 'cle' => (string) Str::uuid()])->assertNotFound();
        $this->actingAs($autre)->post(route('examens.code', $bon->id))->assertNotFound();
        $this->actingAs($autre)->get(route('examens.index'))->assertInertia(fn (AssertableInertia $p) => $p->has('bons', 0));
        foreach (['caisse', 'admin_fonctionnel', 'laboratoire'] as $role) {
            $this->assertNotSame(200, $this->commePersonnel($this->personnelActive($role))->get(route('examens.index'))->status());
        }
        $this->assertNull($bon->fresh()->laboratoire_id);
    }

    public function test_le_code_de_retrait_est_donne_une_fois_apres_paiement_et_garde_par_empreinte(): void
    {
        $bon = $this->bonPrescrit([$this->examen()], $this->laboratoire());

        $this->commePatientRdv()->post(route('examens.code', $bon->id))->assertSessionHasErrors(['examens' => 'Le code de retrait est donné une fois le bon payé.']);
        $this->payerBon($bon);

        $reponse = $this->commePatientRdv()->post(route('examens.code', $bon->id))->assertSessionHasNoErrors();
        $code = $reponse->getSession()->get('code_retrait')['code'];
        $this->assertMatchesRegularExpression('/^\d{8}$/', $code);
        $bon->refresh();
        $this->assertSame(BonsPatient::empreinteCode($bon, $code), $bon->code_retrait_empreinte);
        $this->assertNotNull($bon->code_retrait_remis_le);
        $this->assertStringNotContainsString($code, json_encode($bon->getAttributes()), 'Jamais en clair en base.');

        // Montré une seule fois : la page suivante l'affiche, celle d'après non.
        $this->commePatientRdv()->withSession(['code_retrait' => ['bon' => $bon->id, 'code' => $code]])->get(route('examens.index'))
            ->assertInertia(fn (AssertableInertia $p) => $p->where('codeRetrait.code', $code));
        $this->commePatientRdv()->get(route('examens.index'))
            ->assertInertia(fn (AssertableInertia $p) => $p->where('codeRetrait', null)->where('bons.0.codeDejaRemis', true));

        // Second clic : refusé ; un nouveau code remplace l'ancien.
        $this->commePatientRdv()->post(route('examens.code', $bon->id))->assertSessionHasErrors('examens');
        $nouveau = $this->commePatientRdv()->post(route('examens.code', $bon->id), ['nouveau' => true])->getSession()->get('code_retrait')['code'];
        $this->assertSame(BonsPatient::empreinteCode($bon, $nouveau), $bon->fresh()->code_retrait_empreinte);
        $this->assertNotSame(BonsPatient::empreinteCode($bon, $code), $bon->fresh()->code_retrait_empreinte);
    }

    public function test_le_service_de_paiement_refuse_le_bon_d_un_autre_patient(): void
    {
        $bon = $this->bonPrescrit([$this->examen()], $this->laboratoire());

        try {
            app(PaiementService::class)->initierBon($bon, $this->patient(), MoyenPaiement::MPesa, '0810000001', (string) Str::uuid());
            $this->fail('Paiement du bon d\'un autre patient accepté.');
        } catch (PaiementRefuse $refus) {
            $this->assertSame('Ce bon d\'examen n\'est pas le vôtre.', $refus->getMessage());
        }
        $this->assertSame(0, Paiement::count());
    }

    public function test_un_bon_paye_ne_change_plus_de_laboratoire(): void
    {
        $bon = $this->bonPrescrit([$this->examen()], $this->laboratoire('Premier'));
        $this->payerBon($bon);

        $this->expectException(LaboratoireRefuse::class);
        app(BonsPatient::class)->choisirLaboratoire($this->patientRdv, $bon->fresh(), $this->laboratoire('Second')->id);
    }

    public function test_mes_examens_montre_les_bons_du_patient_et_rien_des_resultats_avant_validation(): void
    {
        $labo = $this->laboratoire();
        $compte = $this->compteLaboratoire($labo);
        $bon = $this->bonEnCours($labo, $compte, [$this->examen()]);
        $this->deposer($labo, $compte, $bon, valeurs: [['analyte' => 'Glycémie', 'valeur' => '2,40', 'unite' => 'g/L', 'anormal' => true]]);

        $reponse = $this->commePatientRdv()->get(route('examens.index'))->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->component('Laboratoire/MesExamens')
                ->has('bons', 1)
                ->where('bons.0.numero', $bon->numero)
                ->where('bons.0.statut', 'resultats_deposes')
                ->where('bons.0.resultats', []));
        $this->assertStringNotContainsString('2,40', $reponse->getContent(), 'Le patient ne voit rien avant la validation.');
        $this->assertStringContainsString('no-store', $reponse->headers->get('Cache-Control'));
    }
}
