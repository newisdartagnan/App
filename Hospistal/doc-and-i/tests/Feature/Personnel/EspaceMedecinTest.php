<?php

namespace Tests\Feature\Personnel;

use App\Enums\StatutRendezVous;
use App\Models\AbsenceMedecin;
use App\Models\DemandeConsultation;
use App\Models\Medecin;
use App\Models\PlageDisponibilite;
use App\Models\RendezVous;
use App\Models\TypeConsultation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\ReferentielMedicalSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\PersonnelDeTest;
use Tests\TestCase;

/**
 * RDV-04 côté médecin : disponibilités hebdomadaires, absences, agenda.
 * Un médecin ne voit et ne modifie QUE ses propres données.
 */
class EspaceMedecinTest extends TestCase
{
    use PersonnelDeTest, RefreshDatabase;

    private Medecin $medecin;

    private Medecin $autre;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesSeeder::class, ReferentielMedicalSeeder::class]);
        // Lundi 9 novembre 2026, 8 h à Kinshasa.
        $this->travelTo(CarbonImmutable::parse('2026-11-09 08:00:00', 'Africa/Kinshasa'));

        $this->medecin = $this->medecinActive();
        $this->autre = $this->medecinActive();
    }

    private function moi(): static
    {
        return $this->commePersonnel($this->medecin->user);
    }

    private function plage(Medecin $medecin, int $jour, string $debut, string $fin): PlageDisponibilite
    {
        return $medecin->plages()->create(['jour_semaine' => $jour, 'heure_debut' => $debut, 'heure_fin' => $fin]);
    }

    private function absence(Medecin $medecin, string $debut, string $fin, ?string $motif = null): AbsenceMedecin
    {
        return $medecin->absences()->create(['debut' => $debut, 'fin' => $fin, 'motif' => $motif]);
    }

    private function rendezVous(Medecin $medecin, User $patient, string $debut, StatutRendezVous $statut = StatutRendezVous::Confirme, string $motif = 'Fièvre depuis trois jours'): RendezVous
    {
        $type = TypeConsultation::where('specialite_id', $medecin->specialite_id)->firstOrFail();
        $demande = new DemandeConsultation(['motif' => $motif, 'tri_reponses' => [], 'tri_alerte' => false]);
        $demande->patient_id = $patient->id;
        $demande->save();

        $rdv = new RendezVous;
        $rdv->forceFill([
            'demande_id' => $demande->id,
            'patient_id' => $patient->id,
            'medecin_id' => $medecin->id,
            'type_consultation_id' => $type->id,
            'debut' => $debut,
            'fin' => CarbonImmutable::parse($debut)->addMinutes(20),
            'statut' => $statut,
            'devise' => 'USD',
        ])->save();

        return $rdv;
    }

    private function ajouterPlage(int $jour, string $debut, string $fin): TestResponse
    {
        return $this->moi()->post(route('personnel.medecin.plages.ajouter'), ['jour_semaine' => $jour, 'heure_debut' => $debut, 'heure_fin' => $fin]);
    }

    private function ajouterAbsence(string $debut, string $fin, ?string $motif = null): TestResponse
    {
        return $this->moi()->post(route('personnel.medecin.absences.ajouter'), ['debut' => $debut, 'fin' => $fin, 'motif' => $motif]);
    }

    /** @return array<string, array{string, string, array<string, string>}> */
    public static function routesDuMedecin(): array
    {
        return [
            'accueil' => ['get', 'personnel.medecin.accueil', []],
            'disponibilités' => ['get', 'personnel.medecin.plages', []],
            'ajout de plage' => ['post', 'personnel.medecin.plages.ajouter', []],
            'suppression de plage' => ['delete', 'personnel.medecin.plages.supprimer', ['plage' => '00000000-0000-0000-0000-000000000000']],
            'absences' => ['get', 'personnel.medecin.absences', []],
            'ajout d\'absence' => ['post', 'personnel.medecin.absences.ajouter', []],
            'suppression d\'absence' => ['delete', 'personnel.medecin.absences.supprimer', ['absence' => '00000000-0000-0000-0000-000000000000']],
            'agenda' => ['get', 'personnel.medecin.agenda', []],
        ];
    }

    // --- Accès -------------------------------------------------------------

    #[DataProvider('routesDuMedecin')]
    public function test_un_visiteur_est_renvoye_vers_la_connexion_du_personnel(string $methode, string $route, array $parametres): void
    {
        $this->{$methode}(route($route, $parametres))->assertRedirect(route('personnel.connexion'));
    }

    #[DataProvider('routesDuMedecin')]
    public function test_un_patient_est_refuse(string $methode, string $route, array $parametres): void
    {
        $this->actingAs(User::factory()->create())->{$methode}(route($route, $parametres))->assertForbidden();
    }

    #[DataProvider('routesDuMedecin')]
    public function test_un_autre_role_du_personnel_est_refuse(string $methode, string $route, array $parametres): void
    {
        $this->commePersonnel($this->personnelActive('caisse'))->{$methode}(route($route, $parametres))->assertForbidden();
    }

    #[DataProvider('routesDuMedecin')]
    public function test_un_medecin_sans_second_facteur_dans_la_session_est_refuse(string $methode, string $route, array $parametres): void
    {
        $this->actingAs($this->medecin->user)->{$methode}(route($route, $parametres))->assertRedirect(route('personnel.connexion'));
        $this->assertGuest();
    }

    public function test_un_compte_au_role_medecin_sans_profil_medecin_est_refuse(): void
    {
        $sansProfil = $this->personnelActive('medecin');

        $this->commePersonnel($sansProfil)->get(route('personnel.medecin.accueil'))->assertForbidden();
        $this->commePersonnel($sansProfil)->get(route('personnel.medecin.agenda'))->assertForbidden();
    }

    public function test_l_accueil_du_personnel_mene_le_medecin_a_son_espace(): void
    {
        $this->moi()->get(route('personnel.accueil'))->assertRedirect(route('personnel.medecin.accueil'));
    }

    public function test_l_accueil_du_medecin_resume_son_compte(): void
    {
        $this->plage($this->medecin, 1, '09:00', '12:00');
        $this->rendezVous($this->medecin, User::factory()->create(), '2026-11-10 09:00:00');
        $this->rendezVous($this->autre, User::factory()->create(), '2026-11-10 09:00:00');

        $this->moi()->get(route('personnel.medecin.accueil'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Personnel/Medecin/Accueil')
                ->where('medecin.specialite', 'Médecine générale')
                ->where('medecin.verifie', true)
                ->where('resume.rendezVousAVenir', 1)
                ->where('resume.plages', 1));
    }

    public function test_un_medecin_non_verifie_est_prevenu_qu_il_n_est_pas_propose(): void
    {
        $this->medecin->forceFill(['ordre_verifie_le' => null])->save();

        $this->moi()->get(route('personnel.medecin.accueil'))
            ->assertInertia(fn ($page) => $page->where('medecin.verifie', false));
    }

    // --- Plages hebdomadaires ----------------------------------------------

    public function test_le_medecin_ajoute_une_plage_en_heure_de_kinshasa(): void
    {
        $this->ajouterPlage(2, '09:00', '12:30')->assertRedirect(route('personnel.medecin.plages'));

        $plage = $this->medecin->plages()->sole();
        $this->assertSame(2, $plage->jour_semaine);
        $this->assertSame('09:00:00', $plage->heure_debut);
        $this->assertSame('12:30:00', $plage->heure_fin);
    }

    public function test_une_plage_qui_en_chevauche_une_autre_le_meme_jour_est_refusee(): void
    {
        $this->plage($this->medecin, 1, '09:00', '12:00');

        foreach ([['08:00', '09:30'], ['11:00', '13:00'], ['10:00', '11:00'], ['08:00', '13:00'], ['09:00', '12:00']] as [$debut, $fin]) {
            $this->ajouterPlage(1, $debut, $fin)->assertSessionHasErrors('heure_debut');
        }
        $this->assertSame(1, $this->medecin->plages()->count());
    }

    public function test_des_plages_contigues_ou_un_autre_jour_sont_acceptees(): void
    {
        $this->plage($this->medecin, 1, '09:00', '12:00');

        $this->ajouterPlage(1, '12:00', '14:00')->assertSessionHasNoErrors();
        $this->ajouterPlage(1, '07:00', '09:00')->assertSessionHasNoErrors();
        $this->ajouterPlage(2, '09:00', '12:00')->assertSessionHasNoErrors();
        $this->assertSame(4, $this->medecin->plages()->count());
    }

    public function test_les_plages_d_un_autre_medecin_ne_genent_pas(): void
    {
        $this->plage($this->autre, 1, '09:00', '12:00');

        $this->ajouterPlage(1, '09:00', '12:00')->assertSessionHasNoErrors();
    }

    public function test_un_double_envoi_ne_cree_qu_une_plage(): void
    {
        $this->ajouterPlage(3, '14:00', '17:00');
        $this->ajouterPlage(3, '14:00', '17:00')->assertSessionHasErrors('heure_debut');

        $this->assertSame(1, $this->medecin->plages()->count());
    }

    public function test_une_plage_mal_formee_est_refusee(): void
    {
        $this->ajouterPlage(1, '12:00', '09:00')->assertSessionHasErrors('heure_fin');
        $this->ajouterPlage(1, '09:00', '09:00')->assertSessionHasErrors('heure_fin');
        $this->ajouterPlage(0, '09:00', '12:00')->assertSessionHasErrors('jour_semaine');
        $this->ajouterPlage(8, '09:00', '12:00')->assertSessionHasErrors('jour_semaine');
        $this->ajouterPlage(1, '9h', '12:00')->assertSessionHasErrors('heure_debut');
        $this->ajouterPlage(1, '09:00', '25:00')->assertSessionHasErrors('heure_fin');

        $this->assertSame(0, PlageDisponibilite::count());
    }

    public function test_le_medecin_ne_voit_que_ses_plages(): void
    {
        $mienne = $this->plage($this->medecin, 1, '09:00', '12:00');
        $this->plage($this->autre, 2, '14:00', '17:00');

        $this->moi()->get(route('personnel.medecin.plages'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Personnel/Medecin/Plages')
                ->has('plages', 1)
                ->where('plages.0.id', $mienne->id)
                ->where('plages.0.jour', 'Lundi')
                ->where('plages.0.heureDebut', '09:00')
                ->where('plages.0.heureFin', '12:00'));
    }

    public function test_le_medecin_supprime_sa_plage(): void
    {
        $plage = $this->plage($this->medecin, 1, '09:00', '12:00');

        $this->moi()->delete(route('personnel.medecin.plages.supprimer', $plage->id))->assertRedirect(route('personnel.medecin.plages'));

        $this->assertModelMissing($plage);
    }

    public function test_un_medecin_ne_supprime_pas_la_plage_d_un_autre(): void
    {
        $plage = $this->plage($this->autre, 1, '09:00', '12:00');

        $this->moi()->delete(route('personnel.medecin.plages.supprimer', $plage->id))->assertNotFound();

        $this->assertModelExists($plage);
    }

    // --- Absences ------------------------------------------------------------

    public function test_le_medecin_declare_une_absence(): void
    {
        $this->ajouterAbsence('2026-11-20T08:00', '2026-11-22T18:00', 'Congrès')->assertRedirect(route('personnel.medecin.absences'));

        $absence = $this->medecin->absences()->sole();
        $this->assertSame('2026-11-20 08:00:00', $absence->debut->timezone('Africa/Kinshasa')->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-22 18:00:00', $absence->fin->timezone('Africa/Kinshasa')->format('Y-m-d H:i:s'));
        $this->assertSame('Congrès', $absence->motif);
    }

    public function test_une_absence_mal_formee_ou_deja_passee_est_refusee(): void
    {
        $this->ajouterAbsence('2026-11-22T08:00', '2026-11-20T08:00')->assertSessionHasErrors('fin');
        $this->ajouterAbsence('2026-11-20T08:00', '2026-11-20T08:00')->assertSessionHasErrors('fin');
        $this->ajouterAbsence('2026-11-01T08:00', '2026-11-02T08:00')->assertSessionHasErrors('fin');
        $this->ajouterAbsence('demain', '2026-11-20T08:00')->assertSessionHasErrors('debut');
        $this->ajouterAbsence('2026-11-20T08:00', '2026-11-21T08:00', str_repeat('x', 300))->assertSessionHasErrors('motif');

        $this->assertSame(0, AbsenceMedecin::count());
    }

    public function test_une_absence_qui_chevauche_une_autre_est_refusee_double_envoi_compris(): void
    {
        $this->ajouterAbsence('2026-11-20T08:00', '2026-11-22T18:00');
        $this->ajouterAbsence('2026-11-20T08:00', '2026-11-22T18:00')->assertSessionHasErrors('debut');
        $this->ajouterAbsence('2026-11-22T12:00', '2026-11-23T12:00')->assertSessionHasErrors('debut');

        $this->ajouterAbsence('2026-11-22T18:00', '2026-11-23T12:00')->assertSessionHasNoErrors();
        $this->assertSame(2, $this->medecin->absences()->count());
    }

    public function test_le_medecin_est_prevenu_des_rendez_vous_pris_sur_son_absence(): void
    {
        $this->rendezVous($this->medecin, User::factory()->create(), '2026-11-20 10:00:00');
        $this->rendezVous($this->medecin, User::factory()->create(), '2026-11-21 10:00:00', StatutRendezVous::AnnuleParPatient);

        $this->ajouterAbsence('2026-11-20T08:00', '2026-11-22T18:00')
            ->assertSessionHas('succes', fn ($message) => str_contains($message, '1 rendez-vous'));
    }

    public function test_le_medecin_ne_voit_que_ses_absences_en_cours_ou_a_venir(): void
    {
        $future = $this->absence($this->medecin, '2026-11-20 08:00', '2026-11-22 18:00', 'Congrès');
        $enCours = $this->absence($this->medecin, '2026-11-08 08:00', '2026-11-10 18:00');
        $this->absence($this->medecin, '2026-10-01 08:00', '2026-10-02 18:00');
        $this->absence($this->autre, '2026-11-25 08:00', '2026-11-26 18:00');

        $this->moi()->get(route('personnel.medecin.absences'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Personnel/Medecin/Absences')
                ->has('absences', 2)
                ->where('absences.0.id', $enCours->id)
                ->where('absences.0.supprimable', false)
                ->where('absences.1.id', $future->id)
                ->where('absences.1.supprimable', true)
                ->where('absences.1.motif', 'Congrès'));
    }

    public function test_le_medecin_supprime_une_absence_a_venir(): void
    {
        $absence = $this->absence($this->medecin, '2026-11-20 08:00', '2026-11-22 18:00');

        $this->moi()->delete(route('personnel.medecin.absences.supprimer', $absence->id))->assertRedirect(route('personnel.medecin.absences'));

        $this->assertModelMissing($absence);
    }

    public function test_une_absence_commencee_ou_passee_n_est_pas_supprimee(): void
    {
        $enCours = $this->absence($this->medecin, '2026-11-08 08:00', '2026-11-10 18:00');
        $passee = $this->absence($this->medecin, '2026-10-01 08:00', '2026-10-02 18:00');

        $this->moi()->delete(route('personnel.medecin.absences.supprimer', $enCours->id))->assertSessionHasErrors('absence');
        $this->moi()->delete(route('personnel.medecin.absences.supprimer', $passee->id))->assertSessionHasErrors('absence');

        $this->assertModelExists($enCours);
        $this->assertModelExists($passee);
    }

    public function test_un_medecin_ne_supprime_pas_l_absence_d_un_autre(): void
    {
        $absence = $this->absence($this->autre, '2026-11-20 08:00', '2026-11-22 18:00');

        $this->moi()->delete(route('personnel.medecin.absences.supprimer', $absence->id))->assertNotFound();

        $this->assertModelExists($absence);
    }

    // --- Agenda ----------------------------------------------------------------

    public function test_l_agenda_liste_les_rendez_vous_a_venir_du_medecin(): void
    {
        $patient = User::factory()->create(['prenom' => 'Grâce', 'nom' => 'KABILA']);
        $tard = $this->rendezVous($this->medecin, $patient, '2026-11-12 15:00:00', StatutRendezVous::EnAttentePaiement, 'Toux');
        $tot = $this->rendezVous($this->medecin, $patient, '2026-11-10 09:30:00');
        $this->rendezVous($this->medecin, User::factory()->create(), '2026-11-02 09:00:00', StatutRendezVous::Termine);
        $this->rendezVous($this->autre, User::factory()->create(), '2026-11-10 09:30:00');

        $this->moi()->get(route('personnel.medecin.agenda'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Personnel/Medecin/Agenda')
                ->has('rendezVous', 2)
                ->where('rendezVous.0.id', $tot->id)
                ->where('rendezVous.0.date', 'mardi 10 novembre 2026')
                ->where('rendezVous.0.heure', '09:30')
                ->where('rendezVous.0.patient', 'Grâce KABILA')
                ->where('rendezVous.0.motif', 'Fièvre depuis trois jours')
                ->where('rendezVous.0.statut', 'Confirmé')
                ->where('rendezVous.1.id', $tard->id)
                ->where('rendezVous.1.heure', '15:00')
                ->where('rendezVous.1.statut', 'En attente de paiement'));
    }

    public function test_le_patient_et_le_motif_ne_sont_montres_que_pour_un_rendez_vous_confirme(): void
    {
        // Un créneau bloqué le temps du paiement, ou annulé, n'est pas (ou plus)
        // une relation de soin : le médecin n'en voit que l'heure et le statut.
        $patient = User::factory()->create(['prenom' => 'Grâce', 'nom' => 'KABILA']);
        foreach ([StatutRendezVous::EnAttentePaiement, StatutRendezVous::AnnuleParPatient, StatutRendezVous::AnnuleParMedecin] as $i => $statut) {
            $this->rendezVous($this->medecin, $patient, '2026-11-1'.($i + 1).' 10:00:00', $statut, 'Douleurs abdominales');
        }

        $reponse = $this->moi()->get(route('personnel.medecin.agenda'))
            ->assertInertia(fn ($page) => $page->has('rendezVous', 3)
                ->where('rendezVous.0.patient', null)->where('rendezVous.0.motif', null)
                ->where('rendezVous.1.patient', null)->where('rendezVous.1.motif', null)
                ->where('rendezVous.2.patient', null)->where('rendezVous.2.motif', null));

        $page = json_encode($reponse->viewData('page'));
        $this->assertStringNotContainsString('KABILA', $page);
        $this->assertStringNotContainsString('Douleurs abdominales', $page);
    }

    public function test_l_agenda_donne_les_libelles_du_point_de_vue_du_medecin(): void
    {
        $this->rendezVous($this->medecin, User::factory()->create(), '2026-11-10 09:30:00', StatutRendezVous::AnnuleParPatient);
        $this->rendezVous($this->medecin, User::factory()->create(), '2026-11-10 10:30:00', StatutRendezVous::Expire);

        $this->moi()->get(route('personnel.medecin.agenda'))
            ->assertInertia(fn ($page) => $page->has('rendezVous', 1)
                ->where('rendezVous.0.statut', 'Annulé par le patient')
                ->where('rendezVous.0.heure', '09:30'));
    }

    public function test_l_agenda_ne_montre_au_medecin_que_le_strict_necessaire_du_patient(): void
    {
        $this->rendezVous($this->medecin, User::factory()->create(), '2026-11-10 09:30:00');

        $this->moi()->get(route('personnel.medecin.agenda'))
            ->assertInertia(fn ($page) => $page->has('rendezVous.0', fn ($rdv) => $rdv
                ->hasAll(['id', 'date', 'heure', 'heureFin', 'patient', 'motif', 'statut', 'statutCode'])
                ->missing('telephone')
                ->etc()));
    }
}
