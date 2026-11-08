<?php

namespace Tests\Feature\Dossier;

use App\Enums\StatutRendezVous;
use App\Models\Antecedent;
use App\Models\Constante;
use App\Models\DemandeRectification;
use App\Models\DossierPatient;
use App\Models\Medecin;
use App\Models\RendezVous;
use App\Models\User;
use App\Services\Dossier\DossierRefuse;
use App\Services\Dossier\DossierService;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ParcoursRendezVous;
use Tests\Support\PersonnelDeTest;
use Tests\TestCase;

/**
 * Revue de sécurité du dossier (M3, F1) : lire n'est pas modifier. Un
 * médecin ne modifie le dossier qu'après avoir vu le patient (rendez-vous
 * terminé, ou confirmé et salle ouverte) ; un groupe sanguin enregistré par
 * un médecin lui est attribué, même si la valeur ne change pas.
 */
class ModificationDossierTest extends TestCase
{
    use ParcoursRendezVous, PersonnelDeTest, RefreshDatabase;

    private const LECTURE_SEULE = 'Vous pourrez modifier ce dossier après avoir vu le patient : consultation ouverte ou terminée.';

    private User $patientDossier;

    private Medecin $medecin;

    private RendezVous $rdvDansUnMois;

    private Antecedent $penicilline;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesSeeder::class);
        $this->voyagerAuLundi();
        $this->patientDossier = $this->patient();
        $this->medecin = $this->medecinActive();
        // Scénario de la revue : un seul rendez-vous, confirmé, dans 28 jours.
        $this->rdvDansUnMois = $this->rendezVousExistant($this->medecin, $this->patientDossier, $this->lundi('09:00')->addDays(28));
        $this->penicilline = app(DossierService::class)->ajouterAntecedent($this->patientDossier, $this->patientDossier, 'allergie', 'Pénicilline');
    }

    private function url(string $suffixe = ''): string
    {
        return "/personnel/medecin/patients/{$this->patientDossier->id}/dossier{$suffixe}";
    }

    public function test_avec_un_rendez_vous_dans_un_mois_le_medecin_lit_mais_ne_retire_pas_l_allergie(): void
    {
        $this->commePersonnel($this->medecin->user);
        $this->get($this->url())->assertOk()->assertInertia(fn ($page) => $page->where('modifiable', false));

        $this->post($this->url("/antecedents/{$this->penicilline->id}/retrait"))->assertSessionHasErrors(['retrait' => self::LECTURE_SEULE]);
        $this->assertNull($this->penicilline->fresh()->retire_le, 'L\'allergie est toujours là.');
    }

    public function test_avant_d_avoir_vu_le_patient_aucune_ecriture_du_medecin(): void
    {
        $demande = app(DossierService::class)->demanderRectification($this->patientDossier, 'Je ne suis plus allergique.');
        $this->commePersonnel($this->medecin->user);

        $this->post($this->url('/antecedents'), ['categorie' => 'medical', 'libelle' => 'Asthme'])->assertSessionHasErrors(['antecedent' => self::LECTURE_SEULE]);
        $this->post($this->url('/constantes'), ['type' => 'poids', 'valeur' => '70'])->assertSessionHasErrors(['constante' => self::LECTURE_SEULE]);
        $this->post($this->url('/groupe-sanguin'), ['groupe_sanguin' => 'O+'])->assertSessionHasErrors(['groupe_sanguin' => self::LECTURE_SEULE]);
        $this->post($this->url("/rectifications/{$demande->id}/reponse"), ['decision' => 'refusee', 'motif' => 'Non.'])
            ->assertSessionHasErrors(['rectification' => self::LECTURE_SEULE]);

        $this->assertSame(1, Antecedent::count());
        $this->assertSame(0, Constante::count());
        $this->assertNull(DossierPatient::where('patient_id', $this->patientDossier->id)->value('groupe_sanguin'));
        $this->assertSame('ouverte', $demande->fresh()->statut);
    }

    public function test_le_service_refuse_aussi_sans_passer_par_l_ecran(): void
    {
        $this->expectException(DossierRefuse::class);
        $this->expectExceptionMessage(self::LECTURE_SEULE);
        app(DossierService::class)->retirerAntecedent($this->medecin->user, $this->penicilline);
    }

    public function test_salle_ouverte_le_medecin_modifie(): void
    {
        $this->travelTo($this->rdvDansUnMois->debut->subMinutes(5));
        $this->commePersonnel($this->medecin->user);

        $this->get($this->url())->assertInertia(fn ($page) => $page->where('modifiable', true));
        $this->post($this->url("/antecedents/{$this->penicilline->id}/retrait"))->assertSessionHasNoErrors();
        $this->assertSame($this->medecin->user_id, $this->penicilline->fresh()->retire_par);
    }

    public function test_apres_la_fenetre_seul_un_rendez_vous_termine_permet_de_modifier(): void
    {
        $this->travelTo($this->rdvDansUnMois->fin->addHours(3));
        $this->commePersonnel($this->medecin->user)
            ->post($this->url('/antecedents'), ['categorie' => 'medical', 'libelle' => 'Asthme'])->assertSessionHasErrors('antecedent');

        $this->rdvDansUnMois->forceFill(['statut' => StatutRendezVous::Termine])->save();
        $this->commePersonnel($this->medecin->user)
            ->post($this->url('/antecedents'), ['categorie' => 'medical', 'libelle' => 'Asthme'])->assertSessionHasNoErrors();
        $this->assertSame('medecin', Antecedent::where('libelle', 'Asthme')->sole()->source);
    }

    public function test_le_patient_modifie_toujours_son_dossier(): void
    {
        $this->actingAs($this->patientDossier)->get('/mon-dossier')->assertInertia(fn ($page) => $page->where('modifiable', true));
        $this->actingAs($this->patientDossier)->post("/mon-dossier/antecedents/{$this->penicilline->id}/retrait")->assertSessionHasNoErrors();
        $this->assertNotNull($this->penicilline->fresh()->retire_le);
    }

    public function test_un_groupe_identique_enregistre_par_le_medecin_lui_est_attribue_et_le_patient_ne_le_change_plus(): void
    {
        $service = app(DossierService::class);
        $service->definirGroupeSanguin($this->patientDossier, $this->patientDossier, 'O+');
        $this->rdvDansUnMois->forceFill(['statut' => StatutRendezVous::Termine])->save();
        $this->travel(1)->hours();

        $this->commePersonnel($this->medecin->user)->post($this->url('/groupe-sanguin'), ['groupe_sanguin' => 'O+'])->assertSessionHasNoErrors();

        $dossier = DossierPatient::where('patient_id', $this->patientDossier->id)->sole();
        $this->assertSame(['O+', $this->medecin->user_id], [$dossier->groupe_sanguin, $dossier->groupe_sanguin_par]);
        $this->assertTrue(now()->startOfSecond()->equalTo($dossier->groupe_sanguin_le), 'Date de la saisie du médecin.');

        $this->actingAs($this->patientDossier)->post('/mon-dossier/groupe-sanguin', ['groupe_sanguin' => 'A+'])
            ->assertSessionHasErrors(['groupe_sanguin' => 'Votre groupe sanguin a été saisi par un médecin : demandez-en la rectification.']);
        $this->assertSame('O+', $dossier->fresh()->groupe_sanguin);
    }

    public function test_une_demande_existante_n_est_pas_ecrasee_par_un_tiers(): void
    {
        // Contrôle : aucune écriture du service ne contourne peutModifier.
        $demande = app(DossierService::class)->demanderRectification($this->patientDossier, 'Je ne suis plus allergique.');

        try {
            app(DossierService::class)->repondreRectification($this->medecin->user, $demande, true, 'Accepté.');
            $this->fail('Réponse acceptée avant d\'avoir vu le patient.');
        } catch (DossierRefuse $refus) {
            $this->assertSame(self::LECTURE_SEULE, $refus->getMessage());
        }
        $this->assertSame('ouverte', DemandeRectification::sole()->statut);
    }
}
