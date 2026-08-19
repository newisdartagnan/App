<?php

namespace Tests\Feature;

use App\Models\Consultation;
use App\Models\Establishment;
use App\Models\LignePrescription;
use App\Models\Medicament;
use App\Models\Officine;
use App\Models\Patient;
use App\Models\PlanAdministration;
use App\Models\Prescription;
use App\Models\ReferentielMedical;
use App\Models\Requisition;
use App\Models\StockMedicament;
use App\Models\TriageUrgence;
use App\Models\User;
use App\Models\Visit;
use App\Services\DossierMedicalService;
use App\Services\OfficineService;
use App\Services\TriageUrgenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pharmacie à deux niveaux, dossier médical structuré, triage d'urgence
 * et plan d'administration des traitements.
 */
class ModulesAvancesTest extends TestCase
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
            'dossier_number' => 'TST-2026-001100',
            'nom' => 'KABILA', 'postnom' => 'NGOY', 'prenom' => 'Esther',
            'sexe' => 'F',
            'date_naissance' => now()->subYears(31)->toDateString(),
            'type_prise_en_charge' => 'prive',
        ]);
    }

    protected function visiteUrgence(): Visit
    {
        return Visit::create([
            'patient_id' => $this->patient->id,
            'establishment_id' => $this->etab->id,
            'user_id' => $this->user->id,
            'type' => 'urgence', 'statut' => 'en_cours',
            'date_entree' => now(), 'motif_consultation' => 'Douleur thoracique',
        ]);
    }

    protected function sejour(): Visit
    {
        return Visit::create([
            'patient_id' => $this->patient->id,
            'establishment_id' => $this->etab->id,
            'user_id' => $this->user->id,
            'type' => 'hospitalisation', 'statut' => 'en_cours',
            'date_entree' => now()->subDay(),
        ]);
    }

    // ═══════════════════════════════════════════════════════════
    // Pharmacie à deux niveaux
    // ═══════════════════════════════════════════════════════════

    public function test_le_stock_exige_une_officine_selectionnee(): void
    {
        $this->get(route('officines.stock'))
            ->assertRedirect(route('officines.index'))
            ->assertSessionHas('error', 'Prière de sélectionner une officine.');
    }

    public function test_selection_d_officine_puis_affichage_du_stock(): void
    {
        $ambulatoire = Officine::where('type', 'ambulatoire')->firstOrFail();

        $this->get(route('officines.index'))->assertOk()->assertSee('Officine ambulatoire');

        $this->post(route('officines.activer', $ambulatoire))
            ->assertRedirect(route('officines.stock'));

        $this->get(route('officines.stock'))
            ->assertOk()
            ->assertSee('Officine ambulatoire')
            ->assertSee('Paracétamol');
    }

    public function test_requisition_servie_transfere_le_stock_du_depot_vers_l_officine(): void
    {
        $service = app(OfficineService::class);
        $depot = Officine::where('type', 'depot_central')->firstOrFail();
        $ambulatoire = Officine::where('type', 'ambulatoire')->firstOrFail();
        $medicament = Medicament::where('denomination_commune', 'Amoxicilline')->firstOrFail();

        $avantDepot = $service->quantiteDisponible($depot, $medicament->id);
        $avantOfficine = $service->quantiteDisponible($ambulatoire, $medicament->id);

        $requisition = $service->creerRequisition($ambulatoire, [$medicament->id => 300], 'Réassort hebdomadaire');

        $this->assertSame('envoyee', $requisition->statut);
        $this->assertStringStartsWith('REQ-', $requisition->numero);

        // Le dépôt sert partiellement : 100 sur les 300 demandés
        $ligne = $requisition->lignes->first();
        $erreurs = $service->servirRequisition($requisition, [$ligne->id => 100]);

        $this->assertSame([], $erreurs);
        $requisition->refresh();
        $this->assertSame('partiellement_servie', $requisition->statut);
        $this->assertEquals(100, (float) $requisition->lignes->first()->quantite_servie);

        // Le stock a bien changé de main
        $this->assertEquals($avantDepot - 100, $service->quantiteDisponible($depot, $medicament->id));
        $this->assertEquals($avantOfficine + 100, $service->quantiteDisponible($ambulatoire, $medicament->id));

        // Le mouvement est tracé des deux côtés
        $this->assertDatabaseHas('mouvements_stock', [
            'type' => 'transfert_sortie', 'officine_id' => $depot->id, 'destination' => $ambulatoire->nom,
        ]);
        $this->assertDatabaseHas('mouvements_stock', [
            'type' => 'transfert_entree', 'officine_id' => $ambulatoire->id, 'provenance' => $depot->nom,
        ]);

        // Le solde est servi ensuite : la réquisition se clôt
        $service->servirRequisition($requisition->fresh('lignes'), [$ligne->id => 200]);
        $this->assertSame('servie', $requisition->fresh()->statut);
    }

    public function test_le_depot_refuse_de_servir_au_dela_de_son_stock(): void
    {
        $service = app(OfficineService::class);
        $ambulatoire = Officine::where('type', 'ambulatoire')->firstOrFail();
        $depot = Officine::where('type', 'depot_central')->firstOrFail();
        $medicament = Medicament::where('denomination_commune', 'Quinine')->firstOrFail();

        $dispo = $service->quantiteDisponible($depot, $medicament->id);
        $requisition = $service->creerRequisition($ambulatoire, [$medicament->id => $dispo + 500]);
        $ligne = $requisition->lignes->first();

        $erreurs = $service->servirRequisition($requisition, [$ligne->id => $dispo + 500]);

        $this->assertNotEmpty($erreurs);
        $this->assertStringContainsString('stock dépôt insuffisant', $erreurs[0]);
        $this->assertEquals($dispo, $service->quantiteDisponible($depot, $medicament->id));
    }

    public function test_le_tableau_de_bord_du_depot_liste_les_demandes(): void
    {
        $ambulatoire = Officine::where('type', 'ambulatoire')->firstOrFail();
        $medicament = Medicament::first();
        app(OfficineService::class)->creerRequisition($ambulatoire, [$medicament->id => 50], 'Test dépôt');

        $this->get(route('officines.depot'))
            ->assertOk()
            ->assertSee('Demandes des officines')
            ->assertSee('Officine ambulatoire')
            ->assertSee('Test dépôt');
    }

    public function test_entree_fournisseur_au_depot(): void
    {
        $depot = Officine::where('type', 'depot_central')->firstOrFail();
        $medicament = Medicament::first();
        $avant = app(OfficineService::class)->quantiteDisponible($depot, $medicament->id);

        $this->post(route('officines.entree'), [
            'officine_id' => $depot->id,
            'medicament_id' => $medicament->id,
            'quantite' => 250,
            'provenance' => 'Fournisseur Kin-Pharma',
            'lot' => 'LOT-TEST-9',
            'date_peremption' => now()->addYear()->toDateString(),
        ])->assertRedirect();

        $this->assertEquals($avant + 250, app(OfficineService::class)->quantiteDisponible($depot, $medicament->id));
        $this->assertDatabaseHas('mouvements_stock', [
            'type' => 'entree', 'provenance' => 'Fournisseur Kin-Pharma',
        ]);
    }

    // ═══════════════════════════════════════════════════════════
    // Dossier médical structuré
    // ═══════════════════════════════════════════════════════════

    public function test_antecedents_et_allergies_choisis_dans_le_referentiel(): void
    {
        $antecedent = ReferentielMedical::antecedents()->where('code', 'ATCD-HTA')->firstOrFail();
        $allergie = ReferentielMedical::allergies()->where('code', 'ALG-PENI')->firstOrFail();

        $this->get(route('dossier.show', $this->patient))
            ->assertOk()
            ->assertSee('Antécédents')
            ->assertSee('Hypertension artérielle');

        $this->post(route('dossier.referentiel.store', $this->patient), [
            'referentiel_id' => $antecedent->id,
            'precision' => 'Traitée depuis 2020',
        ])->assertRedirect();

        $this->post(route('dossier.referentiel.store', $this->patient), [
            'referentiel_id' => $allergie->id,
            'severite' => 'severe',
        ])->assertRedirect();

        $dossier = app(DossierMedicalService::class);
        $this->assertCount(1, $dossier->antecedents($this->patient));
        $this->assertCount(1, $dossier->allergies($this->patient));

        $this->get(route('dossier.show', $this->patient))
            ->assertOk()
            ->assertSee('Traitée depuis 2020')
            ->assertSee('Allergies connues');
    }

    public function test_une_prescription_est_bloquee_par_une_allergie_connue(): void
    {
        // Le patient est allergique à l'amoxicilline
        $allergie = ReferentielMedical::allergies()->where('code', 'ALG-AMOX')->firstOrFail();
        app(DossierMedicalService::class)->ajouter($this->patient, $allergie->id, 'severe');

        $visite = Visit::create([
            'patient_id' => $this->patient->id, 'establishment_id' => $this->etab->id,
            'user_id' => $this->user->id, 'type' => 'consultation_externe',
            'statut' => 'en_cours', 'date_entree' => now(),
        ]);
        $consultation = Consultation::create([
            'visit_id' => $visite->id, 'user_id' => $this->user->id,
            'date_consultation' => now(), 'statut' => 'finalise',
        ]);
        $amoxicilline = Medicament::where('denomination_commune', 'Amoxicilline')->firstOrFail();

        $lignes = [[
            'medicament_id' => $amoxicilline->id, 'dose' => '1 cp',
            'frequence' => '3x/jour', 'duree_jours' => 7, 'quantite_totale' => 21,
        ]];

        // Sans confirmation : refusée
        $this->post(route('prescriptions.store', $consultation), ['lignes' => $lignes])
            ->assertSessionHasErrors('allergie');
        $this->assertSame(0, Prescription::count());

        // Avec confirmation explicite du prescripteur : acceptée
        $this->post(route('prescriptions.store', $consultation), $lignes === []
            ? [] : ['lignes' => $lignes, 'confirmer_allergie' => '1'])
            ->assertRedirect();
        $this->assertSame(1, Prescription::count());
    }

    public function test_un_produit_sans_rapport_avec_l_allergie_passe(): void
    {
        $allergie = ReferentielMedical::allergies()->where('code', 'ALG-PENI')->firstOrFail();
        app(DossierMedicalService::class)->ajouter($this->patient, $allergie->id);

        $metformine = Medicament::where('denomination_commune', 'Metformine')->firstOrFail();

        $alertes = app(DossierMedicalService::class)
            ->alertesAllergie($this->patient, [$metformine->id]);

        $this->assertSame([], $alertes);
    }

    public function test_document_clinique_redige_valide_et_imprime(): void
    {
        $this->post(route('dossier.document.store', $this->patient), [
            'type' => 'certificat_aptitude',
            'titre' => "Certificat d'aptitude physique",
            'contenu' => 'Je soussigné certifie que la patiente est apte à la pratique sportive.',
        ])->assertRedirect();

        $document = \App\Models\DocumentClinique::firstOrFail();
        $this->assertSame('redige', $document->statut);

        $this->post(route('dossier.document.valider', $document))->assertRedirect();
        $this->assertSame('valide', $document->fresh()->statut);

        $this->get(route('dossier.document.imprimer', $document))
            ->assertOk()
            ->assertSee("Certificat d'aptitude physique")
            ->assertSee('KABILA NGOY Esther');
    }

    // ═══════════════════════════════════════════════════════════
    // Triage d'urgence structuré
    // ═══════════════════════════════════════════════════════════

    public function test_le_niveau_de_triage_est_calcule_par_le_critere_le_plus_grave(): void
    {
        $service = app(TriageUrgenceService::class);

        // Aucun critère : non urgent
        $this->assertSame(5, $service->calculerNiveau([])['niveau']);

        // Critères bénins seulement
        $this->assertSame(5, $service->calculerNiveau(['fc_normale', 'afebrile', 'bon_etat'])['niveau']);

        // Un critère de niveau 3 dans un ensemble bénin l'emporte
        $calcul = $service->calculerNiveau(['fc_normale', 'afebrile', 'douleur_intense']);
        $this->assertSame(3, $calcul['niveau']);
        $this->assertSame(['douleur_intense'], $calcul['declencheurs']);

        // Une alerte vitale impose le niveau 1, quels que soient les autres
        $calcul = $service->calculerNiveau(['bon_etat', 'fc_normale', 'arret_cardiorespiratoire', 'cyanose']);
        $this->assertSame(1, $calcul['niveau']);
        $this->assertSame(0, $calcul['delai']);
        $this->assertEqualsCanonicalizing(['arret_cardiorespiratoire', 'cyanose'], $calcul['declencheurs']);
    }

    public function test_triage_enregistre_et_file_de_prise_en_charge(): void
    {
        $visite = $this->visiteUrgence();

        $this->get(route('urgences.index'))
            ->assertOk()
            ->assertSee('En attente de triage')
            ->assertSee('KABILA NGOY Esther');

        $this->get(route('urgences.triage', $visite))
            ->assertOk()
            ->assertSee('alerte vitale')
            ->assertSee('Arrêt cardiorespiratoire')
            ->assertSee('Fin du triage');

        $this->post(route('urgences.triage.store', $visite), [
            'criteres' => [
                'circulation' => 'fc_140_180',
                'temperature' => 'febrile',
                'pathologie_1' => 'douleur_thoracique',
            ],
            'atr' => '0',
            'observation' => 'Douleur rétrosternale irradiant au bras gauche.',
        ])->assertRedirect(route('urgences.index', ['onglet' => 'prise_en_charge']));

        $triage = TriageUrgence::firstOrFail();
        // Douleur thoracique impose le niveau 2 (15 minutes)
        $this->assertSame(2, $triage->niveau);
        $this->assertSame(15, $triage->delai_cible_minutes);
        $this->assertSame(['douleur_thoracique'], $triage->criteres_declencheurs);

        // Le triage vaut prise en charge infirmière initiale
        $this->assertNotNull($visite->fresh()->triage_fait_at);

        $this->get(route('urgences.index'))
            ->assertOk()
            ->assertSee('Prise en charge')
            ->assertSee('Très urgent');
    }

    public function test_le_registre_des_triages_montre_la_distribution(): void
    {
        $visite = $this->visiteUrgence();
        app(TriageUrgenceService::class)->enregistrer($visite, ['glasgow_inf_10'], true);

        $this->get(route('urgences.registre'))
            ->assertOk()
            ->assertSee('REGISTRE DES TRIAGES')
            ->assertSee('Critères déterminants')
            ->assertSee('Achevé par')
            ->assertSee('Réanimation')
            ->assertSee('KABILA NGOY ESTHER');
    }

    public function test_un_sejour_termine_refuse_un_nouveau_triage(): void
    {
        $visite = $this->visiteUrgence();
        $visite->update(['statut' => 'termine', 'date_sortie' => now()]);

        $this->post(route('urgences.triage.store', $visite), ['criteres' => ['fc_normale']])
            ->assertSessionHas('error');

        $this->assertSame(0, TriageUrgence::count());
    }

    // ═══════════════════════════════════════════════════════════
    // Plan d'administration des traitements (grille 24 h)
    // ═══════════════════════════════════════════════════════════

    public function test_grille_24h_ajout_administration_et_annulation(): void
    {
        $sejour = $this->sejour();

        $this->get(route('mar.index', ['visit' => $sejour->id]))
            ->assertOk()
            ->assertSee("Plan d'administration", false)
            ->assertSee('Copier vers le jour suivant');

        $this->post(route('mar.store', $sejour), [
            'jour' => now()->toDateString(),
            'libelle' => 'AMOXICILLINE 1 g x3/j IVD',
            'heures' => [8, 14, 20],
        ])->assertRedirect();

        $plan = PlanAdministration::firstOrFail();
        $this->assertSame([8, 14, 20], $plan->heures);

        // Cocher la prise de 8 h
        $this->post(route('mar.basculer', $plan), ['heure' => 8])->assertRedirect();
        $this->assertSame(1, $plan->fresh()->administrations->count());
        $this->assertNotNull($plan->fresh()->administreeA(8));

        // Recliquer annule la prise
        $this->post(route('mar.basculer', $plan), ['heure' => 8])->assertRedirect();
        $this->assertSame(0, $plan->fresh()->administrations->count());

        $this->get(route('mar.index', ['visit' => $sejour->id]))
            ->assertOk()
            ->assertSee('AMOXICILLINE 1 g x3/j IVD');
    }

    public function test_le_plan_se_reconduit_au_jour_suivant(): void
    {
        $sejour = $this->sejour();
        $jour = now()->toDateString();
        $lendemain = now()->addDay()->toDateString();

        PlanAdministration::create([
            'visit_id' => $sejour->id, 'libelle' => 'CEFTRIAXONE 1 g x2/j IVD',
            'jour' => $jour, 'heures' => [8, 20], 'cree_par' => $this->user->id,
        ]);

        $this->post(route('mar.copier', $sejour), ['jour' => $jour])
            ->assertRedirect(route('mar.index', ['visit' => $sejour->id, 'jour' => $lendemain]));

        $this->assertSame(1, PlanAdministration::whereDate('jour', $lendemain)->count());
        $copie = PlanAdministration::whereDate('jour', $lendemain)->firstOrFail();
        $this->assertSame('CEFTRIAXONE 1 g x2/j IVD', $copie->libelle);
        $this->assertSame([8, 20], $copie->heures);

        // Reconduire deux fois ne duplique pas
        $this->post(route('mar.copier', $sejour), ['jour' => $jour]);
        $this->assertSame(1, PlanAdministration::whereDate('jour', $lendemain)->count());
    }

    public function test_le_plan_reprend_le_libelle_d_une_ordonnance(): void
    {
        $sejour = $this->sejour();
        $consultation = Consultation::create([
            'visit_id' => $sejour->id, 'user_id' => $this->user->id,
            'date_consultation' => now(), 'statut' => 'finalise',
        ]);
        $prescription = Prescription::create([
            'consultation_id' => $consultation->id, 'patient_id' => $this->patient->id,
            'prescripteur_id' => $this->user->id, 'date_prescription' => now(), 'statut' => 'brouillon',
        ]);
        $medicament = Medicament::where('denomination_commune', 'Ceftriaxone')->firstOrFail();
        $ligne = LignePrescription::create([
            'prescription_id' => $prescription->id, 'medicament_id' => $medicament->id,
            'dose' => '1 flacon', 'frequence' => '2x/jour', 'duree_jours' => 5,
            'voie_administration' => 'injectable_iv', 'quantite_totale' => 10,
            'quantite_dispensee' => 0, 'est_substituable' => false,
        ]);

        $this->post(route('mar.store', $sejour), [
            'jour' => now()->toDateString(),
            'ligne_prescription_id' => $ligne->id,
            'heures' => [9, 21],
        ])->assertRedirect();

        $plan = PlanAdministration::firstOrFail();
        $this->assertStringContainsString('Ceftriaxone', $plan->libelle);
        $this->assertStringContainsString('2x/jour', $plan->libelle);
        $this->assertSame($ligne->id, $plan->ligne_prescription_id);
    }

    public function test_un_sejour_termine_refuse_un_nouveau_plan(): void
    {
        $sejour = $this->sejour();
        $sejour->update(['statut' => 'termine', 'date_sortie' => now()]);

        $this->post(route('mar.store', $sejour), [
            'jour' => now()->toDateString(),
            'libelle' => 'Traitement tardif',
            'heures' => [8],
        ])->assertSessionHas('error');

        $this->assertSame(0, PlanAdministration::count());
    }
}
