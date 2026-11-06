<?php

namespace Tests\Feature\Admin;

use App\Enums\MotifRemboursement;
use App\Enums\StatutRendezVous;
use App\Models\EntreeJournalAdministration;
use App\Models\JetonActivation;
use App\Models\Medecin;
use App\Models\Remboursement;
use App\Models\User;
use App\Services\Personnel\ActivationPersonnelService;
use App\Services\Personnel\ComptePersonnelService;
use App\Sms\EnvoyeurSms;
use Database\Seeders\ReferentielMedicalSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Tests\Support\EnvoyeurSmsFactice;
use Tests\Support\ParcoursPaiement;
use Tests\Support\ParcoursRendezVous;
use Tests\Support\PersonnelDeTest;
use Tests\TestCase;

/** CPT-05 : médecins vus par l'administrateur ; désactivation et réactivation des comptes. */
class MedecinsEtDesactivationAdminTest extends TestCase
{
    use ParcoursPaiement, ParcoursRendezVous, PersonnelDeTest, RefreshDatabase;

    private User $admin;

    private Medecin $medecin;

    private EnvoyeurSmsFactice $sms;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesSeeder::class, ReferentielMedicalSeeder::class]);
        $this->voyagerAuLundi();
        $this->admin = $this->personnelActive('admin_fonctionnel');
        $this->medecin = $this->medecinActive();
        $this->sms = new EnvoyeurSmsFactice;
        $this->app->instance(EnvoyeurSms::class, $this->sms);
    }

    private function fiche(User $compte): TestResponse
    {
        return $this->commePersonnel($this->admin)->get(route('personnel.admin.personnel.fiche', $compte->id));
    }

    private function desactiver(User $compte, array $champs = []): TestResponse
    {
        return $this->commePersonnel($this->admin)->post(route('personnel.admin.personnel.desactiver', $compte->id), $champs + ['motif' => 'Fin de contrat']);
    }

    // --- Ordre, profil, planning ----------------------------------------------------------

    public function test_la_verification_de_l_ordre_rend_le_medecin_reservable_et_est_journalisee(): void
    {
        $this->medecin->forceFill(['ordre_verifie_le' => null])->save();
        $this->assertFalse($this->medecin->fresh()->estReservable());

        $this->commePersonnel($this->admin)->post(route('personnel.admin.personnel.ordre', $this->medecin->user_id))->assertSessionHasNoErrors();

        $this->assertTrue($this->medecin->fresh()->estReservable());
        $this->assertTrue(Medecin::reservables()->whereKey($this->medecin->id)->exists());
        $entree = EntreeJournalAdministration::where('action', 'medecin.ordre_verifie')->sole();
        $this->assertSame([$this->admin->id, $this->medecin->user_id, $this->medecin->numero_ordre],
            [$entree->auteur_id, $entree->cible_id, $entree->details['numero_ordre']]);
        $this->assertNotNull($entree->cree_le);

        // Une seconde vérification ne change rien.
        $this->commePersonnel($this->admin)->post(route('personnel.admin.personnel.ordre', $this->medecin->user_id))->assertSessionHasErrors('ordre');
        $this->assertSame(1, EntreeJournalAdministration::where('action', 'medecin.ordre_verifie')->count());
    }

    public function test_l_administrateur_modifie_specialite_et_presentation(): void
    {
        $this->commePersonnel($this->admin)->put(route('personnel.admin.personnel.medecin', $this->medecin->user_id), [
            'specialite' => 'pediatrie', 'presentation' => 'Pédiatre, 15 ans d\'expérience.',
        ])->assertSessionHasNoErrors();

        $m = $this->medecin->fresh(['specialite']);
        $this->assertSame(['pediatrie', 'Pédiatre, 15 ans d\'expérience.'], [$m->specialite->slug, $m->presentation]);
        $this->assertEquals(['specialite_avant' => 'medecine-generale', 'specialite' => 'pediatrie', 'presentation_modifiee' => true],
            EntreeJournalAdministration::where('action', 'medecin.profil_modifie')->sole()->details);

        $this->commePersonnel($this->admin)->put(route('personnel.admin.personnel.medecin', $this->medecin->user_id), ['specialite' => 'astrologie'])
            ->assertSessionHasErrors('specialite');
    }

    public function test_la_fiche_montre_le_planning_et_les_rendez_vous_par_statut_sans_motif_ni_patient(): void
    {
        $this->medecin->plages()->create(['jour_semaine' => 2, 'heure_debut' => '09:00', 'heure_fin' => '12:00']);
        $this->medecin->absences()->create(['debut' => '2026-12-20 08:00', 'fin' => '2026-12-22 18:00', 'motif' => 'Congrès']);
        $patient = User::factory()->create(['nom' => 'KASONGO']);
        $this->rendezVousExistant($this->medecin, $patient, $this->lundi('10:00'));
        $this->rendezVousExistant($this->medecin, $this->patient(), $this->lundi('11:00'));
        $this->rendezVousExistant($this->medecin, $this->patient(), $this->lundi('12:00'), statut: StatutRendezVous::EnAttentePaiement);

        $reponse = $this->fiche($this->medecin->user)->assertOk()
            ->assertInertia(fn ($page) => $page->component('Personnel/Admin/Personnel/Fiche')
                ->where('medecin.plages.0.jour', 'Mardi')
                ->has('medecin.absences', 1)
                ->where('medecin.confirmesAVenir', 2)
                ->where('medecin.rendezVous', fn ($lignes) => collect($lignes)->pluck('nombre', 'statut')->all() === ['en_attente_paiement' => 1, 'confirme' => 2]));

        $page = json_encode($reponse->viewData('page'));
        $this->assertStringNotContainsString('Toux', $page, 'Aucun motif de consultation.');
        $this->assertStringNotContainsString('KASONGO', $page, 'Aucun nom de patient.');
        $this->assertStringNotContainsString('Congrès', $page, 'Le motif d\'absence reste au médecin.');
        $this->assertSame('no-store, private', $reponse->headers->get('Cache-Control'));
    }

    // --- Désactivation -----------------------------------------------------------------------

    public function test_un_compte_desactive_perd_sa_session_et_ses_liens_et_c_est_journalise(): void
    {
        $agent = $this->personnelActive('caisse');
        $enAttente = $this->personnelActive('support');
        app(ActivationPersonnelService::class); // sans effet : le support est déjà activé

        $this->desactiver($agent)->assertSessionHasNoErrors();

        $agent->refresh();
        $this->assertNotNull($agent->desactive_le);
        $this->assertSame('Fin de contrat', $agent->motif_desactivation);
        $this->assertEquals(['motif' => 'Fin de contrat', 'rendez_vous_annules' => 0],
            EntreeJournalAdministration::where('action', 'personnel.compte_desactive')->sole()->details);

        // Sa session en cours se ferme à la requête suivante.
        $this->commePersonnel($agent)->get(route('personnel.caisse.accueil'))->assertRedirect(route('personnel.connexion'));
        $this->assertGuest();
        $this->assertNull($enAttente->fresh()->desactive_le);
    }

    public function test_un_lien_d_activation_ne_sert_plus_apres_desactivation(): void
    {
        $nouveau = User::factory()->sansRole()->create(['email' => 'support@exemple.cd']);
        $nouveau->assignRole('support');
        $lien = app(ActivationPersonnelService::class)->emettreLien($nouveau);

        $this->desactiver($nouveau)->assertSessionHasNoErrors();

        $this->assertSame(0, JetonActivation::valables()->where('user_id', $nouveau->id)->count());
        $this->post(route('personnel.deconnexion'));
        $this->get($lien)->assertStatus(410);
    }

    public function test_le_motif_est_obligatoire(): void
    {
        $agent = $this->personnelActive('caisse');

        $this->desactiver($agent, ['motif' => '  '])->assertSessionHasErrors('motif');
        $this->assertNull($agent->fresh()->desactive_le);

        // Le service le refuse aussi, hors de l'écran (commande, autre appelant).
        $this->expectException(ValidationException::class);
        app(ComptePersonnelService::class)->desactiver($agent, $this->admin, '   ');
    }

    public function test_un_administrateur_ne_se_desactive_pas_lui_meme(): void
    {
        $this->desactiver($this->admin)->assertSessionHasErrors('motif');

        $this->assertNull($this->admin->fresh()->desactive_le);
        $this->assertSame(0, EntreeJournalAdministration::count());
    }

    public function test_un_medecin_avec_des_rendez_vous_confirmes_a_venir_est_refuse_avec_leur_nombre(): void
    {
        $rdv = $this->rendezVousExistant($this->medecin, $this->patient(), $this->lundi('10:00'));
        $this->rendezVousExistant($this->medecin, $this->patient(), $this->lundi('11:00'));

        $this->desactiver($this->medecin->user)->assertSessionHasErrors('motif');
        $this->assertStringContainsString('2 rendez-vous confirmé', session('errors')->first('motif'));

        $this->assertNull($this->medecin->user->fresh()->desactive_le);
        $this->assertSame(StatutRendezVous::Confirme, $rdv->fresh()->statut);
    }

    public function test_un_medecin_dont_un_patient_paie_en_ce_moment_est_refuse(): void
    {
        $this->rendezVousExistant($this->medecin, $this->patient(), $this->lundi('10:00'), statut: StatutRendezVous::EnAttentePaiement);

        $this->desactiver($this->medecin->user, ['annuler_rendez_vous' => true])->assertSessionHasErrors('motif');
        $this->assertNull($this->medecin->user->fresh()->desactive_le);
    }

    public function test_l_administrateur_peut_annuler_en_masse_avec_remboursement_integral(): void
    {
        $paye = $this->rendezVousExistant($this->medecin, $this->patient(), $this->lundi('10:00'));
        $paiement = $this->paiementExistant($paye);
        $autre = $this->rendezVousExistant($this->medecin, $this->patient(), $this->lundi('11:00'));
        $passe = $this->rendezVousExistant($this->medecin, $this->patient(), $this->lundi('06:00'), statut: StatutRendezVous::Termine);

        $this->desactiver($this->medecin->user, ['annuler_rendez_vous' => true])->assertSessionHasNoErrors();

        foreach ([$paye, $autre] as $rdv) {
            $this->assertSame([StatutRendezVous::AnnuleParMedecin, 100], [$rdv->fresh()->statut, $rdv->fresh()->taux_remboursement]);
        }
        $this->assertSame(StatutRendezVous::Termine, $passe->fresh()->statut);
        $remboursement = Remboursement::sole();
        $this->assertSame([$paiement->id, MotifRemboursement::AnnulationMedecin, '10.00'], [$remboursement->paiement_id, $remboursement->motif, (string) $remboursement->montant]);
        $this->assertCount(2, $this->sms->envoyes);
        $this->assertStringNotContainsString('Fin de contrat', $this->sms->envoyes[0]['message'], 'Ni motif ni donnée de santé dans le SMS.');
        $this->assertSame(2, EntreeJournalAdministration::where('action', 'rendez_vous.annule_par_administration')->count());
        $this->assertSame(2, EntreeJournalAdministration::where('action', 'personnel.compte_desactive')->sole()->details['rendez_vous_annules']);
        $this->assertFalse(Medecin::reservables()->whereKey($this->medecin->id)->exists(), 'Plus proposé aux patients.');
    }

    public function test_un_compte_deja_desactive_ne_se_desactive_pas_deux_fois(): void
    {
        $agent = $this->personnelActive('caisse');
        $this->desactiver($agent);

        $this->desactiver($agent, ['motif' => 'Encore'])->assertSessionHasErrors('motif');
        $this->assertSame(1, EntreeJournalAdministration::where('action', 'personnel.compte_desactive')->count());
    }

    // --- Réactivation ---------------------------------------------------------------------------

    public function test_la_reactivation_d_un_compte_jamais_active_donne_un_nouveau_lien(): void
    {
        $nouveau = User::factory()->sansRole()->create(['email' => 'support@exemple.cd']);
        $nouveau->assignRole('support');
        $this->desactiver($nouveau);

        $this->commePersonnel($this->admin)->post(route('personnel.admin.personnel.reactiver', $nouveau->id), ['motif_reactivation' => ''])
            ->assertSessionHasErrors('motif_reactivation');
        $this->commePersonnel($this->admin)->post(route('personnel.admin.personnel.reactiver', $nouveau->id), ['motif_reactivation' => 'Retour'])
            ->assertSessionHasNoErrors();

        $this->assertNull($nouveau->fresh()->desactive_le);
        $lien = $this->fiche($nouveau)->viewData('page')['props']['lienActivation'];
        $this->assertNotNull($lien);
        $this->post(route('personnel.deconnexion'));
        $this->get($lien)->assertRedirect(route('personnel.activation.mot-de-passe'));
    }

    public function test_la_reactivation_d_un_compte_deja_active_ne_donne_pas_de_lien_et_rend_le_medecin_reservable(): void
    {
        $this->desactiver($this->medecin->user);

        $this->commePersonnel($this->admin)->post(route('personnel.admin.personnel.reactiver', $this->medecin->user_id), ['motif_reactivation' => 'Retour de congé'])
            ->assertSessionHasNoErrors();

        $this->assertNull($this->fiche($this->medecin->user)->viewData('page')['props']['lienActivation']);
        $this->assertTrue(Medecin::reservables()->whereKey($this->medecin->id)->exists());
        $this->assertSame(['motif' => 'Retour de congé'], EntreeJournalAdministration::where('action', 'personnel.compte_reactive')->sole()->details);
        $this->assertSame(0, JetonActivation::where('user_id', $this->medecin->user_id)->count());
    }
}
