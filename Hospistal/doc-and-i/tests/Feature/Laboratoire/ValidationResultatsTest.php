<?php

namespace Tests\Feature\Laboratoire;

use App\Enums\StatutBonExamen;
use App\Models\AlerteMedecin;
use App\Models\BonExamen;
use App\Models\EntreeAccesDossier;
use App\Models\ResultatExamen;
use App\Services\Dossier\LectureDossier;
use App\Services\Laboratoire\ActeurLaboratoire;
use App\Services\Laboratoire\DepotResultats;
use App\Sms\EnvoyeurSms;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use RuntimeException;
use Tests\Support\EnvoyeurSmsFactice;
use Tests\Support\ParcoursConsultation;
use Tests\Support\ParcoursLaboratoire;
use Tests\Support\ParcoursPaiement;
use Tests\Support\ParcoursRendezVous;
use Tests\Support\PersonnelDeTest;
use Tests\TestCase;

/** PRE-07, PRE-08, RG-10 : validation des résultats par le médecin prescripteur, alertes, historique. */
class ValidationResultatsTest extends TestCase
{
    use ParcoursConsultation, ParcoursLaboratoire, ParcoursPaiement, ParcoursRendezVous, PersonnelDeTest, RefreshDatabase;

    private EnvoyeurSmsFactice $sms;

    private BonExamen $bon;

    private ResultatExamen $resultat;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesSeeder::class);
        Storage::fake('local');
        $this->preparerConsultation('09:05');
        $this->sms = new EnvoyeurSmsFactice;
        $this->app->instance(EnvoyeurSms::class, $this->sms);
        $labo = $this->laboratoire();
        $compte = $this->compteLaboratoire($labo);
        $this->bon = $this->bonEnCours($labo, $compte, [$this->examen()]);
        $this->resultat = app(DepotResultats::class)->deposer(ActeurLaboratoire::compte($compte, $labo), $this->bon, [
            'valeurs' => [['analyte' => 'Glycémie', 'valeur' => '2,40', 'unite' => 'g/L', 'normes' => '0,70 - 1,10', 'anormal' => true]],
            'critique' => true,
        ], $this->pdfSimple());
        $this->sms->envoyes = [];
    }

    private function valider(?string $commentaire = 'Contrôle dans une semaine.', $comme = null)
    {
        return $this->commePersonnel($comme ?? $this->medecinRdv->user)->post(route('personnel.medecin.resultats.valider', $this->resultat->id), ['commentaire' => $commentaire]);
    }

    public function test_le_medecin_voit_ses_resultats_a_valider_et_sa_lecture_est_journalisee(): void
    {
        $this->commeMedecinRdv()->get(route('personnel.medecin.resultats'))->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->component('Laboratoire/ResultatsAValider')->has('bons', 1)
                ->where('bons.0.numero', $this->bon->numero)->where('bons.0.critique', true));

        $this->commeMedecinRdv()->get(route('personnel.medecin.resultats.bon', $this->bon->id))->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->component('Laboratoire/Resultats')
                ->where('resultats.0.valeurs.0.valeur', '2,40')->where('peutValider', true)->where('enRemplacement', false));
        $this->commeMedecinRdv()->get(route('personnel.medecin.resultats.pdf', $this->resultat->id))->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')->assertDownload('compte-rendu-'.$this->bon->numero.'.pdf');

        $entrees = EntreeAccesDossier::orderBy('objet')->get();
        $this->assertSame(['resultat_pdf', 'resultats_examen'], $entrees->pluck('objet')->all());
        $this->assertSame([$this->medecinRdv->user_id, $this->patientRdv->id], [$entrees[1]->lecteur_id, $entrees[1]->patient_id]);
    }

    public function test_valider_rend_le_resultat_visible_au_patient_et_le_previent_sans_contenu(): void
    {
        $this->commePatientRdv()->get(route('examens.pdf', $this->resultat->id))->assertNotFound();

        $this->valider()->assertSessionHasNoErrors();

        $this->resultat->refresh();
        $this->assertSame([$this->medecinRdv->user_id, 'Contrôle dans une semaine.', false], [$this->resultat->valide_par, $this->resultat->commentaire_medecin, $this->resultat->valide_en_remplacement]);
        $this->assertSame(StatutBonExamen::Valide, $this->bon->fresh()->statut);
        $this->assertSame([['telephone' => $this->patientRdv->telephone, 'message' => 'MEDIHOME : un document vous attend dans votre espace.']], $this->sms->envoyes);
        $this->assertSame(0, AlerteMedecin::whereNull('traitee_le')->count(), 'Alertes du bon traitées.');

        $this->commePatientRdv()->get(route('examens.index'))
            ->assertInertia(fn (AssertableInertia $p) => $p->where('bons.0.statut', 'valide')->where('bons.0.resultats.0.valeurs.0.valeur', '2,40')
                ->where('bons.0.resultats.0.commentaire', 'Contrôle dans une semaine.'));
        $this->commePatientRdv()->get(route('examens.pdf', $this->resultat->id))->assertOk()->assertDownload('compte-rendu-'.$this->bon->numero.'.pdf');
        $this->actingAs($this->patient())->get(route('examens.pdf', $this->resultat->id))->assertNotFound();
    }

    public function test_une_double_validation_ne_change_rien_et_n_envoie_qu_un_sms(): void
    {
        $this->valider('Premier.');
        $this->valider('Second.')->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('Premier.', $this->resultat->fresh()->commentaire_medecin);
        $this->assertCount(1, $this->sms->envoyes);
        $this->expectException(\LogicException::class);
        $this->resultat->fresh()->forceFill(['commentaire_medecin' => 'x'])->save();
    }

    public function test_seul_le_prescripteur_valide_ni_un_confrere_ni_la_caisse_ni_l_administration(): void
    {
        $confrere = $this->medecinActive()->user;
        $this->valider(comme: $confrere)->assertForbidden();
        $this->commePersonnel($confrere)->get(route('personnel.medecin.resultats.bon', $this->bon->id))->assertForbidden();
        $this->commePersonnel($confrere)->get(route('personnel.medecin.resultats.pdf', $this->resultat->id))->assertForbidden();
        foreach (['caisse', 'admin_fonctionnel', 'laboratoire'] as $role) {
            $this->commePersonnel($this->personnelActive($role))->post(route('personnel.medecin.resultats.valider', $this->resultat->id))->assertForbidden();
        }
        // Le coordinateur, sans escalade : rien.
        $coordinateur = $this->personnelActive('coordinateur');
        $this->commePersonnel($coordinateur)->post(route('personnel.coordination.resultats.valider', $this->resultat->id))->assertForbidden();
        $this->commePersonnel($coordinateur)->get(route('personnel.coordination.bon', $this->bon->id))->assertForbidden();

        $this->assertNull($this->resultat->fresh()->valide_le);
        $this->assertSame(0, EntreeAccesDossier::count(), 'Un refus n\'est pas une lecture.');
    }

    public function test_les_alertes_du_medecin_se_marquent_lues_puis_traitees(): void
    {
        $this->commeMedecinRdv()->get(route('personnel.medecin.alertes'))->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->component('Laboratoire/AlertesMedecin')->has('alertes', 2)
                ->where('alertes.0.gravite', 'critique')->where('alertes.0.bonId', $this->bon->id));
        $critique = AlerteMedecin::where('type', 'resultat_critique')->sole();

        $this->commePersonnel($this->medecinActive()->user)->post(route('personnel.medecin.alertes.marquer', [$critique->id, 'traitee']))->assertForbidden();
        $this->commeMedecinRdv()->post(route('personnel.medecin.alertes.marquer', [$critique->id, 'lue']));
        $this->assertNotNull($critique->fresh()->lue_le);
        $this->assertNull($critique->fresh()->traitee_le);
        $this->commeMedecinRdv()->post(route('personnel.medecin.alertes.marquer', [$critique->id, 'traitee']));
        $this->assertNotNull($critique->fresh()->traitee_le);

        $this->commeMedecinRdv()->get(route('personnel.medecin.alertes'))->assertInertia(fn (AssertableInertia $p) => $p->has('alertes', 1));
    }

    public function test_l_historique_montre_le_bon_puis_les_resultats_valides_seulement(): void
    {
        $lecture = app(LectureDossier::class);
        $types = fn () => array_column($lecture->historique($this->patientRdv, true), 'type');

        $this->assertContains('bon_examen', $types());
        $this->assertNotContains('resultat_examen', $types(), 'Rien avant la validation.');

        $this->valider();
        $historique = collect($lecture->historique($this->patientRdv, false))->firstWhere('type', 'resultat_examen');
        $this->assertStringContainsString($this->bon->numero, $historique['titre']);
        $this->assertContains('Glycémie : 2,40 g/L (normes : 0,70 - 1,10) — anormal', $historique['details']);
    }

    public function test_aucune_donnee_de_sante_dans_les_sms_les_journaux_et_les_adresses(): void
    {
        $this->app->instance(EnvoyeurSms::class, new class implements EnvoyeurSms
        {
            public function envoyer(string $telephone, string $message): void
            {
                throw new RuntimeException("Échec vers {$telephone} : {$message}");
            }
        });
        Log::spy();

        $this->valider()->assertSessionHasNoErrors();

        $this->assertNotNull($this->resultat->fresh()->valide_le, 'Une panne SMS ne défait rien.');
        Log::shouldHaveReceived('warning')->withArgs(function (string $message, array $contexte) {
            $texte = $message.json_encode($contexte);

            return ! str_contains($texte, (string) $this->patientRdv->telephone) && ! str_contains($texte, 'Glycémie') && ! str_contains($texte, '2,40');
        })->once();

        // Adresses : identifiants seulement ; le code de retrait ne passe jamais dans une URL.
        foreach (['examens.code' => [$this->bon->id], 'examens.pdf' => [$this->resultat->id], 'personnel.medecin.resultats.bon' => [$this->bon->id]] as $route => $parametres) {
            $this->assertMatchesRegularExpression('#/[0-9a-f-]{36}(/[a-z]+)?$#', route($route, $parametres));
        }
        foreach (AlerteMedecin::pluck('message') as $message) {
            $this->assertStringNotContainsString('Glycémie', $message);
        }
    }

    public function test_un_resultat_valide_ne_se_modifie_plus_en_base(): void
    {
        $this->valider();

        $this->expectExceptionMessageMatches('/non modifiable/');
        DB::transaction(fn () => DB::table('resultats_examen')->update(['critique' => false]));
    }
}
