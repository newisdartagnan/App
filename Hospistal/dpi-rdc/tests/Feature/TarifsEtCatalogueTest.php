<?php

namespace Tests\Feature;

use App\Models\Establishment;
use App\Models\Medicament;
use App\Models\NotificationInterne;
use App\Models\Officine;
use App\Models\Patient;
use App\Models\Service;
use App\Models\SoinPansement;
use App\Models\StockMedicament;
use App\Models\TransfertService;
use App\Models\TypeConsultation;
use App\Models\TypeDiete;
use App\Models\TypeExamen;
use App\Models\User;
use App\Models\Visit;
use App\Services\ParametreService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Ce que l'établissement doit pouvoir régler lui-même.
 *
 * Un hôpital qui ne peut pas relever le prix d'une consultation sans appeler
 * un informaticien n'a pas de tarification : il a une photographie. Et un
 * produit ajouté au catalogue sans voie ni conditionnement rend muet tout le
 * calcul de posologie construit pour le médecin.
 */
class TarifsEtCatalogueTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Establishment $etab;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->admin = User::where('email', 'admin@dpi-rdc.local')->firstOrFail();
        $this->etab = Establishment::firstOrFail();
        $this->actingAs($this->admin);
    }

    // ═══════════════════════════════════════════════════════════
    // Le prix des consultations suit le taux révisé
    // ═══════════════════════════════════════════════════════════

    public function test_le_prix_dune_consultation_suit_le_taux_du_paramétrage(): void
    {
        $type = TypeConsultation::where('est_actif', true)->firstOrFail();
        $type->update(['prix_usd' => 20]);

        app(ParametreService::class)->reviserTaux('USD', 3000, 'Test');

        // Sans cela, la direction relevait le taux et la consultation
        // continuait de se facturer à l'ancien cours.
        $this->assertSame(60000.0, $type->fresh()->prixCdf());

        app(ParametreService::class)->reviserTaux('USD', 3500, 'Nouvelle hausse');

        $this->assertSame(70000.0, $type->fresh()->prixCdf());
    }

    public function test_la_direction_revise_le_tarif_dune_consultation(): void
    {
        $type = TypeConsultation::firstOrFail();

        $this->post(route('tarifs.consultation', $type), ['prix_usd' => 27.5])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame('27.50', $type->fresh()->prix_usd);
    }

    public function test_un_tarif_de_consultation_ne_peut_etre_negatif(): void
    {
        $type = TypeConsultation::firstOrFail();
        $avant = $type->prix_usd;

        $this->post(route('tarifs.consultation', $type), ['prix_usd' => -5])
            ->assertSessionHasErrors('prix_usd');

        $this->assertSame($avant, $type->fresh()->prix_usd);
    }

    public function test_la_tarification_reste_a_la_direction(): void
    {
        $caissier = User::factory()->create(['establishment_id' => $this->etab->id]);
        $caissier->assignRole('caissier');

        $this->actingAs($caissier)
            ->post(route('tarifs.consultation', TypeConsultation::firstOrFail()), ['prix_usd' => 1])
            ->assertForbidden();
    }

    // ═══════════════════════════════════════════════════════════
    // Examens, produits, diètes
    // ═══════════════════════════════════════════════════════════

    public function test_le_tarif_dun_examen_et_son_delai_se_revisent(): void
    {
        $type = TypeExamen::firstOrFail();

        $this->post(route('tarifs.examen', $type), ['prix' => 18000, 'delai_heures' => 6])
            ->assertRedirect()->assertSessionHas('success');

        $type->refresh();

        $this->assertSame(18000.0, (float) $type->prix);
        $this->assertSame(6, $type->delai_heures);
    }

    public function test_le_prix_dun_produit_sapplique_a_toutes_les_officines(): void
    {
        $medicament = Medicament::firstOrFail();
        $officines = Officine::where('est_actif', true)->take(2)->get();

        foreach ($officines as $officine) {
            StockMedicament::updateOrCreate(
                ['medicament_id' => $medicament->id, 'officine_id' => $officine->id, 'lot' => null],
                ['establishment_id' => $this->etab->id, 'quantite_disponible' => 10, 'prix_unitaire_vente' => 1],
            );
        }

        $this->post(route('tarifs.medicament', $medicament), [
            'prix_unitaire_vente' => 75,
            'quantite_alerte' => 25,
        ])->assertRedirect();

        // Le patient ne doit pas payer selon le guichet où il passe.
        $prix = StockMedicament::where('medicament_id', $medicament->id)
            ->pluck('prix_unitaire_vente')->map(fn ($p) => (float) $p)->unique();

        $this->assertCount(1, $prix);
        $this->assertSame(75.0, $prix->first());
    }

    public function test_le_cout_journalier_dune_diete_se_revise(): void
    {
        $type = TypeDiete::firstOrFail();

        $this->post(route('tarifs.diete', $type), ['prix_journalier' => 9500])
            ->assertRedirect()->assertSessionHas('success');

        $this->assertSame(9500.0, (float) $type->fresh()->prix_journalier);
    }

    // ═══════════════════════════════════════════════════════════
    // Retirer du catalogue, sans rien effacer
    // ═══════════════════════════════════════════════════════════

    public function test_un_examen_retire_disparait_des_ecrans_de_prescription(): void
    {
        $type = TypeExamen::where('est_actif', true)->firstOrFail();

        $this->post(route('tarifs.basculer', ['examen', $type->id]))->assertRedirect();

        $this->assertFalse($type->fresh()->est_actif);
        $this->assertFalse(
            TypeExamen::where('est_actif', true)->where('id', $type->id)->exists()
        );
    }

    public function test_un_element_retire_se_remet_au_catalogue(): void
    {
        $medicament = Medicament::firstOrFail();

        $this->post(route('tarifs.basculer', ['medicament', $medicament->id]));
        $this->assertFalse($medicament->fresh()->est_actif);

        $this->post(route('tarifs.basculer', ['medicament', $medicament->id]));
        $this->assertTrue($medicament->fresh()->est_actif);
    }

    public function test_retirer_neffacce_rien(): void
    {
        $type = TypeDiete::firstOrFail();
        $libelle = $type->libelle;

        $this->post(route('tarifs.basculer', ['diete', $type->id]));

        // Le régime reste lisible : les factures qui le portent le nomment.
        $this->assertDatabaseHas('types_diete', ['id' => $type->id, 'libelle' => $libelle]);
    }

    public function test_lecran_des_tarifs_repond_sur_chaque_famille(): void
    {
        foreach (['consultations', 'examens', 'medicaments', 'dietes'] as $onglet) {
            $this->get(route('tarifs.index', ['onglet' => $onglet]))
                ->assertOk()
                ->assertSee('Tarifs et catalogues');
        }
    }

    // ═══════════════════════════════════════════════════════════
    // Le catalogue pharmacie n'écrivait pas ce qu'il fallait
    // ═══════════════════════════════════════════════════════════

    public function test_un_produit_ajoute_recoit_sa_voie_et_son_conditionnement(): void
    {
        $this->post(route('pharmacie.medicaments.store'), [
            'denomination_commune' => 'Ibuprofène',
            'forme' => 'comprime',
            'dosage' => '400 mg',
            'unite_dispensation' => 'comprimé',
            'prix_unitaire_vente' => 60,
        ])->assertRedirect();

        $medicament = Medicament::where('denomination_commune', 'Ibuprofène')->firstOrFail();

        // Sans ces trois-là, le médecin ne lit ni la voie ni le
        // conditionnement, et la quantité ne se déduit plus de la posologie.
        $this->assertSame('orale', $medicament->voie_administration);
        $this->assertSame('plaquette', $medicament->conditionnement);
        $this->assertSame(10, $medicament->unites_par_conditionnement);
        $this->assertStringContainsString('voie orale', $medicament->libelleComplet());
    }

    public function test_le_pharmacien_peut_corriger_le_conditionnement(): void
    {
        // Tous les comprimés ne vont pas par dix.
        $this->post(route('pharmacie.medicaments.store'), [
            'denomination_commune' => 'Zédoximine-Test',
            'forme' => 'comprime',
            'dosage' => '20/120 mg',
            'unite_dispensation' => 'comprimé',
            'prix_unitaire_vente' => 300,
            'conditionnement' => 'boite',
            'unites_par_conditionnement' => 24,
            'voie_administration' => 'orale',
        ])->assertRedirect();

        $medicament = Medicament::where('denomination_commune', 'Zédoximine-Test')->firstOrFail();

        $this->assertSame('boite', $medicament->conditionnement);
        $this->assertSame(24, $medicament->unites_par_conditionnement);
    }

    public function test_un_produit_ajoute_entre_au_depot_central(): void
    {
        $this->post(route('pharmacie.medicaments.store'), [
            'denomination_commune' => 'Bénzalcanide-Test',
            'forme' => 'comprime',
            'dosage' => '500 mg',
            'unite_dispensation' => 'comprimé',
            'prix_unitaire_vente' => 40,
            'quantite_initiale' => 200,
        ])->assertRedirect();

        $medicament = Medicament::where('denomination_commune', 'Bénzalcanide-Test')->firstOrFail();
        $depot = Officine::where('type', 'depot_central')->firstOrFail();
        $stock = StockMedicament::where('medicament_id', $medicament->id)->firstOrFail();

        // Un stock rattaché à aucune officine n'apparaît sur aucun écran.
        $this->assertSame($depot->id, $stock->officine_id);
        $this->assertSame(200.0, (float) $stock->quantite_disponible);
    }

    public function test_lentree_de_marchandise_est_tracee_sur_le_depot(): void
    {
        $this->post(route('pharmacie.medicaments.store'), [
            'denomination_commune' => 'Céfarotide-Test',
            'forme' => 'injectable',
            'dosage' => '1 g',
            'unite_dispensation' => 'flacon',
            'prix_unitaire_vente' => 5000,
            'quantite_initiale' => 50,
        ]);

        $medicament = Medicament::where('denomination_commune', 'Céfarotide-Test')->firstOrFail();
        $depot = Officine::where('type', 'depot_central')->firstOrFail();

        $this->assertDatabaseHas('mouvements_stock', [
            'medicament_id' => $medicament->id,
            'officine_id' => $depot->id,
            'type' => 'entree',
        ]);
        $this->assertSame('injectable', $medicament->voie_administration);
        $this->assertSame('flacon', $medicament->conditionnement);
    }

    public function test_la_reparation_a_rattache_les_stocks_orphelins(): void
    {
        $depot = Officine::where('type', 'depot_central')->firstOrFail();

        $this->assertSame(0, StockMedicament::whereNull('officine_id')->count());
        $this->assertSame(0, Medicament::whereNull('voie_administration')->count());
        $this->assertSame(0, Medicament::whereNull('conditionnement')->count());
        $this->assertTrue(StockMedicament::where('officine_id', $depot->id)->exists());
    }

    // ═══════════════════════════════════════════════════════════
    // Les notifications qui ne menaient nulle part
    // ═══════════════════════════════════════════════════════════

    protected function sejour(): Visit
    {
        $patient = Patient::create([
            'establishment_id' => $this->etab->id,
            'dossier_number' => 'PAT-2026-013000',
            'nom' => 'BOLENGE', 'prenom' => 'Alice', 'sexe' => 'F',
            'date_naissance' => now()->subYears(62)->toDateString(),
            'type_prise_en_charge' => 'prive',
        ]);

        return Visit::create([
            'patient_id' => $patient->id,
            'establishment_id' => $this->etab->id,
            'user_id' => $this->admin->id,
            'type' => 'hospitalisation',
            'statut' => 'en_cours',
            'date_entree' => now()->subDays(3),
            'service_id' => Service::value('id'),
            'motif_consultation' => 'Escarre sacrée',
        ]);
    }

    public function test_une_alerte_de_pansement_mene_au_dossier_du_patient(): void
    {
        $visite = $this->sejour();

        $pansement = SoinPansement::create([
            'visit_id' => $visite->id,
            'user_id' => $this->admin->id,
            'realise_a' => now(),
            'localisation' => 'Sacrum',
            'etat_plaie' => 'fibrineuse',
            'protocole' => 'Alginate',
        ]);

        $notification = NotificationInterne::create([
            'service' => 'hospitalisation',
            'type' => 'alerte_soins',
            'titre' => 'Escarre stade 3',
            'message' => 'Plaie sacrée, stade 3.',
            'reference_type' => 'pansement',
            'reference_id' => $pansement->id,
            'destinataire_id' => $this->admin->id,
        ]);

        // Le médecin recevait l'alerte sans aucun moyen d'atteindre le malade.
        $this->assertSame(
            route('services.dossier', [$visite->service_id, $visite->id]),
            $notification->lien()
        );
    }

    public function test_un_transfert_mene_au_dossier_du_service_daccueil(): void
    {
        $visite = $this->sejour();
        $destination = Service::where('id', '!=', $visite->service_id)->firstOrFail();

        $transfert = TransfertService::create([
            'visit_id' => $visite->id,
            'service_source_id' => $visite->service_id,
            'service_destination_id' => $destination->id,
            'demandeur_id' => $this->admin->id,
            'demandeur_nom' => $this->admin->nom_complet,
            'user_id' => $this->admin->id,
            'motif' => 'Aggravation',
            'transfere_a' => now(),
        ]);

        $notification = NotificationInterne::create([
            'service' => 'hospitalisation',
            'type' => 'transfert_service',
            'titre' => 'Patient transféré',
            'message' => 'Arrive du service précédent.',
            'reference_type' => 'transfert_service',
            'reference_id' => $transfert->id,
            'groupe_destinataire' => 'infirmier_chef',
        ]);

        $this->assertNotNull($notification->lien());
        $this->assertStringContainsString($visite->id, $notification->lien());
    }

    public function test_une_reference_disparue_ne_casse_pas_lecran(): void
    {
        $notification = NotificationInterne::create([
            'service' => 'hospitalisation',
            'type' => 'alerte_soins',
            'titre' => 'Alerte orpheline',
            'message' => 'Le soin a été supprimé.',
            'reference_type' => 'gavage',
            'reference_id' => (string) Str::uuid(),
            'destinataire_id' => $this->admin->id,
        ]);

        $this->assertNull($notification->lien());
        $this->get(route('notifications.index'))->assertOk();
    }

    public function test_chaque_service_qui_notifie_a_son_onglet(): void
    {
        // Un service oublié, et ses notifications se noient dans « toutes »
        // sans compteur ni filtre.
        foreach (['hospitalisation', 'banque_sang', 'labo', 'imagerie', 'pharmacie'] as $service) {
            $this->assertArrayHasKey($service, NotificationInterne::SERVICES);
            $this->assertTrue(NotificationInterne::estUnService($service));
        }

        $this->assertFalse(NotificationInterne::estUnService('toutes'));
    }

    public function test_longlet_dun_service_ne_montre_que_ses_notifications(): void
    {
        NotificationInterne::create([
            'service' => 'banque_sang', 'type' => 'poche_delivree',
            'titre' => 'Poche prête', 'message' => 'Poche PS-1 délivrée.',
            'destinataire_id' => $this->admin->id,
        ]);
        NotificationInterne::create([
            'service' => 'pharmacie', 'type' => 'medicament_delivre',
            'titre' => 'Ordonnance servie', 'message' => 'Produits remis.',
            'destinataire_id' => $this->admin->id,
        ]);

        $this->get(route('notifications.index', ['onglet' => 'banque_sang']))
            ->assertOk()
            ->assertSee('Poche prête')
            ->assertDontSee('Ordonnance servie');
    }

    // ═══════════════════════════════════════════════════════════
    // Le paramétrage se traverse d'un onglet à l'autre
    // ═══════════════════════════════════════════════════════════

    public function test_les_ecrans_de_parametrage_se_repondent(): void
    {
        foreach (['parametres.index', 'tarifs.index', 'utilisateurs.index', 'assurances.index', 'forfaits.index'] as $route) {
            $this->get(route($route))
                ->assertOk()
                ->assertSee('Tarifs et catalogues');
        }
    }

    public function test_toutes_les_routes_nommees_restent_atteignables(): void
    {
        $sources = '';

        foreach ([resource_path('views'), app_path()] as $racine) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($racine)) as $fichier) {
                if (str_ends_with((string) $fichier, '.php')) {
                    $sources .= file_get_contents((string) $fichier);
                }
            }
        }

        $orphelines = collect(app('router')->getRoutes()->getRoutesByName())
            ->filter(fn ($route) => str_starts_with(
                (string) ($route->getAction('controller') ?? ''),
                'App\\Http\\Controllers\\'
            ))
            ->keys()
            ->reject(fn (string $nom) => str_starts_with($nom, 'api.'))
            ->reject(fn (string $nom) => str_contains($sources, "'{$nom}'")
                || str_contains($sources, "\"{$nom}\""))
            ->values()
            ->all();

        $this->assertSame([], $orphelines,
            'Routes qu\'aucune vue ni aucun contrôleur n\'atteint : '.implode(', ', $orphelines));
    }
}
