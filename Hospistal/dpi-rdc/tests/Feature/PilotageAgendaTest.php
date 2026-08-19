<?php

namespace Tests\Feature;

use App\Models\Assurance;
use App\Models\Billetage;
use App\Models\BilanHydrique;
use App\Models\Establishment;
use App\Models\FactureConvention;
use App\Models\Paiement;
use App\Models\Patient;
use App\Models\PatientAssurance;
use App\Models\RendezVous;
use App\Models\TypeExamen;
use App\Models\User;
use App\Models\Visit;
use App\Services\AgendaService;
use App\Services\ConventionService;
use App\Services\FacturationService;
use App\Services\LaboratoireService;
use App\Services\StatistiqueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Facturation aux conventions et billetage, statistiques de pilotage,
 * agenda des rendez-vous, bilan hydrique.
 */
class PilotageAgendaTest extends TestCase
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
            'dossier_number' => 'PAT-2026-007700',
            'nom' => 'LUMBALA', 'postnom' => 'MWAMBA', 'prenom' => 'Chantal',
            'sexe' => 'F',
            'date_naissance' => now()->subYears(29)->toDateString(),
            'type_prise_en_charge' => 'assurance',
            'assurance_nom' => 'SONAS',
            'assurance_numero' => 'SN-4477',
        ]);
    }

    protected function visite(string $type = 'consultation_externe'): Visit
    {
        return Visit::create([
            'patient_id' => $this->patient->id,
            'establishment_id' => $this->etab->id,
            'user_id' => $this->user->id,
            'type' => $type,
            'statut' => 'en_cours',
            'date_entree' => now(),
            'motif_consultation' => 'Bilan',
        ]);
    }

    /** Produit une facture patient avec une part prise en charge. */
    protected function factureAvecTiersPayant(): \App\Models\Facture
    {
        $visite = $this->visite();
        $type = TypeExamen::where('est_actif', true)->firstOrFail();
        $examen = app(LaboratoireService::class)->prescrireExamens($visite, [$type->id], 'labo');

        return app(FacturationService::class)->creerFactureExamen($examen);
    }

    // ═══════════════════════════════════════════════════════════
    // Facturation des conventions
    // ═══════════════════════════════════════════════════════════

    public function test_une_facture_de_convention_regroupe_la_part_prise_en_charge(): void
    {
        $facture = $this->factureAvecTiersPayant();
        $this->assertGreaterThan(0, (float) $facture->assurance_part, 'Le tiers payant doit s\'appliquer.');

        $assurance = Assurance::where('nom', 'SONAS')->firstOrFail();
        $service = app(ConventionService::class);

        $aRefacturer = $service->facturesARefacturer($assurance, now()->toDateString(), now()->toDateString());
        $this->assertCount(1, $aRefacturer);

        $convention = $service->emettre($assurance, now()->toDateString(), now()->toDateString());

        $this->assertStringStartsWith('FCV-', $convention->numero);
        $this->assertSame('emise', $convention->statut);
        $this->assertCount(1, $convention->lignes);
        $this->assertEqualsWithDelta(
            (float) $facture->assurance_part,
            (float) $convention->montant_total,
            0.01
        );

        // Une facture déjà refacturée ne ressort plus
        $this->assertCount(0, $service->facturesARefacturer($assurance, now()->toDateString(), now()->toDateString()));
    }

    public function test_la_conversion_en_devise_applique_le_taux(): void
    {
        $facture = $this->factureAvecTiersPayant();
        $assurance = Assurance::where('nom', 'SONAS')->firstOrFail();

        $convention = app(ConventionService::class)->emettre(
            $assurance, now()->toDateString(), now()->toDateString(),
            'collective', 'USD', 2800
        );

        $this->assertSame('USD', $convention->devise);
        $this->assertEqualsWithDelta(
            (float) $facture->assurance_part / 2800,
            (float) $convention->montant_total,
            0.01
        );
    }

    public function test_reglement_partiel_puis_solde_de_la_facture_convention(): void
    {
        $this->factureAvecTiersPayant();
        $assurance = Assurance::where('nom', 'SONAS')->firstOrFail();
        $service = app(ConventionService::class);
        $convention = $service->emettre($assurance, now()->toDateString(), now()->toDateString());

        $moitie = round((float) $convention->montant_total / 2, 2);
        $service->enregistrerReglement($convention, $moitie, 'virement', 'VIR-001');

        $convention->refresh();
        $this->assertSame('partiellement_reglee', $convention->statut);
        $this->assertEqualsWithDelta($moitie, $convention->resteDu(), 0.02);

        $service->enregistrerReglement($convention->fresh(), $convention->resteDu());
        $this->assertSame('reglee', $convention->fresh()->statut);
        $this->assertTrue($convention->fresh()->estSoldee());
    }

    public function test_emettre_sans_facture_a_refacturer_est_refuse(): void
    {
        $assurance = Assurance::create(['code' => 'VIDE', 'nom' => 'Convention sans activité', 'taux_couverture' => 80, 'est_actif' => true]);

        $this->expectException(\RuntimeException::class);
        app(ConventionService::class)->emettre($assurance, now()->toDateString(), now()->toDateString());
    }

    public function test_les_pages_conventions_et_dettes_repondent(): void
    {
        $this->factureAvecTiersPayant();
        $assurance = Assurance::where('nom', 'SONAS')->firstOrFail();
        $convention = app(ConventionService::class)->emettre($assurance, now()->toDateString(), now()->toDateString());

        $this->get(route('conventions.index', ['assurance_id' => $assurance->id]))
            ->assertOk()->assertSee('Facturation société');

        $this->get(route('conventions.show', $convention))
            ->assertOk()
            ->assertSee($convention->numero)
            ->assertSee('LUMBALA MWAMBA Chantal');

        $this->get(route('conventions.imprimer', $convention))
            ->assertOk()->assertSee('Facture de convention');

        $this->get(route('conventions.dettes'))
            ->assertOk()->assertSee('Dettes à recouvrer')->assertSee($assurance->nom);
    }

    // ═══════════════════════════════════════════════════════════
    // Billetage
    // ═══════════════════════════════════════════════════════════

    public function test_le_billetage_totalise_les_coupures_et_calcule_l_ecart(): void
    {
        $facture = $this->factureAvecTiersPayant();
        Paiement::create([
            'facture_id' => $facture->id,
            'caissier_id' => $this->user->id,
            'date_paiement' => now(),
            'montant' => 15000,
            'mode_paiement' => 'especes',
            'recu_numero' => 'REC-TEST',
        ]);

        $billetage = app(ConventionService::class)->enregistrerBilletage(
            [20000 => 0, 10000 => 1, 5000 => 1, 1000 => 2],
            'CDF',
            now()->startOfDay()->toDateTimeString(),
            now()->endOfDay()->toDateTimeString(),
            'Clôture du jour'
        );

        // 10 000 + 5 000 + 2 × 1 000 = 17 000 comptés pour 15 000 encaissés
        $this->assertEquals(17000, (float) $billetage->total_compte);
        $this->assertEquals(15000, (float) $billetage->total_theorique);
        $this->assertEquals(2000, (float) $billetage->ecart);
        $this->assertTrue($billetage->ecartSignificatif());
        $this->assertSame(['10000' => 1, '5000' => 1, '1000' => 2], $billetage->coupures);
    }

    public function test_la_page_billetage_repond_et_enregistre(): void
    {
        $this->get(route('caisse.billetage'))
            ->assertOk()
            ->assertSee('Billetage')
            ->assertSee('20 000 CDF');

        $this->post(route('caisse.billetage.store'), [
            'devise' => 'CDF',
            'debut' => now()->startOfDay()->toDateTimeString(),
            'fin' => now()->endOfDay()->toDateTimeString(),
            'coupures' => [1000 => 5],
        ])->assertRedirect();

        $this->assertEquals(5000, (float) Billetage::firstOrFail()->total_compte);
    }

    // ═══════════════════════════════════════════════════════════
    // Statistiques de pilotage
    // ═══════════════════════════════════════════════════════════

    public function test_la_synthese_compte_les_admissions_et_l_occupation(): void
    {
        $this->visite();
        $this->visite('urgence');
        $sejour = $this->visite('hospitalisation');

        $lit = \App\Models\Lit::firstOrFail();
        $lit->update(['statut' => 'occupe']);
        $sejour->update(['lit_id' => $lit->id, 'service_id' => $lit->service_id]);

        $synthese = app(StatistiqueService::class)
            ->synthese(now()->toDateString(), now()->toDateString());

        $this->assertSame(3, $synthese['admissions']);
        $this->assertSame(1, $synthese['ambulatoires']);
        $this->assertSame(1, $synthese['urgences']);
        $this->assertSame(1, $synthese['hospitalisations']);
        $this->assertSame(1, $synthese['lits_occupes']);
        $this->assertGreaterThan(0, $synthese['taux_occupation']);
    }

    public function test_les_repartitions_croisent_sexe_age_et_prise_en_charge(): void
    {
        $this->visite();

        $repartitions = app(StatistiqueService::class)
            ->repartitions(now()->toDateString(), now()->toDateString());

        $this->assertSame(1, $repartitions['sexe']['Féminin']);
        $this->assertSame(1, $repartitions['age']['25-44 ans']);
        $this->assertSame(1, $repartitions['prise_en_charge']['SONAS']);
        $this->assertSame(1, $repartitions['type']['consultation_externe']);
    }

    public function test_les_jours_sans_activite_apparaissent_a_zero(): void
    {
        $this->visite();

        $serie = app(StatistiqueService::class)->admissionsParJour(
            now()->subDays(2)->toDateString(),
            now()->toDateString()
        );

        $this->assertCount(3, $serie);
        $this->assertSame(0, $serie[now()->subDays(2)->toDateString()]);
        $this->assertSame(1, $serie[now()->toDateString()]);
    }

    public function test_les_onglets_de_statistiques_repondent(): void
    {
        $this->visite();

        foreach (['activite', 'occupation', 'labo', 'pharmacie'] as $onglet) {
            $this->get(route('statistiques.index', ['onglet' => $onglet]))
                ->assertOk()
                ->assertSee('Statistiques de pilotage');
        }

        $this->get(route('statistiques.index', ['onglet' => 'occupation']))
            ->assertOk()->assertSee("Taux d'occupation", false);
    }

    // ═══════════════════════════════════════════════════════════
    // Agenda
    // ═══════════════════════════════════════════════════════════

    protected function medecin(): User
    {
        $medecin = User::create([
            'establishment_id' => $this->etab->id,
            'nom' => 'NGOMA', 'prenom' => 'Oscar',
            'email' => 'ngoma@dpi-rdc.local', 'matricule' => 'MED-010',
            'password' => bcrypt('secret'), 'is_active' => true,
        ]);
        $medecin->assignRole('medecin');

        return $medecin;
    }

    public function test_fixer_un_rendez_vous_et_refuser_le_chevauchement(): void
    {
        $medecin = $this->medecin();
        $agenda = app(AgendaService::class);
        $debut = now()->addDay()->setTime(9, 0);

        $rv = $agenda->fixer($this->patient, $medecin, $debut->toDateTimeString(), 30, null, '0810000000', 'Contrôle');

        $this->assertSame('fixe', $rv->statut);
        $this->assertSame('0810000000', $rv->contact);

        // Un second rendez-vous qui chevauche est refusé
        $this->expectException(\RuntimeException::class);
        $agenda->fixer($this->patient, $medecin, $debut->copy()->addMinutes(15)->toDateTimeString(), 30);
    }

    public function test_un_creneau_bloque_retire_la_disponibilite(): void
    {
        $medecin = $this->medecin();
        $agenda = app(AgendaService::class);
        $jour = now()->addDay()->toDateString();

        $avant = count($agenda->creneauxLibres($medecin, $jour));
        $this->assertGreaterThan(0, $avant);

        $agenda->bloquer($medecin, now()->addDay()->setTime(10, 0)->toDateTimeString(), 60, 'Réunion de service');

        $apres = count($agenda->creneauxLibres($medecin, $jour));
        $this->assertSame($avant - 2, $apres, 'Un blocage d\'une heure retire deux créneaux de 30 minutes.');
    }

    public function test_annuler_un_rendez_vous_libere_le_creneau(): void
    {
        $medecin = $this->medecin();
        $agenda = app(AgendaService::class);
        $debut = now()->addDay()->setTime(11, 0);

        $rv = $agenda->fixer($this->patient, $medecin, $debut->toDateTimeString(), 30);
        $this->assertFalse($agenda->creneauDisponible($medecin, $debut, 30));

        $agenda->annuler($rv, 'Patient injoignable');

        $this->assertSame('annule', $rv->fresh()->statut);
        $this->assertTrue($agenda->creneauDisponible($medecin, $debut, 30));
    }

    public function test_la_page_agenda_fixe_et_bloque_par_formulaire(): void
    {
        $medecin = $this->medecin();
        $jour = now()->addDay()->toDateString();

        $this->get(route('agenda.index', ['jour' => $jour, 'prestataire_id' => $medecin->id]))
            ->assertOk()
            ->assertSee('Créneaux libres')
            ->assertSee('Bloquer un créneau');

        $this->post(route('agenda.store'), [
            'dossier_number' => $this->patient->dossier_number,
            'prestataire_id' => $medecin->id,
            'debut' => now()->addDay()->setTime(14, 0)->format('Y-m-d\TH:i'),
            'duree_minutes' => 30,
            'motif' => 'Suivi tension',
        ])->assertRedirect();

        $this->assertSame(1, RendezVous::where('statut', 'fixe')->count());

        $this->post(route('agenda.bloquer'), [
            'prestataire_id' => $medecin->id,
            'debut' => now()->addDay()->setTime(16, 0)->format('Y-m-d\TH:i'),
            'duree_minutes' => 60,
            'motif' => 'Congé',
        ])->assertRedirect();

        $this->assertSame(1, RendezVous::where('statut', 'bloque')->count());

        $this->get(route('agenda.index', ['jour' => $jour, 'prestataire_id' => $medecin->id]))
            ->assertOk()
            ->assertSee('Suivi tension')
            ->assertSee('Congé');
    }

    public function test_un_dossier_inconnu_est_refuse(): void
    {
        $medecin = $this->medecin();

        $this->post(route('agenda.store'), [
            'dossier_number' => 'PAT-0000-000000',
            'prestataire_id' => $medecin->id,
            'debut' => now()->addDay()->setTime(15, 0)->format('Y-m-d\TH:i'),
            'duree_minutes' => 30,
        ])->assertSessionHasErrors('dossier_number');

        $this->assertSame(0, RendezVous::count());
    }

    // ═══════════════════════════════════════════════════════════
    // Bilan hydrique
    // ═══════════════════════════════════════════════════════════

    public function test_le_bilan_hydrique_calcule_la_balance_par_tranche(): void
    {
        $sejour = $this->visite('hospitalisation');

        $this->post(route('bilan-hydrique.store', $sejour), [
            'jour' => now()->toDateString(),
            'tranche' => 'matin',
            'perfusion' => 500, 'apport_iv' => 250, 'per_os' => 300,
            'urines' => 400, 'vomissements' => 100,
        ])->assertRedirect();

        $bilan = BilanHydrique::firstOrFail();

        $this->assertSame(1050, $bilan->totalEntrees());
        $this->assertSame(500, $bilan->totalSorties());
        $this->assertSame(550, $bilan->balance());
        $this->assertSame('Matin (6 h – 14 h)', $bilan->libelleTranche());
    }

    public function test_une_seconde_saisie_de_la_meme_tranche_met_a_jour(): void
    {
        $sejour = $this->visite('hospitalisation');
        $donnees = ['jour' => now()->toDateString(), 'tranche' => 'nuit', 'perfusion' => 500];

        $this->post(route('bilan-hydrique.store', $sejour), $donnees)->assertRedirect();
        $this->post(route('bilan-hydrique.store', $sejour), array_merge($donnees, ['perfusion' => 800]))->assertRedirect();

        $this->assertSame(1, BilanHydrique::count());
        $this->assertSame(800, BilanHydrique::firstOrFail()->perfusion);
    }

    public function test_la_page_bilan_hydrique_affiche_la_balance_du_jour(): void
    {
        $sejour = $this->visite('hospitalisation');

        BilanHydrique::create([
            'visit_id' => $sejour->id, 'user_id' => $this->user->id,
            'jour' => now()->toDateString(), 'tranche' => 'matin',
            'perfusion' => 2000, 'urines' => 200,
        ]);

        $this->get(route('bilan-hydrique.index', ['visit' => $sejour->id]))
            ->assertOk()
            ->assertSee('Bilan hydrique')
            ->assertSee('Balance du jour')
            ->assertSee('rétention hydrique');
    }

    public function test_un_sejour_termine_refuse_un_bilan(): void
    {
        $sejour = $this->visite('hospitalisation');
        $sejour->update(['statut' => 'termine', 'date_sortie' => now()]);

        $this->post(route('bilan-hydrique.store', $sejour), [
            'jour' => now()->toDateString(), 'tranche' => 'matin', 'perfusion' => 500,
        ])->assertSessionHas('error');

        $this->assertSame(0, BilanHydrique::count());
    }
}
