<?php

namespace Tests\Feature\Dossier;

use App\Enums\StatutPaiement;
use App\Enums\StatutRendezVous;
use App\Models\Medecin;
use App\Models\RendezVous;
use App\Models\User;
use App\Services\Dossier\DossierService;
use App\Services\Dossier\LectureDossier;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ParcoursDossier;
use Tests\Support\ParcoursPaiement;
use Tests\Support\ParcoursRendezVous;
use Tests\Support\PersonnelDeTest;
use Tests\TestCase;

/** DOS-01 et DOS-03 : qui lit le dossier, et l'historique chronologique. */
class LectureDossierTest extends TestCase
{
    use ParcoursDossier, ParcoursPaiement, ParcoursRendezVous, PersonnelDeTest, RefreshDatabase;

    private User $patientDossier;

    private Medecin $medecinTraitant;

    private RendezVous $rdv;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesSeeder::class);
        $this->voyagerAuLundi();
        $this->patientDossier = $this->patient();
        $this->medecinTraitant = $this->medecinActive();
        $this->rdv = $this->rendezVousExistant($this->medecinTraitant, $this->patientDossier, $this->lundi('09:00'));
    }

    private function urlMedecin(): string
    {
        return "/personnel/medecin/patients/{$this->patientDossier->id}/dossier";
    }

    public function test_le_patient_lit_son_dossier_allergies_comprises(): void
    {
        app(DossierService::class)->ajouterAntecedent($this->patientDossier, $this->patientDossier, 'allergie', 'Pénicilline', 'Œdème');

        $this->actingAs($this->patientDossier)->get('/mon-dossier')->assertOk()
            ->assertInertia(fn ($page) => $page->component('Dossier/MonDossier')
                ->where('elements.0.categorie', 'allergie')
                ->where('elements.0.elements.0.libelle', 'Pénicilline')
                ->where('elements.0.elements.0.precision', 'Œdème')
                ->where('groupeSanguin', null)
                ->has('categories', 5)->has('mesures', 6));
    }

    public function test_le_medecin_traitant_lit_le_dossier_depuis_l_agenda(): void
    {
        $this->commePersonnel($this->medecinTraitant->user)
            ->get("/personnel/medecin/rendez-vous/{$this->rdv->id}/dossier")
            ->assertRedirect($this->urlMedecin());

        $this->commePersonnel($this->medecinTraitant->user)->get($this->urlMedecin())->assertOk()
            ->assertInertia(fn ($page) => $page->component('Personnel/Medecin/Dossier')
                ->where('patient.id', $this->patientDossier->id)->missing('acces'));
    }

    public function test_le_medecin_d_une_consultation_terminee_lit_encore_le_dossier(): void
    {
        $medecin = $this->medecinActive();
        $this->rendezVousExistant($medecin, $this->patientDossier, $this->lundi('07:00')->subWeek(), statut: StatutRendezVous::Termine);

        $this->commePersonnel($medecin->user)->get($this->urlMedecin())->assertOk();
    }

    public function test_un_autre_medecin_ou_une_relation_annulee_expiree_en_attente_n_ouvre_rien(): void
    {
        foreach ([null, StatutRendezVous::AnnuleParPatient, StatutRendezVous::AnnuleParMedecin, StatutRendezVous::Expire, StatutRendezVous::EnAttentePaiement] as $statut) {
            $medecin = $this->medecinActive();
            $rdv = $statut ? $this->rendezVousExistant($medecin, $this->patientDossier, $this->lundi('11:00'), statut: $statut) : null;

            $this->commePersonnel($medecin->user)->get($this->urlMedecin())->assertNotFound();
            if ($rdv) {
                // Le raccourci depuis l'agenda redirige, puis la relation de soin est revérifiée.
                $this->commePersonnel($medecin->user)->get("/personnel/medecin/rendez-vous/{$rdv->id}/dossier")
                    ->assertRedirect($this->urlMedecin());
            }
            // Le rendez-vous d'un autre médecin n'est pas un raccourci.
            $this->commePersonnel($medecin->user)->get("/personnel/medecin/rendez-vous/{$this->rdv->id}/dossier")->assertNotFound();
        }
    }

    public function test_un_medecin_desactive_ne_lit_plus(): void
    {
        $this->medecinTraitant->user->forceFill(['desactive_le' => now()])->save();

        $reponse = $this->commePersonnel($this->medecinTraitant->user->fresh())->get($this->urlMedecin());
        $this->assertNotSame(200, $reponse->status());
    }

    public function test_caisse_administration_patient_et_visiteur_n_ont_pas_acces(): void
    {
        foreach (['caisse', 'admin_fonctionnel'] as $role) {
            $this->commePersonnel($this->personnelActive($role))->get($this->urlMedecin())->assertForbidden();
            $this->commePersonnel($this->personnelActive($role))->get('/mon-dossier')->assertForbidden();
        }

        $this->actingAs($this->patientDossier)->get($this->urlMedecin())->assertForbidden();
        $this->actingAs($this->patient())->get($this->urlMedecin())->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->get('/mon-dossier')->assertRedirect('/connexion');
    }

    public function test_un_autre_patient_ne_voit_pas_ce_dossier(): void
    {
        app(DossierService::class)->ajouterAntecedent($this->patientDossier, $this->patientDossier, 'allergie', 'Pénicilline');

        $this->actingAs($this->patient())->get('/mon-dossier')->assertOk()
            ->assertInertia(fn ($page) => $page->where('elements.0.elements', [])->where('historique', []));
    }

    public function test_l_historique_montre_notes_signees_avenants_ordonnances_et_consultations_jamais_un_brouillon(): void
    {
        $termine = $this->rendezVousExistant($this->medecinTraitant, $this->patientDossier, $this->lundi('07:00')->subWeek(), statut: StatutRendezVous::Termine);
        $note = $this->noteExistante($termine, true, 'Douleur thoracique');
        $this->avenantExistant($note, 'Allergie au latex signalée après coup');
        $this->ordonnanceExistante($termine, 'Amoxicilline');
        $this->noteExistante($this->rdv, false, 'BROUILLON-SECRET');

        $types = fn (array $h) => array_column($h, 'type');
        $lectures = [
            fn () => $this->actingAs($this->patientDossier)->get('/mon-dossier'),
            fn () => $this->commePersonnel($this->medecinTraitant->user)->get($this->urlMedecin()),
        ];
        foreach ($lectures as $lire) {
            $reponse = $lire()->assertOk();
            $historique = $reponse->viewData('page')['props']['historique'];

            $this->assertContains('consultation', $types($historique));
            $this->assertContains('ordonnance', $types($historique));
            $this->assertSame(1, count(array_keys($types($historique), 'note')), 'Une seule note : la signée.');
            $evenementNote = collect($historique)->firstWhere('type', 'note');
            $this->assertSame($note->id, $evenementNote['id']);
            $this->assertContains('Motif : Douleur thoracique', $evenementNote['details']);
            $this->assertSame('Allergie au latex signalée après coup', $evenementNote['avenants'][0]['contenu']);
            $this->assertStringContainsString('Amoxicilline', json_encode(collect($historique)->firstWhere('type', 'ordonnance')['details'], JSON_UNESCAPED_UNICODE));
            $this->assertStringNotContainsString('BROUILLON-SECRET', $reponse->getContent(), 'Un brouillon n\'apparaît jamais.');
        }
    }

    public function test_les_paiements_figurent_pour_le_patient_seulement(): void
    {
        $this->paiementExistant($this->rdv);
        $this->paiementExistant($this->rdv, StatutPaiement::Echoue);

        $this->actingAs($this->patientDossier)->get('/mon-dossier')->assertInertia(fn ($page) => $page
            ->where('historique', fn ($h) => collect($h)->where('type', 'paiement')->count() === 1));
        $this->commePersonnel($this->medecinTraitant->user)->get($this->urlMedecin())->assertInertia(fn ($page) => $page
            ->where('historique', fn ($h) => collect($h)->where('type', 'paiement')->isEmpty()));
    }

    public function test_l_historique_va_du_plus_recent_au_plus_ancien(): void
    {
        $service = app(DossierService::class);
        $service->ajouterAntecedent($this->patientDossier, $this->patientDossier, 'medical', 'Paludisme');
        $this->travel(2)->hours();
        $service->ajouterAntecedent($this->patientDossier, $this->patientDossier, 'medical', 'Typhoïde');

        $this->actingAs($this->patientDossier)->get('/mon-dossier')->assertInertia(fn ($page) => $page
            ->where('historique.0.titre', 'Antécédent médical ajouté(e) : Typhoïde')
            ->where('historique.1.titre', 'Antécédent médical ajouté(e) : Paludisme'));
    }

    public function test_pendant_l_appel_le_medecin_recoit_allergies_traitements_et_constantes(): void
    {
        $service = app(DossierService::class);
        $service->ajouterAntecedent($this->patientDossier, $this->patientDossier, 'allergie', 'Sulfamides', 'Urticaire');
        $service->ajouterAntecedent($this->patientDossier, $this->patientDossier, 'traitement', 'Amlodipine');
        $retire = $service->ajouterAntecedent($this->patientDossier, $this->patientDossier, 'traitement', 'Ancien traitement');
        $service->retirerAntecedent($this->patientDossier, $retire);
        $service->ajouterConstante($this->patientDossier, $this->patientDossier, 'temperature', '37,8');
        $service->definirGroupeSanguin($this->patientDossier, $this->patientDossier, 'B+');

        $resume = app(LectureDossier::class)->resumePourAppel($this->patientDossier);

        $this->assertSame('B+', $resume['groupeSanguin']);
        $this->assertSame(['Sulfamides (Urticaire)'], $resume['allergies']);
        $this->assertSame(['Amlodipine'], $resume['traitements'], 'Un traitement retiré n\'est plus « en cours ».');
        $this->assertSame('37,8 °C', $resume['constantes'][0]['valeur']);
        $this->assertSame(['medical' => [], 'chirurgical' => [], 'familial' => []], $resume['antecedents']);
    }
}
