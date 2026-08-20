<?php

namespace Tests\Feature;

use App\Models\Assurance;
use App\Models\Caution;
use App\Models\DisponibiliteMedecin;
use App\Models\Establishment;
use App\Models\Facture;
use App\Models\Forfait;
use App\Models\Patient;
use App\Models\PatientAssurance;
use App\Models\PrescriptionDiete;
use App\Models\Service;
use App\Models\TypeConsultation;
use App\Models\TypeDiete;
use App\Models\User;
use App\Models\Visit;
use App\Services\AcompteService;
use App\Services\DisponibiliteService;
use App\Services\FacturationService;
use App\Services\ForfaitService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Acomptes de soins, règles de forfait, nom de l'assureur sur la facture,
 * disponibilité des médecins et tenue de la file d'attente.
 */
class AcomptesForfaitsFileTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Establishment $etab;

    protected Patient $patient;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->user = User::where('email', 'admin@dpi-rdc.local')->firstOrFail();
        $this->actingAs($this->user);
        $this->etab = Establishment::firstOrFail();

        $this->patient = Patient::create([
            'establishment_id' => $this->etab->id,
            'dossier_number' => 'PAT-2026-005500',
            'nom' => 'MBALA', 'postnom' => 'KIESE', 'prenom' => 'Antoine',
            'sexe' => 'M',
            'date_naissance' => now()->subYears(46)->toDateString(),
            'type_prise_en_charge' => 'prive',
        ]);
    }

    /** Assureur de référence des tests, créé si l'établissement n'en a pas. */
    protected function assureur(): Assurance
    {
        return Assurance::firstOrCreate(
            ['code' => 'SONAS'],
            ['nom' => 'SONAS', 'taux_couverture' => 80, 'est_actif' => true]
        );
    }

    protected function sejour(string $type = 'hospitalisation', string $statut = 'en_cours'): Visit
    {
        return Visit::create([
            'patient_id' => $this->patient->id,
            'establishment_id' => $this->etab->id,
            'user_id' => $this->user->id,
            'type' => $type,
            'statut' => $statut,
            'date_entree' => now()->subDays(2),
            'service_id' => Service::where('is_active', true)->first()?->id,
            'motif_consultation' => 'Surveillance',
        ]);
    }

    // ═══════════════════════════════════════════════════════════
    // Nom de l'assureur sur la facture
    // ═══════════════════════════════════════════════════════════

    public function test_la_facture_porte_le_nom_de_lassureur_et_non_le_mot_assurance(): void
    {
        $assurance = $this->assureur();

        $this->patient->update(['type_prise_en_charge' => 'assurance']);

        PatientAssurance::create([
            'patient_id' => $this->patient->id,
            'assurance_id' => $assurance->id,
            'numero_police' => 'SN-8842',
            'date_debut' => now()->subYear()->toDateString(),
            'annee_courante' => (int) now()->format('Y'),
            'est_actif' => true,
        ]);

        $visite = $this->sejour();
        $facture = app(FacturationService::class)->creerFactureHospitalisation($visite);

        $this->assertSame('SONAS', $facture->assurance_nom);
        $this->assertSame('SN-8842', $facture->assurance_numero);
        $this->assertSame('SONAS — n° SN-8842', $facture->libellePriseEnCharge());

        $this->get(route('caisse.imprimer', $facture))
            ->assertOk()
            ->assertSee('SONAS')
            ->assertDontSee('Prise en charge :</strong> Assurance', false);
    }

    public function test_un_patient_prive_garde_un_libelle_lisible(): void
    {
        $facture = app(FacturationService::class)->creerFactureHospitalisation($this->sejour());

        $this->assertSame('Privé', $facture->libellePriseEnCharge());
        $this->assertNull($facture->assurance_nom);
    }

    // ═══════════════════════════════════════════════════════════
    // Acomptes
    // ═══════════════════════════════════════════════════════════

    public function test_un_acompte_simpute_sur_la_facture_du_sejour(): void
    {
        $visite = $this->sejour();

        $this->post(route('acomptes.store', $visite), [
            'montant' => 100000,
            'devise' => 'CDF',
            'mode_paiement' => 'especes',
            'type' => 'acompte',
            'motif' => 'Avance à l\'admission',
        ])->assertSessionHas('success');

        $facture = app(FacturationService::class)->creerFactureHospitalisation($visite);
        $facture->refresh();

        $this->assertSame(100000.0, (float) $facture->acompte_impute);
        $this->assertSame(
            (float) $facture->patient_part - 100000.0,
            $facture->soldeRestant(),
            'Le reste à payer au guichet déduit l\'acompte.'
        );

        $acompte = Caution::where('visit_id', $visite->id)->firstOrFail();
        $this->assertSame(100000.0, (float) $acompte->montant_impute);
        $this->assertSame(0.0, $acompte->resteDisponible());
        $this->assertSame('soldee', $acompte->fresh()->statut);
    }

    public function test_un_acompte_verse_apres_la_facture_rattrape_les_factures_ouvertes(): void
    {
        $visite = $this->sejour();
        $facture = app(FacturationService::class)->creerFactureHospitalisation($visite);

        $this->post(route('acomptes.store', $visite), [
            'montant' => 50000, 'devise' => 'CDF',
            'mode_paiement' => 'mobile_money', 'type' => 'acompte',
        ])->assertSessionHas('success');

        $this->assertSame(50000.0, (float) $facture->fresh()->acompte_impute);
    }

    public function test_un_acompte_superieur_a_la_facture_solde_et_laisse_un_reliquat(): void
    {
        $visite = $this->sejour();

        app(AcompteService::class)->encaisser($visite, 1000000);

        $facture = app(FacturationService::class)->creerFactureHospitalisation($visite);
        $facture->refresh();

        $this->assertSame('payee', $facture->statut, 'La facture est soldée par l\'acompte.');
        $this->assertSame(0.0, $facture->soldeRestant());
        $this->assertGreaterThan(0, app(AcompteService::class)->soldeDisponible($visite->id));
    }

    public function test_le_reliquat_est_rembourse_a_la_sortie(): void
    {
        $visite = $this->sejour();
        app(AcompteService::class)->encaisser($visite, 1000000);
        app(FacturationService::class)->creerFactureHospitalisation($visite);

        $disponible = app(AcompteService::class)->soldeDisponible($visite->id);
        $this->assertGreaterThan(0, $disponible);

        $this->post(route('acomptes.rembourser', $visite))->assertSessionHas('success');

        $this->assertSame(0.0, app(AcompteService::class)->soldeDisponible($visite->id));
        $this->assertSame($disponible, (float) Caution::where('visit_id', $visite->id)->sum('montant_rembourse_cdf'));

        $this->post(route('acomptes.rembourser', $visite))->assertSessionHas('error');
    }

    public function test_un_acompte_est_refuse_en_consultation_externe(): void
    {
        $visite = $this->sejour('consultation_externe');

        $this->post(route('acomptes.store', $visite), [
            'montant' => 10000, 'devise' => 'CDF',
            'mode_paiement' => 'especes', 'type' => 'acompte',
        ])->assertSessionHas('error');

        $this->assertSame(0, Caution::where('visit_id', $visite->id)->count());
    }

    public function test_les_urgences_acceptent_un_acompte(): void
    {
        $visite = $this->sejour('urgence');

        $this->post(route('acomptes.store', $visite), [
            'montant' => 25000, 'devise' => 'CDF',
            'mode_paiement' => 'especes', 'type' => 'caution',
        ])->assertSessionHas('success');

        $this->assertSame(1, Caution::where('visit_id', $visite->id)->count());
    }

    // ═══════════════════════════════════════════════════════════
    // Forfaits
    // ═══════════════════════════════════════════════════════════

    protected function forfait(string $portee, array $categories = [], ?int $jours = null): Forfait
    {
        return Forfait::create([
            'establishment_id' => $this->etab->id,
            'code' => strtoupper($portee).'-'.count($categories).($jours ?? 'X'),
            'libelle' => 'Forfait '.$portee,
            'portee' => $portee,
            'montant' => 250000,
            'devise' => 'CDF',
            'categories_couvertes' => $portee === 'global' ? array_keys(Forfait::CATEGORIES) : $categories,
            'jours_inclus' => $jours,
            'is_active' => true,
        ]);
    }

    public function test_un_forfait_global_couvre_tout_le_sejour(): void
    {
        $visite = $this->sejour();
        $forfait = $this->forfait('global');

        $this->post(route('forfaits.appliquer', $visite), ['forfait_id' => $forfait->id])
            ->assertSessionHas('success');

        $visite->refresh();
        $this->assertSame($forfait->id, $visite->forfait_id);
        $this->assertSame(250000.0, (float) $visite->forfait_montant);

        $facture = app(FacturationService::class)->creerFactureHospitalisation($visite);

        // Les journées figurent au dossier mais à montant nul : tout est
        // couvert par le forfait déjà facturé.
        $this->assertSame(0.0, (float) $facture->total_ttc);
        $this->assertNotNull($facture->lignes->firstWhere('type', 'hospitalisation'));
        $this->assertStringContainsString('inclus au forfait', $facture->lignes->first()->libelle);
    }

    public function test_un_forfait_partiel_ne_couvre_que_les_categories_cochees(): void
    {
        $visite = $this->sejour();

        // Le forfait couvre la diète, pas les journées d'hospitalisation.
        $forfait = $this->forfait('partiel', ['diete']);
        app(ForfaitService::class)->appliquer($visite, $forfait);

        PrescriptionDiete::create([
            'visit_id' => $visite->id,
            'type_diete_id' => TypeDiete::where('code', 'DHS')->firstOrFail()->id,
            'user_id' => $this->user->id,
            'debut' => now()->subDays(2)->toDateString(),
        ]);

        $facture = app(FacturationService::class)->creerFactureHospitalisation($visite->fresh());

        $ligneSejour = $facture->lignes->firstWhere('type', 'hospitalisation');
        $ligneDiete = $facture->lignes->firstWhere('type', 'diete');

        $this->assertGreaterThan(0, (float) $ligneSejour->total_ligne, 'Les journées restent facturées.');
        $this->assertSame(0.0, (float) $ligneDiete->total_ligne, 'La diète est prise par le forfait.');
        $this->assertStringContainsString('inclus au forfait', $ligneDiete->libelle);
    }

    public function test_un_forfait_cesse_de_couvrir_au_dela_des_journees_incluses(): void
    {
        $visite = $this->sejour();
        $visite->update(['date_entree' => now()->subDays(10)]);
        $visite->refresh();

        $forfait = $this->forfait('global', [], 5);
        app(ForfaitService::class)->appliquer($visite, $forfait);

        $this->assertFalse($forfait->couvreEncore($visite));
        $this->assertFalse(app(ForfaitService::class)->couvre($visite->fresh(), 'hospitalisation'));

        $facture = app(FacturationService::class)->creerFactureHospitalisation($visite->fresh());

        $this->assertGreaterThan(0, (float) $facture->total_ttc,
            'Au-delà des journées incluses, tout redevient facturé à l\'acte.');
    }

    public function test_un_forfait_partiel_sans_categorie_est_refuse(): void
    {
        $this->post(route('forfaits.store'), [
            'code' => 'VIDE', 'libelle' => 'Forfait sans rien',
            'portee' => 'partiel', 'montant' => 1000, 'devise' => 'CDF',
        ])->assertSessionHas('error');

        $this->assertNull(Forfait::where('code', 'VIDE')->first());
    }

    public function test_un_sejour_ne_porte_quun_seul_forfait(): void
    {
        $visite = $this->sejour();
        $premier = $this->forfait('global');
        $second = $this->forfait('partiel', ['diete']);

        $this->post(route('forfaits.appliquer', $visite), ['forfait_id' => $premier->id]);
        $this->post(route('forfaits.appliquer', $visite), ['forfait_id' => $second->id])
            ->assertSessionHas('error');

        $this->assertSame($premier->id, $visite->fresh()->forfait_id);
    }

    public function test_un_forfait_reserve_a_une_societe_nest_propose_quaux_affilies(): void
    {
        $assurance = $this->assureur();

        $reserve = $this->forfait('global');
        $reserve->update(['assurance_id' => $assurance->id, 'code' => 'SONAS-G']);

        $visite = $this->sejour();
        $service = app(ForfaitService::class);

        $this->assertFalse($service->disponiblesPour($visite)->contains('id', $reserve->id));

        $this->patient->update(['type_prise_en_charge' => 'assurance']);
        PatientAssurance::create([
            'patient_id' => $this->patient->id,
            'assurance_id' => $assurance->id,
            'numero_police' => 'SN-1',
            'annee_courante' => (int) now()->format('Y'),
            'est_actif' => true,
        ]);

        $this->assertTrue($service->disponiblesPour($visite->fresh())->contains('id', $reserve->id));
    }

    // ═══════════════════════════════════════════════════════════
    // Disponibilité des médecins
    // ═══════════════════════════════════════════════════════════

    protected function medecin(string $nom, ?string $specialite): User
    {
        $medecin = User::create([
            'establishment_id' => $this->etab->id,
            'nom' => $nom, 'prenom' => 'Dr',
            'login' => strtolower($nom), 'email' => strtolower($nom).'@dpi-rdc.local',
            'password' => bcrypt('secret'), 'specialite' => $specialite, 'is_active' => true,
        ]);
        $medecin->assignRole('medecin');

        return $medecin;
    }

    public function test_un_medecin_hors_de_ses_plages_nest_pas_disponible(): void
    {
        $medecin = $this->medecin('NGALA', 'Cardiologie');

        DisponibiliteMedecin::create([
            'user_id' => $medecin->id,
            'jour_semaine' => 1, // lundi
            'heure_debut' => '08:00', 'heure_fin' => '12:00',
            'is_active' => true,
        ]);

        $service = app(DisponibiliteService::class);
        $lundi = now()->startOfWeek()->toDateString();

        $this->assertTrue($service->medecinsDisponibles('Cardiologie', $lundi, '09:00')->contains('id', $medecin->id));
        $this->assertFalse($service->medecinsDisponibles('Cardiologie', $lundi, '15:00')->contains('id', $medecin->id));

        $mardi = now()->startOfWeek()->addDay()->toDateString();
        $this->assertFalse($service->medecinsDisponibles('Cardiologie', $mardi, '09:00')->contains('id', $medecin->id));
    }

    public function test_une_absence_retire_le_medecin_meme_sur_sa_plage(): void
    {
        $medecin = $this->medecin('LUKAU', 'Neurologie');

        DisponibiliteMedecin::create([
            'user_id' => $medecin->id, 'jour_semaine' => 1,
            'heure_debut' => '08:00', 'heure_fin' => '18:00', 'is_active' => true,
        ]);

        $lundi = now()->startOfWeek()->toDateString();
        $service = app(DisponibiliteService::class);

        $this->assertTrue($service->medecinsDisponibles('Neurologie', $lundi, '09:00')->contains('id', $medecin->id));

        $this->post(route('disponibilites.absence'), [
            'user_id' => $medecin->id,
            'debut' => $lundi, 'fin' => $lundi, 'motif' => 'Mission',
        ])->assertSessionHas('success');

        $this->assertFalse(
            app(DisponibiliteService::class)->medecinsDisponibles('Neurologie', $lundi, '09:00')->contains('id', $medecin->id)
        );
    }

    public function test_une_specialite_sans_medecin_est_signalee_a_laccueil(): void
    {
        $type = TypeConsultation::where('code', 'CONS-CARDIO')->firstOrFail();

        $avertissement = app(DisponibiliteService::class)->avertissementPour($type);

        $this->assertNotNull($avertissement);
        $this->assertStringContainsString('Aucun médecin', $avertissement);
    }

    public function test_deux_plages_qui_se_chevauchent_sont_refusees(): void
    {
        $medecin = $this->medecin('KIMBALA', 'ORL');

        $this->post(route('disponibilites.store'), [
            'user_id' => $medecin->id, 'jour_semaine' => 2,
            'heure_debut' => '08:00', 'heure_fin' => '12:00',
        ])->assertSessionHas('success');

        $this->post(route('disponibilites.store'), [
            'user_id' => $medecin->id, 'jour_semaine' => 2,
            'heure_debut' => '11:00', 'heure_fin' => '15:00',
        ])->assertSessionHas('error');

        $this->assertSame(1, DisponibiliteMedecin::where('user_id', $medecin->id)->count());
    }

    public function test_lecran_de_disponibilite_repond(): void
    {
        $this->get(route('disponibilites.index'))
            ->assertOk()
            ->assertSee('Couverture par spécialité')
            ->assertSee('Cardiologie');
    }

    // ═══════════════════════════════════════════════════════════
    // File d'attente
    // ═══════════════════════════════════════════════════════════

    protected function visiteConsultation(?string $codeType = 'CONS-MG'): Visit
    {
        return Visit::create([
            'patient_id' => $this->patient->id,
            'establishment_id' => $this->etab->id,
            'user_id' => $this->user->id,
            'type' => 'consultation_externe',
            'type_consultation_id' => $codeType ? TypeConsultation::where('code', $codeType)->firstOrFail()->id : null,
            'statut' => 'en_cours',
            'date_entree' => now(),
            'gratuite' => true,
            'motif_consultation' => 'Contrôle',
        ]);
    }

    public function test_un_patient_des_urgences_ne_figure_pas_dans_la_file_des_consultations(): void
    {
        $this->visiteConsultation();

        $urgence = Visit::create([
            'patient_id' => Patient::create([
                'establishment_id' => $this->etab->id,
                'dossier_number' => 'PAT-2026-005501',
                'nom' => 'BONDO', 'prenom' => 'Sylvie', 'sexe' => 'F',
                'date_naissance' => now()->subYears(30)->toDateString(),
                'type_prise_en_charge' => 'prive',
            ])->id,
            'establishment_id' => $this->etab->id,
            'user_id' => $this->user->id,
            'type' => 'urgence',
            'statut' => 'en_cours',
            'date_entree' => now(),
            'gratuite' => true,
            'motif_consultation' => 'Plaie profonde',
        ]);

        $html = $this->get(route('consultations.index'))->assertOk()->getContent();

        $this->assertStringContainsString('MBALA', $html, 'La consultation externe reste dans la file.');
        $this->assertStringNotContainsString('BONDO', $html, 'Le patient des urgences n\'y figure pas.');

        // … mais il est bien dans la file des urgences.
        $this->get(route('urgences.index'))->assertOk()->assertSee('BONDO');
        $this->assertSame('urgence', $urgence->type);
    }

    public function test_un_patient_entre_au_cabinet_quitte_la_file_dattente(): void
    {
        $visite = $this->visiteConsultation();

        $this->assertStringContainsString('MBALA', $this->get(route('consultations.index'))->getContent());

        $this->get(route('visites.consulter', $visite))->assertOk();

        $visite->refresh();
        $this->assertNotNull($visite->consultation_debutee_at);
        $this->assertSame($this->user->id, $visite->consultation_par);
        $this->assertTrue($visite->estAuCabinet());

        $html = $this->get(route('consultations.index'))->assertOk()->getContent();
        $this->assertStringContainsString('Au cabinet', $html);
    }

    public function test_un_confrere_ne_peut_pas_reprendre_un_patient_deja_au_cabinet(): void
    {
        $visite = $this->visiteConsultation();
        $this->get(route('visites.consulter', $visite))->assertOk();

        $autre = $this->medecin('MPUTU', null);
        $autre->givePermissionTo('consultation.create');

        $this->actingAs($autre)
            ->get(route('visites.consulter', $visite))
            ->assertRedirect(route('consultations.index'))
            ->assertSessionHas('error');
    }

    public function test_le_medecin_peut_remettre_le_patient_en_file(): void
    {
        $visite = $this->visiteConsultation();
        $this->get(route('visites.consulter', $visite))->assertOk();

        $this->post(route('visites.liberer', $visite))->assertSessionHas('success');

        $visite->refresh();
        $this->assertNull($visite->consultation_debutee_at);
        $this->assertNull($visite->consultation_par);
        $this->assertFalse($visite->estAuCabinet());
    }

    public function test_la_file_porte_le_type_de_consultation_pour_permettre_le_filtrage(): void
    {
        $this->visiteConsultation('CONS-CARDIO');

        $this->get(route('consultations.index'))
            ->assertOk()
            ->assertSee('Cardiologie');
    }

    // ═══════════════════════════════════════════════════════════
    // Onglet Hospitalisation
    // ═══════════════════════════════════════════════════════════

    public function test_lecran_hospitalisation_ne_montre_que_les_sejours_et_les_lits(): void
    {
        $this->sejour();
        $this->visiteConsultation();

        $html = $this->get(route('visites.index'))->assertOk()->getContent();

        $this->assertStringContainsString('Occupation des lits', $html);
        $this->assertStringContainsString('MBALA', $html);
        $this->assertStringNotContainsString('Consultation externe', $html);
        $this->assertStringNotContainsString('consultation_externe', $html);
    }

    public function test_la_consultation_nallume_pas_longlet_hospitalisation(): void
    {
        $visite = $this->visiteConsultation();

        $html = $this->get(route('visites.consulter', $visite))->assertOk()->getContent();

        $hospitalisation = strpos($html, 'Hospitalisation<span');
        $this->assertNotFalse($hospitalisation);
        $this->assertStringNotContainsString('est-actif', substr($html, $hospitalisation - 160, 160));
    }
}
