<?php

namespace Tests\Feature\Laboratoire;

use App\Enums\StatutBonExamen;
use App\Models\BonExamen;
use App\Models\JetonLaboratoire;
use App\Models\Partenaire;
use App\Models\ResultatExamen;
use App\Services\Laboratoire\JetonsLaboratoire;
use App\Sms\EnvoyeurSms;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\EnvoyeurSmsFactice;
use Tests\Support\ParcoursConsultation;
use Tests\Support\ParcoursLaboratoire;
use Tests\Support\ParcoursPaiement;
use Tests\Support\ParcoursRendezVous;
use Tests\Support\PersonnelDeTest;
use Tests\TestCase;

/** PRE-06 : API des laboratoires, même service que le portail, jeton Bearer. */
class ApiLaboratoireTest extends TestCase
{
    use ParcoursConsultation, ParcoursLaboratoire, ParcoursPaiement, ParcoursRendezVous, PersonnelDeTest, RefreshDatabase;

    private Partenaire $labo;

    private string $jeton;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesSeeder::class);
        Storage::fake('local');
        $this->preparerConsultation('09:05');
        $this->app->instance(EnvoyeurSms::class, new EnvoyeurSmsFactice);
        $this->labo = $this->laboratoire('Eyano Centre Biologique (test)');
        [, $this->jeton] = app(JetonsLaboratoire::class)->emettre($this->personnelActive('admin_fonctionnel'), $this->labo, 'Logiciel du laboratoire');
    }

    private function appeler(array $corps, ?string $jeton = null): TestResponse
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.($jeton ?? $this->jeton)])->postJson('/api/laboratoire/v1/resultats', $corps);
    }

    private function bonPaye(): BonExamen
    {
        $bon = $this->bonPrescrit([$this->examen()], $this->labo);
        $this->payerBon($bon);

        return $bon->fresh();
    }

    public function test_le_logiciel_du_laboratoire_depose_avec_numero_et_code_de_retrait(): void
    {
        $bon = $this->bonPaye();
        $code = $this->codeRetrait($bon);

        $reponse = $this->appeler([
            'numero_bon' => $bon->numero, 'code_retrait' => $code, 'cle' => 'eyano-'.Str::random(20),
            'valeurs' => [['analyte' => 'Glycémie', 'valeur' => '1,05', 'unite' => 'g/L', 'normes' => '0,70 - 1,10', 'anormal' => false]],
            'pdf_base64' => base64_encode($this->pdfSimple()),
        ])->assertCreated()->assertJsonPath('statut', 'depose')->assertJsonPath('bon.statut', 'resultats_deposes');

        $this->assertSame(['statut', 'resultat_id', 'bon'], array_keys($reponse->json()), 'Réponse sobre.');
        $this->assertStringContainsString('no-store', $reponse->headers->get('Cache-Control'));
        $this->assertNull($reponse->headers->get('Set-Cookie'), 'Sans session.');
        $resultat = ResultatExamen::sole();
        $jeton = JetonLaboratoire::sole();
        $this->assertSame(['api', null, $jeton->id], [$resultat->source, $resultat->depose_par, $resultat->jeton_id]);
        $this->assertNotNull($jeton->fresh()->dernier_usage_le);
        $this->assertNull($bon->fresh()->recu_par, 'Reçu par le logiciel, pas par un compte.');
    }

    public function test_un_envoi_rejoue_avec_la_meme_cle_ne_double_rien(): void
    {
        $bon = $this->bonPaye();
        $corps = ['numero_bon' => $bon->numero, 'code_retrait' => $this->codeRetrait($bon), 'cle' => 'eyano-'.Str::random(20), 'valeurs' => [['analyte' => 'Glycémie', 'valeur' => '1']]];

        $id = $this->appeler($corps)->assertCreated()->json('resultat_id');
        $this->appeler($corps)->assertOk()->assertJsonPath('statut', 'deja_recu')->assertJsonPath('resultat_id', $id);

        $this->assertSame(1, ResultatExamen::count());
    }

    public function test_un_bon_deja_en_cours_se_complete_avec_son_numero_seul(): void
    {
        $compte = $this->compteLaboratoire($this->labo);
        $bon = $this->bonEnCours($this->labo, $compte, [$this->examen()]);
        $paye = $this->bonPrescrit([$this->examen('NFS', 'NFS')], $this->labo);
        $this->payerBon($paye);

        $this->appeler(['numero_bon' => $bon->numero, 'valeurs' => [['analyte' => 'Glycémie', 'valeur' => '1']]])->assertCreated();
        $this->appeler(['numero_bon' => $paye->numero, 'valeurs' => [['analyte' => 'NFS', 'valeur' => 'normale']]])
            ->assertForbidden()->assertJsonPath('erreur', 'code_retrait_incorrect');
        $this->assertSame(StatutBonExamen::Paye, $paye->fresh()->statut);
    }

    public function test_jeton_absent_invalide_revoque_ou_laboratoire_desactive_repond_401(): void
    {
        $bon = $this->bonPaye();
        $corps = ['numero_bon' => $bon->numero, 'code_retrait' => $this->codeRetrait($bon), 'valeurs' => [['analyte' => 'Glycémie', 'valeur' => '1']]];

        $this->postJson('/api/laboratoire/v1/resultats', $corps)->assertUnauthorized()->assertJsonPath('erreur', 'jeton_invalide');
        $this->appeler($corps, 'mhlab_'.Str::random(48))->assertUnauthorized();
        $this->appeler($corps, 'autre-chose')->assertUnauthorized();

        $this->labo->update(['actif' => false]);
        $this->appeler($corps)->assertUnauthorized();
        $this->labo->update(['actif' => true]);

        app(JetonsLaboratoire::class)->revoquer($this->personnelActive('admin_fonctionnel'), JetonLaboratoire::sole());
        $this->appeler($corps)->assertUnauthorized();

        $this->assertSame(0, ResultatExamen::count());
        $this->assertSame(StatutBonExamen::Paye, $bon->fresh()->statut);
    }

    public function test_le_bon_d_un_autre_laboratoire_ou_un_code_faux_est_refuse(): void
    {
        $autreLabo = $this->laboratoire('Autre laboratoire');
        $bonAutre = $this->bonPrescrit([$this->examen('URE', 'Urée')], $autreLabo);
        $this->payerBon($bonAutre);
        $code = $this->codeRetrait($bonAutre->fresh());

        $this->appeler(['numero_bon' => $bonAutre->numero, 'code_retrait' => $code, 'valeurs' => [['analyte' => 'A', 'valeur' => '1']]])
            ->assertNotFound()->assertJsonPath('erreur', 'bon_introuvable');
        $this->appeler(['numero_bon' => 'BE-2026-999999', 'code_retrait' => $code, 'valeurs' => [['analyte' => 'A', 'valeur' => '1']]])->assertNotFound();

        $bon = $this->bonPaye();
        $this->codeRetrait($bon);
        $this->appeler(['numero_bon' => $bon->numero, 'code_retrait' => '00000000', 'valeurs' => [['analyte' => 'A', 'valeur' => '1']]])
            ->assertForbidden()->assertJsonPath('erreur', 'code_retrait_incorrect');

        $this->assertSame(0, ResultatExamen::count());
    }

    public function test_donnees_invalides_et_pdf_falsifie(): void
    {
        $bon = $this->bonPaye();
        $code = $this->codeRetrait($bon);

        $this->appeler(['numero_bon' => 'n\'importe quoi'])->assertStatus(422)->assertJsonPath('erreur', 'donnees_invalides');
        $this->appeler(['numero_bon' => $bon->numero, 'code_retrait' => $code, 'pdf_base64' => '%%% pas du base64 %%%'])->assertStatus(422);
        $this->appeler(['numero_bon' => $bon->numero, 'code_retrait' => $code, 'valeurs' => [['analyte' => '', 'valeur' => '1']]])->assertStatus(422);
        $this->appeler(['numero_bon' => $bon->numero, 'code_retrait' => $code])->assertStatus(422);
        $this->appeler(['numero_bon' => $bon->numero, 'code_retrait' => $code, 'pdf_base64' => base64_encode('<html>faux</html>')])
            ->assertStatus(409)->assertJsonPath('message', 'Seul un compte rendu au format PDF est accepté.');
        $this->withHeaders(['Authorization' => 'Bearer '.$this->jeton])->post('/api/laboratoire/v1/resultats', ['numero_bon' => $bon->numero])->assertStatus(415);

        $this->assertSame(0, ResultatExamen::count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_le_debit_est_limite_par_jeton_et_les_essais_de_jetons_par_adresse(): void
    {
        config(['doc-and-i.laboratoire.api_requetes_par_minute' => 3]);

        for ($i = 0; $i < 3; $i++) {
            $this->appeler(['numero_bon' => 'BE-2026-999999', 'valeurs' => [['analyte' => 'A', 'valeur' => '1']]])->assertNotFound();
        }
        $this->appeler(['numero_bon' => 'BE-2026-999999'])->assertStatus(429)->assertJsonPath('erreur', 'trop_de_requetes');

        for ($i = 0; $i < 3; $i++) {
            $this->appeler([], 'mhlab_'.Str::random(48))->assertUnauthorized();
        }
        $this->appeler([], 'mhlab_'.Str::random(48))->assertStatus(429);
    }

    public function test_le_jeton_n_est_garde_que_par_son_empreinte(): void
    {
        $jeton = JetonLaboratoire::sole();

        $this->assertSame(hash('sha256', $this->jeton), $jeton->empreinte);
        $this->assertStringNotContainsString($this->jeton, json_encode($jeton->getAttributes()));
        $this->assertStringStartsWith('mhlab_', $this->jeton);
    }
}
