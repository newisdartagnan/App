<?php

namespace Tests\Feature;

use App\Models\ActeClinique;
use App\Models\Establishment;
use App\Models\KitOperatoire;
use App\Models\Patient;
use App\Models\SalleOperation;
use App\Models\Service;
use App\Models\User;
use App\Models\Visit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Bloc opératoire : demande, planification, intervention, registre.
 *
 * La salle est la ressource rare : le point sensible est qu'on ne puisse pas
 * y programmer deux interventions au même moment.
 */
class BlocOperatoireTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Establishment $etab;

    protected Patient $patient;

    protected Visit $visite;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->admin = User::where('email', 'admin@dpi-rdc.local')->firstOrFail();
        $this->etab = Establishment::firstOrFail();
        $this->actingAs($this->admin);

        $this->patient = Patient::create([
            'establishment_id' => $this->etab->id,
            'dossier_number' => 'PAT-2026-009900',
            'nom' => 'KABILA', 'postnom' => 'Mwamba', 'prenom' => 'Blandine',
            'sexe' => 'F',
            'date_naissance' => now()->subYears(28)->toDateString(),
            'type_prise_en_charge' => 'prive',
        ]);

        $this->visite = Visit::create([
            'patient_id' => $this->patient->id,
            'establishment_id' => $this->etab->id,
            'user_id' => $this->admin->id,
            'type' => 'hospitalisation',
            'statut' => 'en_cours',
            'date_entree' => now(),
            'service_id' => Service::where('type', 'maternite')->value('id'),
            'motif_consultation' => 'Travail',
        ]);
    }

    protected function demande(string $libelle, string $domaine = 'maternite'): ActeClinique
    {
        return ActeClinique::create([
            'visit_id' => $this->visite->id,
            'patient_id' => $this->patient->id,
            'prescripteur_id' => $this->admin->id,
            'demandeur_id' => $this->admin->id,
            'domaine' => $domaine,
            'libelle' => $libelle,
            'prix' => 250000,
            'statut' => 'prescrit',
            'diagnostic_preop' => 'Bassin rétréci',
        ]);
    }

    protected function salle(string $code = 'SOP-1'): SalleOperation
    {
        return SalleOperation::where('code', $code)->firstOrFail();
    }

    /** @return array<string, mixed> */
    protected function creneau(SalleOperation $salle, string $heure, int $duree = 90): array
    {
        return [
            'salle_id' => $salle->id,
            'date_prevue' => $heure,
            'duree_minutes' => $duree,
            'operateur_id' => $this->admin->id,
            'type_anesthesie' => 'rachianesthesie',
            'consentement' => '1',
        ];
    }

    // ═══════════════════════════════════════════════════════════
    // Dotation du bloc
    // ═══════════════════════════════════════════════════════════

    public function test_le_bloc_ouvre_avec_ses_salles_et_ses_kits(): void
    {
        $this->assertSame(3, SalleOperation::where('est_actif', true)->count());
        $this->assertSame(6, KitOperatoire::where('est_actif', true)->count());

        $kit = KitOperatoire::where('code', 'KIT-CESAR')->firstOrFail();
        $this->assertStringContainsString('Champs stériles', $kit->libelleContenu());
        $this->assertSame(45000.0, (float) $kit->prix);
    }

    // ═══════════════════════════════════════════════════════════
    // Programme préopératoire et planification
    // ═══════════════════════════════════════════════════════════

    public function test_le_programme_preoperatoire_liste_les_demandes(): void
    {
        $this->demande('Césarienne');

        $this->get(route('bloc.programme'))
            ->assertOk()
            ->assertSee('Programme préopératoire')
            ->assertSee('Césarienne')
            ->assertSee('KABILA')
            ->assertSee('Bassin rétréci');
    }

    public function test_une_intervention_se_planifie_dans_une_salle(): void
    {
        $acte = $this->demande('Césarienne');
        $salle = $this->salle();

        $this->post(route('bloc.planifier', $acte), $this->creneau($salle, '2026-08-25T09:00'))
            ->assertRedirect()
            ->assertSessionHas('success');

        $acte->refresh();

        $this->assertSame('planifie', $acte->statut);
        $this->assertSame($salle->id, $acte->salle_id);
        $this->assertSame('2026-08-25 09:00', $acte->date_prevue->format('Y-m-d H:i'));
        $this->assertSame('2026-08-25 10:30', $acte->finPrevue()->format('Y-m-d H:i'));
        $this->assertSame('Rachianesthésie', $acte->libelleAnesthesie());
        $this->assertTrue($acte->consentement);
        $this->assertTrue($acte->estPlanifiee());
    }

    public function test_deux_interventions_ne_se_chevauchent_pas_dans_la_meme_salle(): void
    {
        $salle = $this->salle();
        $premiere = $this->demande('Césarienne');
        $seconde = $this->demande('Cure de hernie inguinale', 'chirurgie');

        $this->post(route('bloc.planifier', $premiere), $this->creneau($salle, '2026-08-25T09:00', 90))
            ->assertSessionHas('success');

        // 10 h tombe en plein milieu du créneau de 9 h à 10 h 30.
        $this->post(route('bloc.planifier', $seconde), $this->creneau($salle, '2026-08-25T10:00', 60))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame('prescrit', $seconde->fresh()->statut);
        $this->assertNull($seconde->fresh()->salle_id);
    }

    public function test_le_creneau_suivant_est_accepte(): void
    {
        $salle = $this->salle();
        $premiere = $this->demande('Césarienne');
        $seconde = $this->demande('Cure de hernie inguinale', 'chirurgie');

        $this->post(route('bloc.planifier', $premiere), $this->creneau($salle, '2026-08-25T09:00', 90));
        $this->post(route('bloc.planifier', $seconde), $this->creneau($salle, '2026-08-25T11:00', 60))
            ->assertSessionHas('success');

        $this->assertSame('planifie', $seconde->fresh()->statut);
    }

    public function test_la_meme_heure_dans_une_autre_salle_est_acceptee(): void
    {
        $premiere = $this->demande('Césarienne');
        $seconde = $this->demande('Cure de hernie inguinale', 'chirurgie');

        $this->post(route('bloc.planifier', $premiere), $this->creneau($this->salle('SOP-1'), '2026-08-25T09:00'));
        $this->post(route('bloc.planifier', $seconde), $this->creneau($this->salle('SOP-2'), '2026-08-25T09:00'))
            ->assertSessionHas('success');

        $this->assertSame('planifie', $seconde->fresh()->statut);
    }

    public function test_replanifier_une_intervention_ne_la_declare_pas_en_conflit_avec_elle_meme(): void
    {
        $acte = $this->demande('Césarienne');
        $salle = $this->salle();

        $this->post(route('bloc.planifier', $acte), $this->creneau($salle, '2026-08-25T09:00', 90));
        $this->post(route('bloc.planifier', $acte), $this->creneau($salle, '2026-08-25T09:30', 90))
            ->assertSessionHas('success');

        $this->assertSame('09:30', $acte->fresh()->date_prevue->format('H:i'));
    }

    public function test_lhoraire_du_bloc_affiche_la_semaine_salle_par_salle(): void
    {
        $acte = $this->demande('Césarienne');
        $this->post(route('bloc.planifier', $acte), $this->creneau($this->salle(), '2026-08-25T09:00'));

        $this->get(route('bloc.horaire', ['semaine' => '2026-08-25', 'salle' => $this->salle()->id]))
            ->assertOk()
            ->assertSee('Salle 1')
            ->assertSee('Césarienne')
            ->assertSee('09:00');

        // La semaine d'à côté ne montre rien.
        $this->get(route('bloc.horaire', ['semaine' => '2026-09-08', 'salle' => $this->salle()->id]))
            ->assertOk()
            ->assertDontSee('Césarienne');
    }

    // ═══════════════════════════════════════════════════════════
    // Clôture et registre
    // ═══════════════════════════════════════════════════════════

    protected function planifierEtCloturer(): ActeClinique
    {
        $acte = $this->demande('Césarienne');
        $this->post(route('bloc.planifier', $acte), $this->creneau($this->salle(), '2026-08-25T09:00'));

        $this->post(route('bloc.cloturer', $acte), [
            'heure_entree_salle' => '2026-08-25T09:05',
            'heure_sortie_salle' => '2026-08-25T10:20',
            'compte_rendu' => 'Incision de Pfannenstiel. Extraction d\'un nouveau-né vivant. Fermeture plan par plan.',
            'diagnostic_postop' => 'Césarienne pour bassin rétréci',
            'incidents' => 'Aucun',
            'kits' => [KitOperatoire::where('code', 'KIT-CESAR')->value('id')],
            'type_anesthesie' => 'rachianesthesie',
        ])->assertSessionHas('success');

        return $acte->fresh();
    }

    public function test_la_cloture_enregistre_le_compte_rendu_et_le_temps_de_salle(): void
    {
        $acte = $this->planifierEtCloturer();

        $this->assertSame('realise', $acte->statut);
        $this->assertSame(75, $acte->dureeReelleMinutes());
        $this->assertStringContainsString('Pfannenstiel', $acte->compte_rendu);
        $this->assertSame('Kit césarienne', $acte->libelleKits());
        $this->assertSame(45000.0, $acte->coutKits());
        $this->assertNotNull($acte->date_realisation);
    }

    public function test_une_intervention_deja_cloturee_ne_se_recloture_pas(): void
    {
        $acte = $this->planifierEtCloturer();

        $this->post(route('bloc.cloturer', $acte), [
            'heure_entree_salle' => '2026-08-25T09:05',
            'heure_sortie_salle' => '2026-08-25T11:00',
            'compte_rendu' => 'Deuxième version',
        ])->assertSessionHas('info');

        $this->assertStringContainsString('Pfannenstiel', $acte->fresh()->compte_rendu);
        $this->assertSame(75, $acte->fresh()->dureeReelleMinutes());
    }

    public function test_une_sortie_de_salle_anterieure_a_lentree_est_refusee(): void
    {
        $acte = $this->demande('Césarienne');
        $this->post(route('bloc.planifier', $acte), $this->creneau($this->salle(), '2026-08-25T09:00'));

        $this->post(route('bloc.cloturer', $acte), [
            'heure_entree_salle' => '2026-08-25T11:00',
            'heure_sortie_salle' => '2026-08-25T10:00',
            'compte_rendu' => 'Test',
        ])->assertSessionHasErrors('heure_sortie_salle');

        $this->assertSame('planifie', $acte->fresh()->statut);
    }

    public function test_un_compte_rendu_vide_est_refuse(): void
    {
        $acte = $this->demande('Césarienne');
        $this->post(route('bloc.planifier', $acte), $this->creneau($this->salle(), '2026-08-25T09:00'));

        $this->post(route('bloc.cloturer', $acte), [
            'heure_entree_salle' => '2026-08-25T09:00',
            'heure_sortie_salle' => '2026-08-25T10:00',
        ])->assertSessionHasErrors('compte_rendu');
    }

    public function test_le_registre_porte_lintervention_realisee(): void
    {
        $this->planifierEtCloturer();

        $this->get(route('bloc.registre', ['debut' => '2026-08-01', 'fin' => '2026-08-31']))
            ->assertOk()
            ->assertSee('KABILA')
            ->assertSee('Césarienne')
            ->assertSee('Rachianesthésie')
            ->assertSee('75 min')
            ->assertSee('Salle 1')
            ->assertSee('Privé');
    }

    public function test_la_feuille_dintervention_sert_de_compte_rendu_imprimable(): void
    {
        $acte = $this->planifierEtCloturer();

        $this->get(route('bloc.feuille', $acte))
            ->assertOk()
            ->assertSee('Compte rendu opératoire')
            ->assertSee('Pfannenstiel')
            ->assertSee('Kit césarienne')
            ->assertSee('75 minutes')
            ->assertSee('Rachianesthésie');
    }

    public function test_une_intervention_cloturee_quitte_la_liste_a_cloturer(): void
    {
        $acte = $this->planifierEtCloturer();

        $this->get(route('bloc.interventions', ['debut' => '2026-08-01', 'fin' => '2026-08-31']))
            ->assertOk()
            ->assertDontSee($acte->libelle);
    }

    public function test_un_sejour_termine_interdit_toute_programmation(): void
    {
        $acte = $this->demande('Césarienne');
        $this->visite->update(['statut' => 'termine', 'date_sortie' => now()]);

        $this->post(route('bloc.planifier', $acte), $this->creneau($this->salle(), '2026-08-25T09:00'))
            ->assertSessionHas('error');

        $this->assertSame('prescrit', $acte->fresh()->statut);
    }
}
