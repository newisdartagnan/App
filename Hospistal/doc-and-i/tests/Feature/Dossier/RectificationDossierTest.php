<?php

namespace Tests\Feature\Dossier;

use App\Enums\StatutRendezVous;
use App\Models\DemandeRectification;
use App\Models\Medecin;
use App\Models\RendezVous;
use App\Models\User;
use App\Services\Dossier\DossierService;
use App\Services\Dossier\LectureDossier;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ParcoursDossier;
use Tests\Support\ParcoursRendezVous;
use Tests\Support\PersonnelDeTest;
use Tests\TestCase;

/** DOS-04 : rectification demandée par le patient, réponse du médecin traitant ; PDF du dossier. */
class RectificationDossierTest extends TestCase
{
    use ParcoursDossier, ParcoursRendezVous, PersonnelDeTest, RefreshDatabase;

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

    private function rectification(string $texte = 'Je ne suis pas diabétique, merci de corriger.'): DemandeRectification
    {
        return app(DossierService::class)->demanderRectification($this->patientDossier, $texte);
    }

    private function urlReponse(DemandeRectification $demande, ?User $patient = null): string
    {
        return '/personnel/medecin/patients/'.($patient ?? $this->patientDossier)->id."/dossier/rectifications/{$demande->id}/reponse";
    }

    public function test_le_patient_demande_une_rectification_et_en_voit_le_statut(): void
    {
        $this->actingAs($this->patientDossier)
            ->post('/mon-dossier/rectifications', ['demande' => 'Mon allergie à la pénicilline a disparu.'])
            ->assertSessionHasNoErrors()->assertSessionHas('succes');

        $demande = DemandeRectification::sole();
        $this->assertSame(['ouverte', $this->patientDossier->id], [$demande->statut, $demande->patient_id]);
        $this->get('/mon-dossier')->assertInertia(fn ($page) => $page
            ->where('rectifications.0.statut', 'ouverte')->where('rectifications.0.statutLibelle', 'En attente de réponse'));
    }

    public function test_double_envoi_de_la_demande_une_seule_ligne_et_texte_trop_court_refuse(): void
    {
        $this->actingAs($this->patientDossier);
        $this->post('/mon-dossier/rectifications', ['demande' => 'Le poids noté est faux.']);
        $this->post('/mon-dossier/rectifications', ['demande' => 'Le poids noté est faux.']);
        $this->post('/mon-dossier/rectifications', ['demande' => 'Faux'])->assertSessionHasErrors('demande');
        $this->post('/mon-dossier/rectifications', [])->assertSessionHasErrors('demande');

        $this->assertSame(1, DemandeRectification::count());
    }

    public function test_seul_le_patient_demande_une_rectification(): void
    {
        $this->expectExceptionMessage('Seul le patient demande la rectification de son dossier.');
        app(DossierService::class)->demanderRectification($this->medecinTraitant->user, 'Une correction quelconque.');
    }

    public function test_le_medecin_traitant_refuse_avec_un_motif_et_ne_repond_qu_une_fois(): void
    {
        $demande = $this->rectification();
        $this->commePersonnel($this->medecinTraitant->user);

        $this->post($this->urlReponse($demande), ['motif' => 'Sans décision'])->assertSessionHasErrors('decision');
        $this->post($this->urlReponse($demande), ['decision' => 'refusee'])->assertSessionHasErrors('motif');
        $this->assertSame('ouverte', $demande->fresh()->statut);

        $this->post($this->urlReponse($demande), ['decision' => 'refusee', 'motif' => 'Glycémie à jeun élevée deux fois.'])
            ->assertSessionHasNoErrors();
        $this->post($this->urlReponse($demande), ['decision' => 'acceptee', 'motif' => 'Changement d\'avis.'])
            ->assertSessionHasErrors(['rectification' => 'Cette demande a déjà reçu une réponse.']);

        $demande->refresh();
        $this->assertSame(['refusee', 'Glycémie à jeun élevée deux fois.', $this->medecinTraitant->user_id], [$demande->statut, $demande->reponse, $demande->traitee_par]);
        $this->assertNotNull($demande->traitee_le);
        $this->actingAs($this->patientDossier)->get('/mon-dossier')->assertInertia(fn ($page) => $page
            ->where('rectifications.0.statutLibelle', 'Refusée')->where('rectifications.0.reponse', 'Glycémie à jeun élevée deux fois.'));
    }

    public function test_accepter_renvoie_a_la_note_signee_corrigee_par_avenant(): void
    {
        $termine = $this->rendezVousExistant($this->medecinTraitant, $this->patientDossier, $this->lundi('07:00')->subWeek(), statut: StatutRendezVous::Termine);
        $signee = $this->noteExistante($termine);
        $brouillon = $this->noteExistante($this->rdv, false);
        $autrePatient = $this->patient();
        $noteAutre = $this->noteExistante($this->rendezVousExistant($this->medecinTraitant, $autrePatient, $this->lundi('07:00')->subDays(2), statut: StatutRendezVous::Termine));
        $demande = $this->rectification();
        $this->commePersonnel($this->medecinTraitant->user);

        foreach ([$brouillon->id, $noteAutre->id] as $refusee) {
            $this->post($this->urlReponse($demande), ['decision' => 'acceptee', 'motif' => 'Corrigé.', 'note_id' => $refusee])
                ->assertSessionHasErrors(['rectification' => 'Cette note n\'est pas une note signée de ce patient.']);
        }

        $this->get('/personnel/medecin/patients/'.$this->patientDossier->id.'/dossier')
            ->assertInertia(fn ($page) => $page->has('notes', 1)->where('notes.0.id', $signee->id));
        $this->post($this->urlReponse($demande), ['decision' => 'acceptee', 'motif' => 'Corrigé par avenant.', 'note_id' => $signee->id])
            ->assertSessionHasNoErrors();

        $this->assertSame(['acceptee', $signee->id], [$demande->fresh()->statut, $demande->fresh()->note_id]);
        $this->actingAs($this->patientDossier)->get('/mon-dossier')->assertInertia(fn ($page) => $page
            ->where('rectifications.0.statutLibelle', 'Acceptée')->where('rectifications.0.noteId', $signee->id));
    }

    public function test_un_refus_ne_garde_pas_de_note_liee(): void
    {
        $termine = $this->rendezVousExistant($this->medecinTraitant, $this->patientDossier, $this->lundi('07:00')->subWeek(), statut: StatutRendezVous::Termine);
        $demande = $this->rectification();

        $this->commePersonnel($this->medecinTraitant->user)
            ->post($this->urlReponse($demande), ['decision' => 'refusee', 'motif' => 'La note est exacte.', 'note_id' => $this->noteExistante($termine)->id])
            ->assertSessionHasNoErrors();
        $this->assertNull($demande->fresh()->note_id);
    }

    public function test_un_tiers_ne_repond_pas(): void
    {
        $demande = $this->rectification();

        $sansRelation = $this->medecinActive();
        $this->rendezVousExistant($sansRelation, $this->patientDossier, $this->lundi('11:00'), statut: StatutRendezVous::Expire);
        $this->commePersonnel($sansRelation->user)->post($this->urlReponse($demande), ['decision' => 'acceptee', 'motif' => 'Oui.'])->assertNotFound();

        foreach (['caisse', 'admin_fonctionnel'] as $role) {
            $this->commePersonnel($this->personnelActive($role))->post($this->urlReponse($demande), ['decision' => 'acceptee', 'motif' => 'Oui.'])->assertForbidden();
        }
        $this->actingAs($this->patientDossier)->post($this->urlReponse($demande), ['decision' => 'acceptee', 'motif' => 'Oui.'])->assertForbidden();

        // La demande d'un patient n'est pas traitée par l'adresse d'un autre patient suivi.
        $autre = $this->patient();
        $this->rendezVousExistant($this->medecinTraitant, $autre, $this->lundi('10:00'));
        $this->commePersonnel($this->medecinTraitant->user)->post($this->urlReponse($demande, $autre), ['decision' => 'acceptee', 'motif' => 'Oui.'])->assertNotFound();

        $this->assertSame('ouverte', $demande->fresh()->statut);
    }

    public function test_le_patient_telecharge_son_dossier_en_pdf_sans_cache(): void
    {
        $service = app(DossierService::class);
        $service->ajouterAntecedent($this->patientDossier, $this->patientDossier, 'allergie', 'Pénicilline');
        $service->ajouterConstante($this->patientDossier, $this->patientDossier, 'tension', '130', '85');
        $termine = $this->rendezVousExistant($this->medecinTraitant, $this->patientDossier, $this->lundi('07:00')->subWeek(), statut: StatutRendezVous::Termine);
        $this->avenantExistant($this->noteExistante($termine));
        $this->ordonnanceExistante($termine);

        $reponse = $this->actingAs($this->patientDossier)->get('/mon-dossier/pdf')->assertOk()->assertDownload('dossier-medical.pdf');

        $this->assertSame('application/pdf', $reponse->headers->get('Content-Type'));
        $this->assertStringContainsString('no-store', $reponse->headers->get('Cache-Control'));
        $this->assertStringStartsWith('%PDF', $reponse->getContent());
    }

    public function test_le_gabarit_pdf_contient_le_dossier_sans_ressource_externe(): void
    {
        $service = app(DossierService::class);
        $service->ajouterAntecedent($this->patientDossier, $this->patientDossier, 'allergie', 'Pénicilline');
        $lecture = app(LectureDossier::class);

        $html = view('pdf.dossier', [
            'patient' => $this->patientDossier,
            'etat' => $lecture->etat($this->patientDossier),
            'historique' => $lecture->historique($this->patientDossier, true),
            'emis' => '07/11/2026 à 10:00',
        ])->render();

        $this->assertStringContainsString('Pénicilline', $html);
        $this->assertStringContainsString('Groupe sanguin : <strong>inconnu</strong>', $html);
        $this->assertDoesNotMatchRegularExpression('#(src|href)\s*=\s*["\']?(https?:)?//#i', $html, 'Aucune ressource externe.');
    }

    public function test_le_pdf_est_reserve_au_patient(): void
    {
        $this->commePersonnel($this->medecinTraitant->user)->get('/mon-dossier/pdf')->assertForbidden();
        $this->commePersonnel($this->personnelActive('admin_fonctionnel'))->get('/mon-dossier/pdf')->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->get('/mon-dossier/pdf')->assertRedirect('/connexion');
    }
}
