<?php

namespace Tests\Feature;

use App\Models\DisponibiliteMedecin;
use App\Models\Establishment;
use App\Models\Facture;
use App\Models\Lit;
use App\Models\NotificationInterne;
use App\Models\Patient;
use App\Models\Service;
use App\Models\TransfertService;
use App\Models\User;
use App\Models\Visit;
use App\Services\AcompteService;
use App\Services\DisponibiliteService;
use App\Services\FacturationService;
use Database\Seeders\DisponibiliteMedecinSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Transferts inter-services, mobilisation de l'acompte au guichet et
 * installation des plages de présence des médecins.
 */
class TransfertsAcomptesTest extends TestCase
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
            'dossier_number' => 'PAT-2026-006600',
            'nom' => 'ILUNGA', 'postnom' => 'WAMBA', 'prenom' => 'Célestin',
            'sexe' => 'M',
            'date_naissance' => now()->subYears(49)->toDateString(),
            'type_prise_en_charge' => 'prive',
        ]);
    }

    protected function sejourEn(string $code = 'REA'): Visit
    {
        $service = Service::where('code', $code)->firstOrFail();
        $lit = Lit::where('service_id', $service->id)->where('statut', 'libre')->firstOrFail();

        $visite = Visit::create([
            'patient_id' => $this->patient->id,
            'establishment_id' => $this->etab->id,
            'user_id' => $this->user->id,
            'type' => 'hospitalisation',
            'statut' => 'en_cours',
            'date_entree' => now()->subDays(2),
            'service_id' => $service->id,
            'lit_id' => $lit->id,
            'motif_consultation' => 'Détresse respiratoire',
        ]);

        $lit->update(['statut' => 'occupe']);

        return $visite;
    }

    // ═══════════════════════════════════════════════════════════
    // Transfert inter-services
    // ═══════════════════════════════════════════════════════════

    public function test_un_transfert_change_de_service_sans_clore_le_sejour(): void
    {
        $visite = $this->sejourEn('REA');
        $litDepart = $visite->lit;
        $medecine = Service::where('code', 'MED')->firstOrFail();
        $litArrivee = Lit::where('service_id', $medecine->id)->where('statut', 'libre')->firstOrFail();

        $this->post(route('transferts.store', $visite), [
            'service_destination_id' => $medecine->id,
            'lit_destination_id' => $litArrivee->id,
            'demandeur_id' => $this->user->id,
            'motif' => 'État stabilisé, poursuite des soins en médecine interne',
        ])->assertSessionHas('success');

        $visite->refresh();

        // Le séjour est le même : même admission, même date d'entrée.
        $this->assertSame('en_cours', $visite->statut);
        $this->assertNull($visite->date_sortie);
        $this->assertNull($visite->mode_sortie);
        $this->assertTrue($visite->peutRecevoirServices());

        // Seuls le service et le lit ont changé.
        $this->assertSame($medecine->id, $visite->service_id);
        $this->assertSame($litArrivee->id, $visite->lit_id);
        $this->assertSame('libre', $litDepart->fresh()->statut, 'Le lit de départ est rendu.');
        $this->assertSame('occupe', $litArrivee->fresh()->statut);

        $transfert = TransfertService::where('visit_id', $visite->id)->firstOrFail();

        $this->assertSame($this->user->id, $transfert->demandeur_id);
        $this->assertSame($this->user->nom_complet, $transfert->demandeur_nom);
        $this->assertStringContainsString('médecine interne', $transfert->motif);
        $this->assertStringContainsString('Réanimation', $transfert->trajet());
        $this->assertStringContainsString('Médecine interne', $transfert->trajet());
    }

    public function test_le_demandeur_peut_etre_un_praticien_hors_application(): void
    {
        $visite = $this->sejourEn('REA');
        $medecine = Service::where('code', 'MED')->firstOrFail();

        $this->post(route('transferts.store', $visite), [
            'service_destination_id' => $medecine->id,
            'demandeur_nom' => 'Dr NGOY, chef de service',
            'motif' => 'Demande du staff du matin',
        ])->assertSessionHas('success');

        $transfert = TransfertService::where('visit_id', $visite->id)->firstOrFail();

        $this->assertNull($transfert->demandeur_id);
        $this->assertSame('Dr NGOY, chef de service', $transfert->demandeur_nom);
        $this->assertSame($this->user->id, $transfert->user_id, 'L\'agent qui saisit est tracé à part.');
    }

    public function test_le_motif_et_le_demandeur_sont_obligatoires(): void
    {
        $visite = $this->sejourEn('REA');
        $medecine = Service::where('code', 'MED')->firstOrFail();

        $this->post(route('transferts.store', $visite), [
            'service_destination_id' => $medecine->id,
        ])->assertSessionHasErrors(['demandeur_nom', 'motif']);

        $this->assertSame(0, TransfertService::where('visit_id', $visite->id)->count());
    }

    public function test_un_transfert_vers_le_meme_service_est_refuse(): void
    {
        $visite = $this->sejourEn('REA');

        $this->post(route('transferts.store', $visite), [
            'service_destination_id' => $visite->service_id,
            'demandeur_nom' => 'Dr NGOY',
            'motif' => 'Test',
        ])->assertSessionHas('error');

        $this->assertSame(0, TransfertService::where('visit_id', $visite->id)->count());
    }

    public function test_un_lit_deja_occupe_est_refuse(): void
    {
        $visite = $this->sejourEn('REA');
        $medecine = Service::where('code', 'MED')->firstOrFail();
        $lit = Lit::where('service_id', $medecine->id)->where('statut', 'libre')->firstOrFail();
        $lit->update(['statut' => 'occupe']);

        $this->post(route('transferts.store', $visite), [
            'service_destination_id' => $medecine->id,
            'lit_destination_id' => $lit->id,
            'demandeur_nom' => 'Dr NGOY',
            'motif' => 'Test',
        ])->assertSessionHas('error');

        $this->assertSame($visite->service_id, $visite->fresh()->service_id);
    }

    public function test_un_sejour_termine_ne_se_transfere_plus(): void
    {
        $visite = $this->sejourEn('REA');
        $visite->update(['statut' => 'termine']);

        $this->post(route('transferts.store', $visite), [
            'service_destination_id' => Service::where('code', 'MED')->firstOrFail()->id,
            'demandeur_nom' => 'Dr NGOY',
            'motif' => 'Test',
        ])->assertSessionHas('error');
    }

    public function test_le_service_qui_recoit_est_prevenu(): void
    {
        $visite = $this->sejourEn('REA');
        $medecine = Service::where('code', 'MED')->firstOrFail();

        $this->post(route('transferts.store', $visite), [
            'service_destination_id' => $medecine->id,
            'demandeur_nom' => 'Dr NGOY',
            'motif' => 'Poursuite des soins',
        ])->assertSessionHas('success');

        $notification = NotificationInterne::where('reference_type', 'transfert_service')->firstOrFail();

        $this->assertSame('infirmier_chef', $notification->groupe_destinataire);
        $this->assertSame('haute', $notification->priorite);
        $this->assertStringContainsString('ILUNGA', $notification->message);
        $this->assertStringContainsString('Dr NGOY', $notification->message);
    }

    public function test_le_parcours_dans_lhopital_est_conserve(): void
    {
        $visite = $this->sejourEn('REA');
        $medecine = Service::where('code', 'MED')->firstOrFail();
        $pediatrie = Service::where('code', 'PED')->firstOrFail();

        $this->post(route('transferts.store', $visite), [
            'service_destination_id' => $medecine->id,
            'demandeur_nom' => 'Dr NGOY', 'motif' => 'Stabilisé',
        ]);
        $this->post(route('transferts.store', $visite->fresh()), [
            'service_destination_id' => $pediatrie->id,
            'demandeur_nom' => 'Dr KABEYA', 'motif' => 'Erreur d\'orientation',
        ]);

        $this->assertSame(2, TransfertService::where('visit_id', $visite->id)->count());

        $this->get(route('services.dossier', [$pediatrie, $visite->fresh()]))
            ->assertOk()
            ->assertSee('Parcours dans l')
            ->assertSee('Dr NGOY')
            ->assertSee('Dr KABEYA')
            ->assertSee('Transfert vers un autre service');
    }

    // ═══════════════════════════════════════════════════════════
    // Acompte mobilisable sur n'importe quelle facture
    // ═══════════════════════════════════════════════════════════

    public function test_lacompte_dun_sejour_regle_la_facture_dun_autre_passage(): void
    {
        // Avance laissée aux urgences.
        $urgence = Visit::create([
            'patient_id' => $this->patient->id,
            'establishment_id' => $this->etab->id,
            'user_id' => $this->user->id,
            'type' => 'urgence', 'statut' => 'termine',
            'date_entree' => now()->subDays(10),
            'motif_consultation' => 'Traumatisme',
        ]);

        app(AcompteService::class)->encaisser($urgence, 200000);

        $this->assertSame(200000.0, app(AcompteService::class)->soldePatient($this->patient->id));

        // Consultation externe facturée le lendemain.
        $consultation = Visit::create([
            'patient_id' => $this->patient->id,
            'establishment_id' => $this->etab->id,
            'user_id' => $this->user->id,
            'type' => 'consultation_externe', 'statut' => 'en_cours',
            'date_entree' => now(), 'motif_consultation' => 'Contrôle',
        ]);

        $facture = app(FacturationService::class)->creerFactureConsultation($consultation);

        // L'imputation automatique ne pioche pas dans l'avance d'un autre séjour.
        $this->assertSame(0.0, (float) $facture->fresh()->acompte_impute);

        // Le guichet, lui, peut la mobiliser.
        $this->get(route('caisse.show', $facture))
            ->assertOk()
            ->assertSee('dispose d', false)
            ->assertSee('acompte')
            ->assertSee('Utiliser l\'acompte', false);

        $this->post(route('caisse.acompte', $facture))->assertSessionHas('success');

        $facture->refresh();

        $this->assertGreaterThan(0, (float) $facture->acompte_impute);
        $this->assertSame('payee', $facture->statut, 'L\'avance suffit à solder la consultation.');
        $this->assertSame(0.0, $facture->soldeRestant());
        $this->assertSame(
            200000.0 - (float) $facture->patient_part,
            app(AcompteService::class)->soldePatient($this->patient->id)
        );
    }

    public function test_le_guichet_refuse_de_mobiliser_un_acompte_inexistant(): void
    {
        $visite = Visit::create([
            'patient_id' => $this->patient->id,
            'establishment_id' => $this->etab->id,
            'user_id' => $this->user->id,
            'type' => 'consultation_externe', 'statut' => 'en_cours',
            'date_entree' => now(), 'motif_consultation' => 'Contrôle',
        ]);

        $facture = app(FacturationService::class)->creerFactureConsultation($visite);

        $this->post(route('caisse.acompte', $facture))->assertSessionHas('error');

        $this->get(route('caisse.show', $facture))
            ->assertOk()
            ->assertDontSee('Utiliser l\'acompte', false);
    }

    public function test_une_facture_soldee_ne_consomme_plus_dacompte(): void
    {
        $visite = $this->sejourEn('MED');
        app(AcompteService::class)->encaisser($visite, 1000000);

        $facture = app(FacturationService::class)->creerFactureHospitalisation($visite);
        $this->assertSame('payee', $facture->fresh()->statut);

        $restant = app(AcompteService::class)->soldePatient($this->patient->id);

        $this->post(route('caisse.acompte', $facture))->assertSessionHas('info');

        $this->assertSame($restant, app(AcompteService::class)->soldePatient($this->patient->id));
    }

    public function test_limputation_est_tracee_sur_la_facture(): void
    {
        $visite = $this->sejourEn('MED');
        app(AcompteService::class)->encaisser($visite, 40000);

        $facture = app(FacturationService::class)->creerFactureHospitalisation($visite);

        $this->assertCount(1, $facture->imputations);
        $this->assertSame(40000.0, (float) $facture->imputations->first()->montant);

        $this->get(route('caisse.show', $facture))
            ->assertOk()
            ->assertSee('Acomptes imputés sur cette facture')
            ->assertSee('40 000');
    }

    // ═══════════════════════════════════════════════════════════
    // Facturation à effet unique
    // ═══════════════════════════════════════════════════════════

    public function test_refacturer_un_sejour_deja_facture_najoute_rien(): void
    {
        $visite = $this->sejourEn('MED');

        $this->post(route('visites.facturer-sejour', $visite))->assertSessionHas('success');
        $this->assertSame(1, Facture::where('visit_id', $visite->id)->count());

        $this->post(route('visites.facturer-sejour', $visite->fresh()))->assertSessionHas('info');
        $this->assertSame(1, Facture::where('visit_id', $visite->id)->count());

        $this->assertFalse(app(FacturationService::class)->resteAFacturer($visite->fresh()));
    }

    public function test_une_journee_supplementaire_redevient_facturable(): void
    {
        $visite = $this->sejourEn('MED');

        $this->post(route('visites.facturer-sejour', $visite));
        $this->assertFalse(app(FacturationService::class)->resteAFacturer($visite->fresh()));

        $visite->update(['date_entree' => now()->subDays(4)]);

        $this->assertTrue(app(FacturationService::class)->resteAFacturer($visite->fresh()));

        $this->post(route('visites.facturer-sejour', $visite->fresh()))->assertSessionHas('success');
        $this->assertSame(2, Facture::where('visit_id', $visite->id)->count());
    }

    // ═══════════════════════════════════════════════════════════
    // Plages de présence des médecins
    // ═══════════════════════════════════════════════════════════

    public function test_chaque_medecin_recoit_ses_plages_de_presence(): void
    {
        $medecin = User::create([
            'establishment_id' => $this->etab->id,
            'nom' => 'MWANZA', 'prenom' => 'Dr Joseph',
            'login' => 'mwanza', 'email' => 'mwanza@dpi-rdc.local',
            'password' => bcrypt('secret'), 'specialite' => 'Pédiatrie', 'is_active' => true,
        ]);
        $medecin->assignRole('medecin');

        $this->assertSame(0, DisponibiliteMedecin::where('user_id', $medecin->id)->count());

        $installees = DisponibiliteMedecinSeeder::installerPour($medecin);

        $this->assertSame(count(DisponibiliteMedecinSeeder::PLAGES_PAR_DEFAUT), $installees);
        $this->assertSame(11, DisponibiliteMedecin::where('user_id', $medecin->id)->count());

        // Semaine ouvrée matin et après-midi, samedi matin, dimanche libre.
        $jours = DisponibiliteMedecin::where('user_id', $medecin->id)
            ->pluck('jour_semaine')->unique()->sort()->values()->all();

        $this->assertSame([1, 2, 3, 4, 5, 6], $jours);
    }

    public function test_le_seeder_est_idempotent(): void
    {
        $medecin = User::create([
            'establishment_id' => $this->etab->id,
            'nom' => 'BOSEKO', 'prenom' => 'Dr Anne',
            'login' => 'boseko', 'email' => 'boseko@dpi-rdc.local',
            'password' => bcrypt('secret'), 'is_active' => true,
        ]);
        $medecin->assignRole('medecin');

        DisponibiliteMedecinSeeder::installerPour($medecin);
        $ajoutees = DisponibiliteMedecinSeeder::installerPour($medecin);

        $this->assertSame(0, $ajoutees, 'Un médecin déjà pourvu n\'est pas touché.');
        $this->assertSame(11, DisponibiliteMedecin::where('user_id', $medecin->id)->count());
    }

    public function test_les_plages_par_defaut_couvrent_les_heures_ouvrables(): void
    {
        $medecin = User::create([
            'establishment_id' => $this->etab->id,
            'nom' => 'TSHIBOLA', 'prenom' => 'Dr Paul',
            'login' => 'tshibola', 'email' => 'tshibola@dpi-rdc.local',
            'password' => bcrypt('secret'), 'specialite' => 'Cardiologie', 'is_active' => true,
        ]);
        $medecin->assignRole('medecin');
        DisponibiliteMedecinSeeder::installerPour($medecin);

        $service = app(DisponibiliteService::class);
        $lundi = now()->startOfWeek()->toDateString();
        $dimanche = now()->startOfWeek()->addDays(6)->toDateString();

        $this->assertTrue($service->medecinsDisponibles('Cardiologie', $lundi, '09:00')->contains('id', $medecin->id));
        $this->assertTrue($service->medecinsDisponibles('Cardiologie', $lundi, '15:00')->contains('id', $medecin->id));
        $this->assertFalse($service->medecinsDisponibles('Cardiologie', $lundi, '13:00')->contains('id', $medecin->id),
            'La pause de midi n\'est pas couverte.');
        $this->assertFalse($service->medecinsDisponibles('Cardiologie', $dimanche, '09:00')->contains('id', $medecin->id));
    }
}
