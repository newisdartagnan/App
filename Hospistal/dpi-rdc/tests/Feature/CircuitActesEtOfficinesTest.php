<?php

namespace Tests\Feature;

use App\Models\ActeClinique;
use App\Models\Establishment;
use App\Models\Officine;
use App\Models\Patient;
use App\Models\PrescriptionDiete;
use App\Models\SalleOperation;
use App\Models\Service;
use App\Models\StockMedicament;
use App\Models\TypeDiete;
use App\Models\User;
use App\Models\Visit;
use App\Services\FacturationService;
use App\Services\OfficineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Le circuit d'un acte clinique, et le contrôle des officines.
 *
 * Un acte se demande, se programme, se réalise, puis se facture. Facturer
 * sans programmer ni clôturer laisserait payer un geste dont rien ne dit
 * qu'il a eu lieu : l'écran doit le montrer à chaque étape.
 */
class CircuitActesEtOfficinesTest extends TestCase
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
            'dossier_number' => 'PAT-2026-011000',
            'nom' => 'KALALA', 'prenom' => 'Denis', 'sexe' => 'M',
            'date_naissance' => now()->subYears(51)->toDateString(),
            'type_prise_en_charge' => 'prive',
        ]);

        $this->visite = Visit::create([
            'patient_id' => $this->patient->id,
            'establishment_id' => $this->etab->id,
            'user_id' => $this->admin->id,
            'type' => 'hospitalisation',
            'statut' => 'en_cours',
            'date_entree' => now()->subDays(2),
            'service_id' => Service::where('type', 'chirurgie')->value('id'),
            'motif_consultation' => 'Hernie inguinale',
        ]);
    }

    // ═══════════════════════════════════════════════════════════
    // La demande d'acte
    // ═══════════════════════════════════════════════════════════

    public function test_le_formulaire_de_demande_souvre_sans_sejour_prechoisi(): void
    {
        // Le bouton « nouvel acte » ne doit plus renvoyer aux admissions :
        // on choisit le patient sur place.
        $this->get(route('bloc.create'))
            ->assertOk()
            ->assertSee('Demande d')
            ->assertSee('intervention chirurgicale')
            ->assertSee('choisir un séjour ouvert')
            ->assertSee('KALALA');
    }

    public function test_le_formulaire_se_prefile_depuis_le_dossier_du_patient(): void
    {
        $this->get(route('bloc.create', ['visit_id' => $this->visite->id]))
            ->assertOk()
            ->assertSee('KALALA Denis')
            ->assertSee('Cure de hernie inguinale');
    }

    public function test_le_catalogue_des_actes_est_configurable(): void
    {
        $this->assertGreaterThan(10, count(config('dpi.actes.chirurgie')));
        $this->assertGreaterThan(10, count(config('dpi.actes.maternite')));

        $this->get(route('bloc.create'))->assertOk()->assertSee('Appendicectomie');
        $this->get(route('maternite.create'))->assertOk()->assertSee('Césarienne');
    }

    protected function demander(array $donnees = []): ActeClinique
    {
        $this->post(route('bloc.store'), [
            'visit_id' => $this->visite->id,
            'domaine' => 'chirurgie',
            'libelle' => 'Cure de hernie inguinale',
            'prix' => 350000,
            'duree_minutes' => 90,
            'diagnostic_preop' => 'Hernie inguinale droite',
            ...$donnees,
        ])->assertRedirect();

        return ActeClinique::where('domaine', 'chirurgie')->latest('created_at')->firstOrFail();
    }

    public function test_une_demande_nest_pas_un_programme(): void
    {
        $acte = $this->demander();

        // Ni salle, ni créneau, ni opérateur : ce n'est qu'une demande.
        $this->assertSame('prescrit', $acte->statut);
        $this->assertNull($acte->date_prevue);
        $this->assertNull($acte->salle_id);
        $this->assertNull($acte->operateur_id);
        $this->assertSame(90, $acte->duree_minutes);
        $this->assertSame('Hernie inguinale droite', $acte->diagnostic_preop);
        $this->assertSame($this->admin->id, $acte->demandeur_id);
        // Rien n'est facturé tant qu'on ne l'a pas demandé.
        $this->assertNull($acte->facture_id);
    }

    public function test_la_demande_renvoie_au_programme_du_bloc(): void
    {
        $this->post(route('bloc.store'), [
            'visit_id' => $this->visite->id,
            'domaine' => 'chirurgie',
            'libelle' => 'Appendicectomie',
            'prix' => 400000,
        ])->assertRedirect(route('bloc.programme'))
            ->assertSessionHas('success');

        $this->get(route('bloc.programme'))
            ->assertOk()
            ->assertSee('Appendicectomie')
            ->assertSee('KALALA');
    }

    public function test_le_bloc_peut_inscrire_lui_meme_une_demande(): void
    {
        $this->get(route('bloc.programme'))
            ->assertOk()
            ->assertSee('Nouvelle demande d')
            ->assertSee('choisir un séjour ouvert');
    }

    public function test_un_acte_facture_mais_non_programme_est_signale(): void
    {
        $acte = $this->demander(['facturer' => '1']);

        $this->assertNotNull($acte->facture_id);
        $this->assertSame('prescrit', $acte->statut);

        // Le dossier du patient doit le dire : payé ne veut pas dire fait.
        $this->get(route('services.dossier', [
            'service' => $this->visite->service_id,
            'visit' => $this->visite->id,
        ]))
            ->assertOk()
            ->assertSee('À programmer')
            ->assertSee('programmer au bloc');
    }

    public function test_le_circuit_complet_de_lacte_chirurgical(): void
    {
        $acte = $this->demander();
        $salle = SalleOperation::where('code', 'SOP-1')->firstOrFail();

        // 1. Programmation au bloc : salle, créneau, chirurgien.
        $this->post(route('bloc.planifier', $acte), [
            'salle_id' => $salle->id,
            'date_prevue' => '2026-09-10T09:00',
            'duree_minutes' => 90,
            'operateur_id' => $this->admin->id,
            'type_anesthesie' => 'generale',
            'consentement' => '1',
        ])->assertSessionHas('success');

        $acte->refresh();
        $this->assertSame('planifie', $acte->statut);
        $this->assertSame($salle->id, $acte->salle_id);

        // 2. Clôture : le compte rendu atteste de la réalisation.
        $this->post(route('bloc.cloturer', $acte), [
            'heure_entree_salle' => '2026-09-10T09:05',
            'heure_sortie_salle' => '2026-09-10T10:15',
            'compte_rendu' => 'Incision inguinale droite, réduction du sac, pose de plaque, fermeture.',
        ])->assertSessionHas('success');

        $acte->refresh();
        $this->assertSame('realise', $acte->statut);
        $this->assertSame(70, $acte->dureeReelleMinutes());

        // 3. Facturation, une fois l'acte réalisé.
        $this->post(route('actes.facturer', $acte))->assertRedirect();
        $this->assertNotNull($acte->fresh()->facture_id);

        // 4. Le registre en garde la trace.
        $this->get(route('bloc.registre', ['debut' => '2026-09-01', 'fin' => '2026-09-30']))
            ->assertOk()
            ->assertSee('KALALA')
            ->assertSee('Cure de hernie inguinale')
            ->assertSee('Salle 1');
    }

    public function test_un_examen_specialise_se_programme_sans_salle(): void
    {
        $this->post(route('examens-specialises.store'), [
            'visit_id' => $this->visite->id,
            'domaine' => 'examen_specialise',
            'libelle' => 'Examen spécialisé Cardiologie',
            'prix' => 60000,
        ])->assertRedirect();

        $acte = ActeClinique::where('domaine', 'examen_specialise')->firstOrFail();
        $this->assertSame('prescrit', $acte->statut);

        // Hors bloc, la programmation se fait sur place : date et opérateur.
        $this->post(route('actes.planifier', $acte), [
            'date_prevue' => '2026-09-12T10:00',
            'operateur_id' => $this->admin->id,
            'duree_minutes' => 30,
        ])->assertSessionHas('success');

        $this->assertSame('planifie', $acte->fresh()->statut);
    }

    public function test_un_sejour_termine_refuse_toute_nouvelle_demande(): void
    {
        $this->visite->update(['statut' => 'termine', 'date_sortie' => now()]);

        $this->post(route('bloc.store'), [
            'visit_id' => $this->visite->id,
            'domaine' => 'chirurgie',
            'libelle' => 'Appendicectomie',
            'prix' => 400000,
        ])->assertSessionHas('error');

        $this->assertSame(0, ActeClinique::where('domaine', 'chirurgie')->count());
    }

    // ═══════════════════════════════════════════════════════════
    // Diète facturée, ménage compris dans le séjour
    // ═══════════════════════════════════════════════════════════

    protected function prescrireDiete(): PrescriptionDiete
    {
        $type = TypeDiete::where('prix_journalier', '>', 0)->firstOrFail();

        return PrescriptionDiete::create([
            'visit_id' => $this->visite->id,
            'type_diete_id' => $type->id,
            'user_id' => $this->admin->id,
            'debut' => now()->subDays(2)->toDateString(),
        ]);
    }

    public function test_la_diete_est_portee_sur_la_facture_du_sejour(): void
    {
        $diete = $this->prescrireDiete();

        $facture = app(FacturationService::class)->creerFactureHospitalisation($this->visite->fresh());

        $ligne = $facture->lignes->firstWhere('type', 'diete');

        $this->assertNotNull($ligne, 'La diète servie doit figurer sur la facture.');
        $this->assertSame(3.0, (float) $ligne->quantite);
        $this->assertSame(
            (float) $diete->typeDiete->prix_journalier,
            (float) $ligne->prix_unitaire
        );
        $this->assertNotNull($diete->fresh()->facture_id);
    }

    public function test_une_diete_prescrite_apres_une_premiere_facture_est_facturee_aussi(): void
    {
        $facturation = app(FacturationService::class);

        // Le séjour est facturé une première fois, sans diète.
        $premiere = $facturation->creerFactureHospitalisation($this->visite->fresh());
        $this->assertNull($premiere->lignes->firstWhere('type', 'diete'));

        $this->prescrireDiete();

        // La diète prescrite ensuite ne se perd pas : elle part sur la suivante.
        $seconde = $facturation->creerFactureHospitalisation($this->visite->fresh());
        $this->assertNotNull($seconde);
        $this->assertNotNull($seconde->lignes->firstWhere('type', 'diete'));
    }

    public function test_le_menage_ne_se_facture_pas(): void
    {
        $this->prescrireDiete();
        $facture = app(FacturationService::class)->creerFactureHospitalisation($this->visite->fresh());

        // L'entretien de la chambre est compris dans le prix de la journée.
        $this->assertSame(
            ['hospitalisation', 'diete'],
            $facture->lignes->pluck('type')->unique()->values()->all()
        );

        $this->get(route('diete.index'))
            ->assertOk()
            ->assertSee('compris dans le prix de la journée');
    }

    // ═══════════════════════════════════════════════════════════
    // Contrôle des officines
    // ═══════════════════════════════════════════════════════════

    public function test_le_tableau_des_officines_montre_stocks_ruptures_et_requisitions(): void
    {
        $urgences = Officine::where('nom', 'Officine Urgences')->firstOrFail();

        // Une rupture au comptoir des urgences.
        $stock = StockMedicament::where('officine_id', $urgences->id)->firstOrFail();
        $stock->update(['quantite_disponible' => 0]);

        // Et une réquisition en attente au dépôt.
        app(OfficineService::class)->creerRequisition(
            $urgences,
            [$stock->medicament_id => 200],
            'Rupture au comptoir'
        );

        $this->get(route('officines.tableau'))
            ->assertOk()
            ->assertSee('Officine Urgences')
            ->assertSee('Dépôt central')
            ->assertSee('En rupture :')
            ->assertSee($stock->medicament->designation())
            ->assertSee('Réquisitions en attente au dépôt central')
            ->assertSee('Rupture au comptoir')
            // Le dépôt ne délivre pas aux patients, et l'écran le dit.
            ->assertSee('ne délivre pas aux patients');
    }

    public function test_chaque_officine_est_controlable_depuis_le_tableau(): void
    {
        $ambulatoire = Officine::where('type', 'ambulatoire')->firstOrFail();

        $this->post(route('officines.activer', $ambulatoire))
            ->assertRedirect(route('officines.stock'));

        $this->get(route('officines.stock'))
            ->assertOk()
            ->assertSee($ambulatoire->nom);
    }

    public function test_les_ecrans_de_pharmacie_partagent_la_meme_navigation(): void
    {
        foreach ([
            'officines.tableau', 'pharmacie.dashboard',
            'pharmacie.prescriptions', 'pharmacie.medicaments', 'officines.depot',
        ] as $route) {
            $this->get(route($route))
                ->assertOk()
                ->assertSee('Officines')
                ->assertSee('Dépôt central');
        }
    }
}
