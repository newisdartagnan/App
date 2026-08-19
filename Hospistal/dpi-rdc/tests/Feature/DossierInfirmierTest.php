<?php

namespace Tests\Feature;

use App\Models\Establishment;
use App\Models\EvaluationNeuro;
use App\Models\NotificationInterne;
use App\Models\Patient;
use App\Models\PrescriptionDiete;
use App\Models\Service;
use App\Models\SoinGavage;
use App\Models\SoinPansement;
use App\Models\TacheMenage;
use App\Models\Transfusion;
use App\Models\TypeDiete;
use App\Models\User;
use App\Models\Visit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Dossier infirmier : pansement, gavage, évaluation neurologique,
 * transfusion — et le volet hôtelier diète / ménage des hospitalisés.
 */
class DossierInfirmierTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Patient $patient;

    protected Establishment $etab;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->user = User::where('email', 'admin@dpi-rdc.local')->firstOrFail();
        $this->actingAs($this->user);
        $this->etab = Establishment::firstOrFail();

        $this->patient = Patient::create([
            'establishment_id' => $this->etab->id,
            'dossier_number' => 'PAT-2026-008800',
            'nom' => 'KABEYA', 'postnom' => 'NGOYI', 'prenom' => 'Josue',
            'sexe' => 'M',
            'date_naissance' => now()->subYears(41)->toDateString(),
            'type_prise_en_charge' => 'prive',
        ]);
    }

    protected function sejour(string $statut = 'en_cours'): Visit
    {
        return Visit::create([
            'patient_id' => $this->patient->id,
            'establishment_id' => $this->etab->id,
            'user_id' => $this->user->id,
            'type' => 'hospitalisation',
            'statut' => $statut,
            'date_entree' => now()->subDays(3),
            'service_id' => Service::where('is_active', true)->first()?->id,
            'motif_consultation' => 'Surveillance post-opératoire',
        ]);
    }

    // ═══════════════════════════════════════════════════════════
    // Pansement
    // ═══════════════════════════════════════════════════════════

    public function test_un_pansement_programme_sa_refection(): void
    {
        $visite = $this->sejour();

        $this->post(route('infirmier.pansement', $visite), [
            'realise_a' => now()->format('Y-m-d H:i'),
            'localisation' => 'Cicatrice sus-ombilicale',
            'etat_plaie' => 'propre',
            'protocole' => 'Sérum physiologique, tulle gras, compresses stériles',
            'date_refaire' => now()->addDays(2)->toDateString(),
        ])->assertSessionHas('success');

        $soin = SoinPansement::where('visit_id', $visite->id)->firstOrFail();

        $this->assertSame('Cicatrice sus-ombilicale', $soin->localisation);
        $this->assertFalse($soin->refectionDue(), 'Une réfection dans deux jours n\'est pas encore due.');
        $this->assertSame(0, $soin->joursRetard());
    }

    public function test_une_refection_depassee_est_signalee_en_retard(): void
    {
        $visite = $this->sejour();

        $soin = SoinPansement::create([
            'visit_id' => $visite->id, 'user_id' => $this->user->id,
            'realise_a' => now()->subDays(5), 'localisation' => 'Talon droit',
            'etat_plaie' => 'bourgeonnante', 'protocole' => 'Hydrocolloïde',
            'date_refaire' => now()->subDays(2)->toDateString(),
        ]);

        $this->assertTrue($soin->refectionDue());
        $this->assertSame(2, $soin->joursRetard());
    }

    public function test_une_plaie_infectee_alerte_le_medecin(): void
    {
        $visite = $this->sejour();

        $this->post(route('infirmier.pansement', $visite), [
            'realise_a' => now()->format('Y-m-d H:i'),
            'localisation' => 'Escarre sacrée',
            'etat_plaie' => 'infectee',
            'protocole' => 'Détersion, pansement à l\'argent',
        ])->assertSessionHas('success');

        $notification = NotificationInterne::where('reference_type', 'pansement')->first();

        $this->assertNotNull($notification, 'Une plaie infectée doit alerter le médecin.');
        $this->assertSame($this->user->id, $notification->destinataire_id);
        $this->assertSame('haute', $notification->priorite);
    }

    public function test_un_sejour_termine_refuse_un_pansement(): void
    {
        $visite = $this->sejour('termine');

        $this->post(route('infirmier.pansement', $visite), [
            'realise_a' => now()->format('Y-m-d H:i'),
            'localisation' => 'Bras gauche',
            'etat_plaie' => 'propre',
            'protocole' => 'Compresses',
        ])->assertSessionHas('error');

        $this->assertSame(0, SoinPansement::where('visit_id', $visite->id)->count());
    }

    // ═══════════════════════════════════════════════════════════
    // Gavage
    // ═══════════════════════════════════════════════════════════

    public function test_le_gavage_calcule_la_quantite_retenue(): void
    {
        $visite = $this->sejour();

        $this->post(route('infirmier.gavage', $visite), [
            'realise_a' => now()->format('Y-m-d H:i'),
            'sonde' => 'naso_gastrique',
            'residu_gastrique' => 30,
            'type_aliment' => 'Nutrition entérale polymérique',
            'quantite_aliment' => 300,
            'quantite_eliminee' => 50,
            'tolerance' => 'bonne',
        ])->assertSessionHas('success');

        $gavage = SoinGavage::where('visit_id', $visite->id)->firstOrFail();

        $this->assertSame(250, $gavage->quantiteRetenue());
        $this->assertFalse($gavage->residuEleve());
        $this->assertNull($gavage->alerte());
    }

    public function test_un_residu_gastrique_eleve_declenche_une_alerte(): void
    {
        $visite = $this->sejour();

        $this->post(route('infirmier.gavage', $visite), [
            'realise_a' => now()->format('Y-m-d H:i'),
            'sonde' => 'gastrostomie',
            'residu_gastrique' => 400,
            'type_aliment' => 'Bouillie enrichie',
            'quantite_aliment' => 250,
            'tolerance' => 'bonne',
        ])->assertSessionHas('success');

        $gavage = SoinGavage::where('visit_id', $visite->id)->firstOrFail();

        $this->assertTrue($gavage->residuEleve());
        $this->assertStringContainsString('suspendre le gavage', $gavage->alerte());
        $this->assertSame(1, NotificationInterne::where('reference_type', 'gavage')->count());
    }

    // ═══════════════════════════════════════════════════════════
    // Évaluation neurologique
    // ═══════════════════════════════════════════════════════════

    public function test_le_score_de_glasgow_est_calcule_automatiquement(): void
    {
        $visite = $this->sejour();

        $this->post(route('infirmier.neuro', $visite), [
            'evalue_a' => now()->format('Y-m-d H:i'),
            'ouverture_yeux' => 4,
            'reponse_verbale' => 5,
            'reponse_motrice' => 6,
            'pupille_droite' => 'reactive',
            'pupille_gauche' => 'reactive',
        ])->assertSessionHas('success');

        $evaluation = EvaluationNeuro::where('visit_id', $visite->id)->firstOrFail();

        $this->assertSame(15, $evaluation->score);
        $this->assertSame('leger', $evaluation->gravite());
        $this->assertNull($evaluation->alerte());
    }

    public function test_un_glasgow_bas_alerte_en_urgence_et_met_a_jour_le_sejour(): void
    {
        $visite = $this->sejour();

        $this->post(route('infirmier.neuro', $visite), [
            'evalue_a' => now()->format('Y-m-d H:i'),
            'ouverture_yeux' => 2,
            'reponse_verbale' => 2,
            'reponse_motrice' => 3,
        ])->assertSessionHas('success');

        $evaluation = EvaluationNeuro::where('visit_id', $visite->id)->firstOrFail();

        $this->assertSame(7, $evaluation->score);
        $this->assertSame('grave', $evaluation->gravite());
        $this->assertStringContainsString('coma', $evaluation->alerte());

        $this->assertSame(7, (int) $visite->fresh()->glasgow, 'Le séjour doit porter le dernier Glasgow.');

        $notification = NotificationInterne::where('reference_type', 'evaluation_neuro')->firstOrFail();
        $this->assertSame('urgente', $notification->priorite);
    }

    public function test_un_glasgow_intermediaire_reste_en_priorite_haute(): void
    {
        $visite = $this->sejour();

        $this->post(route('infirmier.neuro', $visite), [
            'evalue_a' => now()->format('Y-m-d H:i'),
            'ouverture_yeux' => 3,
            'reponse_verbale' => 4,
            'reponse_motrice' => 5,
        ])->assertSessionHas('success');

        $evaluation = EvaluationNeuro::where('visit_id', $visite->id)->firstOrFail();

        $this->assertSame(12, $evaluation->score);
        $this->assertSame('modere', $evaluation->gravite());
        $this->assertSame('haute', NotificationInterne::where('reference_type', 'evaluation_neuro')->firstOrFail()->priorite);
    }

    // ═══════════════════════════════════════════════════════════
    // Transfusion
    // ═══════════════════════════════════════════════════════════

    public function test_une_poche_compatible_est_enregistree(): void
    {
        $visite = $this->sejour();

        $this->post(route('infirmier.transfusion', $visite), [
            'produit' => 'cgr',
            'groupe_donneur' => 'O-',
            'groupe_receveur' => 'A+',
            'numero_poche' => 'PCH-2026-0001',
            'quantite' => 300,
            'jour' => now()->toDateString(),
            'heure_debut' => '08:00',
            'heure_fin' => '10:30',
            'incident' => 'aucun',
        ])->assertSessionHas('success');

        $transfusion = Transfusion::where('visit_id', $visite->id)->firstOrFail();

        $this->assertSame(150, $transfusion->dureeMinutes());
        $this->assertFalse($transfusion->enCours());
        $this->assertFalse($transfusion->avecIncident());
    }

    public function test_une_poche_incompatible_est_refusee(): void
    {
        $visite = $this->sejour();

        $this->post(route('infirmier.transfusion', $visite), [
            'produit' => 'cgr',
            'groupe_donneur' => 'A+',
            'groupe_receveur' => 'O-',
            'numero_poche' => 'PCH-2026-0002',
            'quantite' => 300,
            'jour' => now()->toDateString(),
            'heure_debut' => '08:00',
            'incident' => 'aucun',
        ])->assertSessionHas('error');

        $this->assertSame(0, Transfusion::where('visit_id', $visite->id)->count());
    }

    public function test_la_compatibilite_du_plasma_se_lit_a_lenvers_du_globulaire(): void
    {
        // En globulaire un donneur O- convient à un receveur AB+ ;
        // en plasma c'est l'inverse, seul un donneur AB convient.
        $this->assertTrue(Transfusion::estCompatible('cgr', 'O-', 'AB+'));
        $this->assertFalse(Transfusion::estCompatible('pfc', 'O-', 'AB+'));
        $this->assertTrue(Transfusion::estCompatible('pfc', 'AB+', 'O-'));
        $this->assertTrue(Transfusion::estCompatible('pfc', 'A+', 'A-'), 'Le Rhésus n\'entre pas en compte pour le plasma.');
    }

    public function test_un_numero_de_poche_ne_peut_pas_etre_saisi_deux_fois(): void
    {
        $visite = $this->sejour();

        $donnees = [
            'produit' => 'cgr', 'groupe_donneur' => 'O-', 'groupe_receveur' => 'O-',
            'numero_poche' => 'PCH-2026-0003', 'quantite' => 250,
            'jour' => now()->toDateString(), 'heure_debut' => '09:00', 'incident' => 'aucun',
        ];

        $this->post(route('infirmier.transfusion', $visite), $donnees)->assertSessionHas('success');
        $this->post(route('infirmier.transfusion', $visite), $donnees)->assertSessionHas('error');

        $this->assertSame(1, Transfusion::where('visit_id', $visite->id)->count());
    }

    public function test_terminer_une_poche_avec_incident_alerte_en_urgence(): void
    {
        $visite = $this->sejour();

        $this->post(route('infirmier.transfusion', $visite), [
            'produit' => 'cgr', 'groupe_donneur' => 'O-', 'groupe_receveur' => 'B+',
            'numero_poche' => 'PCH-2026-0004', 'quantite' => 250,
            'jour' => now()->toDateString(), 'heure_debut' => '09:00', 'incident' => 'aucun',
        ]);

        $transfusion = Transfusion::where('numero_poche', 'PCH-2026-0004')->firstOrFail();
        $this->assertTrue($transfusion->enCours());

        $this->post(route('infirmier.transfusion.terminer', $transfusion), [
            'heure_fin' => '11:00',
            'incident' => 'frisson',
            'observation' => 'Frissons à la 20e minute, transfusion ralentie',
        ])->assertSessionHas('success');

        $transfusion->refresh();

        $this->assertSame(120, $transfusion->dureeMinutes());
        $this->assertTrue($transfusion->avecIncident());
        $this->assertSame('urgente', NotificationInterne::where('reference_type', 'transfusion')->firstOrFail()->priorite);
    }

    public function test_la_page_du_dossier_infirmier_affiche_les_quatre_onglets(): void
    {
        $visite = $this->sejour();

        $reponse = $this->get(route('infirmier.index', ['visit' => $visite->id]));

        $reponse->assertOk()
            ->assertSee('Pansement')
            ->assertSee('Gavage')
            ->assertSee('Transfusion');

        $this->get(route('infirmier.index', ['visit' => $visite->id, 'onglet' => 'neuro']))
            ->assertOk()
            ->assertSee('Glasgow');
    }

    // ═══════════════════════════════════════════════════════════
    // Diète et ménage
    // ═══════════════════════════════════════════════════════════

    public function test_le_referentiel_de_dietes_est_installe(): void
    {
        $this->assertGreaterThanOrEqual(8, TypeDiete::where('establishment_id', $this->etab->id)->count());
        $this->assertNotNull(TypeDiete::where('code', 'DB')->first(), 'La diète basique doit exister.');
    }

    public function test_prescrire_une_diete_cloture_la_precedente(): void
    {
        $visite = $this->sejour();
        $basique = TypeDiete::where('code', 'DB')->firstOrFail();
        $diabetique = TypeDiete::where('code', 'DDIAB')->firstOrFail();

        $this->post(route('diete.prescrire', $visite), [
            'type_diete_id' => $basique->id,
            'debut' => now()->subDays(2)->toDateString(),
        ])->assertSessionHas('success');

        $this->post(route('diete.prescrire', $visite), [
            'type_diete_id' => $diabetique->id,
            'debut' => now()->toDateString(),
        ])->assertSessionHas('success');

        $prescriptions = PrescriptionDiete::where('visit_id', $visite->id)->orderBy('debut')->get();

        $this->assertCount(2, $prescriptions);
        $this->assertSame(now()->subDay()->toDateString(), $prescriptions[0]->fin->toDateString());
        $this->assertNull($prescriptions[1]->fin);
        $this->assertSame($diabetique->id, $visite->fresh()->dieteEnCours()->type_diete_id);
    }

    public function test_represcrire_la_meme_diete_est_refuse(): void
    {
        $visite = $this->sejour();
        $basique = TypeDiete::where('code', 'DB')->firstOrFail();

        $this->post(route('diete.prescrire', $visite), [
            'type_diete_id' => $basique->id, 'debut' => now()->toDateString(),
        ])->assertSessionHas('success');

        $this->post(route('diete.prescrire', $visite), [
            'type_diete_id' => $basique->id, 'debut' => now()->toDateString(),
        ])->assertSessionHas('error');

        $this->assertSame(1, PrescriptionDiete::where('visit_id', $visite->id)->count());
    }

    public function test_la_diete_est_facturable_au_prorata_des_jours_servis(): void
    {
        $visite = $this->sejour();
        $hyperproteinee = TypeDiete::where('code', 'DHP')->firstOrFail();

        $prescription = PrescriptionDiete::create([
            'visit_id' => $visite->id,
            'type_diete_id' => $hyperproteinee->id,
            'user_id' => $this->user->id,
            'debut' => now()->subDays(3)->toDateString(),
            'fin' => now()->toDateString(),
        ]);

        $this->assertSame(4, $prescription->joursServis(), 'Les journées entamées comptent pour une.');
        $this->assertSame(4 * (float) $hyperproteinee->prix_journalier, $prescription->montant());
    }

    public function test_arreter_la_diete_cloture_la_prescription_en_cours(): void
    {
        $visite = $this->sejour();
        $basique = TypeDiete::where('code', 'DB')->firstOrFail();

        $this->post(route('diete.prescrire', $visite), [
            'type_diete_id' => $basique->id, 'debut' => now()->toDateString(),
        ]);

        $this->post(route('diete.arreter', $visite))->assertSessionHas('success');

        $this->assertNull($visite->fresh()->dieteEnCours());
        $this->post(route('diete.arreter', $visite))->assertSessionHas('error');
    }

    public function test_le_menage_est_unique_par_type_et_par_jour(): void
    {
        $visite = $this->sejour();

        $this->post(route('diete.menage', $visite), [
            'jour' => now()->toDateString(), 'type' => 'nettoyage', 'statut' => 'fait',
        ])->assertSessionHas('success');

        $this->post(route('diete.menage', $visite), [
            'jour' => now()->toDateString(), 'type' => 'nettoyage', 'statut' => 'refuse',
            'observation' => 'Patient au bloc',
        ])->assertSessionHas('success');

        $taches = TacheMenage::where('visit_id', $visite->id)->get();

        $this->assertCount(1, $taches, 'La saisie du jour est mise à jour, pas dupliquée.');
        $this->assertSame('refuse', $taches->first()->statut);
    }

    public function test_lecran_diete_recapitule_les_plateaux_et_signale_les_manques(): void
    {
        $visite = $this->sejour();
        $basique = TypeDiete::where('code', 'DB')->firstOrFail();

        PrescriptionDiete::create([
            'visit_id' => $visite->id, 'type_diete_id' => $basique->id,
            'user_id' => $this->user->id, 'debut' => now()->toDateString(),
        ]);

        // Un second séjour laissé sans diète doit être compté comme manquant.
        $autre = Patient::create([
            'establishment_id' => $this->etab->id,
            'dossier_number' => 'PAT-2026-008801',
            'nom' => 'ILUNGA', 'prenom' => 'Grace', 'sexe' => 'F',
            'date_naissance' => now()->subYears(22)->toDateString(),
            'type_prise_en_charge' => 'prive',
        ]);
        Visit::create([
            'patient_id' => $autre->id, 'establishment_id' => $this->etab->id,
            'user_id' => $this->user->id, 'type' => 'hospitalisation', 'statut' => 'en_cours',
            'date_entree' => now(), 'motif_consultation' => 'Observation',
        ]);

        $this->get(route('diete.index'))
            ->assertOk()
            ->assertSee('Diète basique')
            ->assertSee('sans diète prescrite')
            ->assertSee('KABEYA')
            ->assertSee('ILUNGA');
    }

    public function test_la_feuille_de_service_est_imprimable(): void
    {
        $visite = $this->sejour();
        $basique = TypeDiete::where('code', 'DB')->firstOrFail();

        PrescriptionDiete::create([
            'visit_id' => $visite->id, 'type_diete_id' => $basique->id,
            'user_id' => $this->user->id, 'debut' => now()->toDateString(),
        ]);

        $this->get(route('diete.imprimer'))
            ->assertOk()
            ->assertSee('Feuille de service')
            ->assertSee('Diète basique')
            ->assertSee('Responsable cuisine');
    }

    public function test_un_sejour_termine_refuse_une_diete(): void
    {
        $visite = $this->sejour('termine');
        $basique = TypeDiete::where('code', 'DB')->firstOrFail();

        $this->post(route('diete.prescrire', $visite), [
            'type_diete_id' => $basique->id, 'debut' => now()->toDateString(),
        ])->assertSessionHas('error');

        $this->assertSame(0, PrescriptionDiete::where('visit_id', $visite->id)->count());
    }
}
