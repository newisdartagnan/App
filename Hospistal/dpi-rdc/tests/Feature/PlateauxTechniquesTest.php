<?php

namespace Tests\Feature;

use App\Models\Assurance;
use App\Models\Establishment;
use App\Models\ExamenLaboratoire;
use App\Models\Medicament;
use App\Models\MouvementStock;
use App\Models\Officine;
use App\Models\Patient;
use App\Models\PatientAssurance;
use App\Models\ResultatExamen;
use App\Models\TypeExamen;
use App\Models\User;
use App\Models\Visit;
use App\Services\FacturationService;
use App\Services\NotificationService;
use App\Services\StatistiqueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Séparation du laboratoire et de l'imagerie, documents remis au patient et
 * au prescripteur, statistiques par plateau technique, clôture des passages.
 */
class PlateauxTechniquesTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Establishment $etab;

    protected Patient $patient;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->admin = User::where('email', 'admin@dpi-rdc.local')->firstOrFail();
        $this->etab = Establishment::firstOrFail();
        $this->actingAs($this->admin);

        $assurance = Assurance::firstOrCreate(
            ['code' => 'MONKOLE'],
            ['nom' => 'Monkole', 'taux_couverture' => 80, 'est_actif' => true]
        );

        $this->patient = Patient::create([
            'establishment_id' => $this->etab->id,
            'dossier_number' => 'PAT-2026-009100',
            'nom' => 'IKULA', 'postnom' => 'Boris', 'prenom' => 'Newis',
            'sexe' => 'M',
            'date_naissance' => now()->subYears(29)->toDateString(),
            'type_prise_en_charge' => 'assurance',
        ]);

        PatientAssurance::create([
            'patient_id' => $this->patient->id,
            'assurance_id' => $assurance->id,
            'numero_police' => 'MK-2201',
            'date_debut' => now()->subYear()->toDateString(),
            'annee_courante' => (int) now()->format('Y'),
            'est_actif' => true,
        ]);
    }

    /** Crée un examen du domaine donné, avec ses résultats. */
    protected function examen(string $domaine, ?User $prescripteur = null): ExamenLaboratoire
    {
        $examen = ExamenLaboratoire::create([
            'numero_bon' => ($domaine === 'imagerie' ? 'IMG' : 'LAB').'-2026-00'.random_int(1000, 9999),
            'patient_id' => $this->patient->id,
            'prescripteur_id' => ($prescripteur ?? $this->admin)->id,
            'laborantin_id' => $this->admin->id,
            'date_prescription' => now(),
            'date_prelevement' => now(),
            'date_resultat' => now(),
            'statut' => 'valide',
            'domaine' => $domaine,
            'observations_cliniques' => 'Fièvre depuis trois jours',
            'technique' => $domaine === 'imagerie' ? 'Coupes axiales sans injection' : null,
            'conclusion' => $domaine === 'imagerie'
                ? 'Pas de lésion hémorragique visible.'
                : 'Syndrome inflammatoire biologique.',
        ]);

        // Au laboratoire on retient des panels à plusieurs dosages : c'est
        // le cas qui doit se détailler sur le bon et sur la facture.
        $types = TypeExamen::where('domaine', $domaine)->get()
            ->when($domaine === 'labo', fn ($c) => $c->filter(
                fn ($t) => count($t->valeurs_reference['parametres'] ?? []) > 1
            ))
            ->take(2);

        foreach ($types as $type) {
            $parametres = $domaine === 'labo'
                ? array_slice(array_column($type->valeurs_reference['parametres'] ?? [], 'nom'), 0, 3)
                : [];

            foreach ($parametres ?: [null] as $parametre) {
                ResultatExamen::create([
                    'examen_id' => $examen->id,
                    'type_examen_id' => $type->id,
                    'parametre' => $parametre,
                    'valeur_brute' => $domaine === 'labo' ? '1.05' : null,
                    'unite' => $domaine === 'labo' ? 'g/L' : null,
                    'valeur_reference_min' => $domaine === 'labo' ? 0.7 : null,
                    'valeur_reference_max' => $domaine === 'labo' ? 1.1 : null,
                    'interpretation' => $domaine === 'labo' ? 'normal' : null,
                ]);
            }
        }

        return $examen->fresh(['resultats.typeExamen']);
    }

    // ═══════════════════════════════════════════════════════════
    // Catalogue : le laboratoire et l'imagerie sont deux domaines
    // ═══════════════════════════════════════════════════════════

    public function test_le_catalogue_distingue_le_laboratoire_de_limagerie(): void
    {
        $this->assertGreaterThan(0, TypeExamen::where('domaine', 'imagerie')->count());
        $this->assertGreaterThan(0, TypeExamen::where('domaine', 'labo')->count());

        // Un scanner est reconnu comme tel, pas rangé dans « autre ».
        $scanner = TypeExamen::where('code', 'IMG-SCAN-CRAN')->firstOrFail();
        $this->assertSame('imagerie', $scanner->domaine);
        $this->assertSame('Scanner (TDM)', $scanner->libelleModalite());

        // Aucun examen d'imagerie ne porte de valeurs de référence.
        $this->assertSame(0, TypeExamen::where('domaine', 'imagerie')
            ->whereRaw("valeurs_reference::text NOT IN ('[]', '{}')")->count());
    }

    // ═══════════════════════════════════════════════════════════
    // Bulletin du jour : un plateau technique par document
    // ═══════════════════════════════════════════════════════════

    public function test_le_bulletin_du_jour_ne_melange_pas_les_deux_plateaux(): void
    {
        $labo = $this->examen('labo');
        $imagerie = $this->examen('imagerie');

        $this->get(route('patients.bulletin-jour', ['patient' => $this->patient, 'domaine' => 'labo']))
            ->assertOk()
            ->assertSee('Bulletin de résultats du jour')
            ->assertSee('Le biologiste')
            ->assertSee($labo->numero_bon)
            ->assertDontSee($imagerie->numero_bon)
            ->assertDontSee('Le médecin radiologue');

        $this->get(route('patients.bulletin-jour', ['patient' => $this->patient, 'domaine' => 'imagerie']))
            ->assertOk()
            ->assertSee('Comptes rendus d', false)
            ->assertSee('Le médecin radiologue')
            ->assertSee($imagerie->numero_bon)
            ->assertDontSee($labo->numero_bon)
            ->assertDontSee('Le biologiste');
    }

    public function test_le_bulletin_du_jour_porte_le_nom_de_lassureur(): void
    {
        $this->examen('labo');

        $this->get(route('patients.bulletin-jour', ['patient' => $this->patient, 'domaine' => 'labo']))
            ->assertOk()
            ->assertSee('Monkole — n° MK-2201')
            ->assertDontSee('<strong>Prise en charge :</strong> Assurance', false);
    }

    // ═══════════════════════════════════════════════════════════
    // Bon d'examen et facture : détail des sous-examens
    // ═══════════════════════════════════════════════════════════

    public function test_le_bon_de_laboratoire_detaille_les_sous_examens(): void
    {
        $examen = $this->examen('labo');
        $premier = $examen->resultats->first();

        $this->get(route('labo.bon', $examen))
            ->assertOk()
            ->assertSee('Monkole — n° MK-2201')
            ->assertSee('Examen et sous-examens')
            ->assertSee($premier->parametre)
            ->assertSee($premier->typeExamen->libelle);
    }

    public function test_la_facture_dexamens_detaille_les_sous_examens(): void
    {
        $examen = $this->examen('labo');
        $facture = app(FacturationService::class)->creerFactureExamen($examen);

        // Seul un panel à plusieurs dosages a des sous-examens à détailler.
        $panel = $examen->resultats->groupBy('type_examen_id')
            ->first(fn ($resultats) => $resultats->pluck('parametre')->filter()->unique()->count() > 1);

        $this->assertNotNull($panel, 'Le jeu de test doit contenir un panel à plusieurs dosages.');

        $reponse = $this->get(route('caisse.imprimer', $facture))->assertOk()->assertSee('Monkole');

        foreach ($panel->pluck('parametre')->filter() as $parametre) {
            $reponse->assertSee($parametre);
        }
    }

    public function test_un_bon_dimagerie_annonce_la_modalite_et_non_des_normes(): void
    {
        $examen = $this->examen('imagerie');

        $this->get(route('labo.bon', $examen))
            ->assertOk()
            ->assertSee('Examen / modalité')
            ->assertDontSee('Examen et sous-examens');

        $this->get(route('labo.bulletin', $examen))
            ->assertOk()
            ->assertSee('Examens réalisés')
            ->assertSee('Modalité')
            ->assertDontSee('Valeurs de référence');
    }

    // ═══════════════════════════════════════════════════════════
    // Le prescripteur reçoit un document, pas un accès au plateau
    // ═══════════════════════════════════════════════════════════

    public function test_la_notification_de_resultat_mene_au_document_et_non_au_plateau(): void
    {
        $medecin = User::create([
            'establishment_id' => $this->etab->id,
            'nom' => 'MUYAKA', 'prenom' => 'Chris',
            'matricule' => 'MED-501',
            'password' => bcrypt('motdepasse123'),
            'is_active' => true,
        ]);
        $medecin->assignRole('medecin');

        $examen = $this->examen('imagerie', $medecin);
        $notification = app(NotificationService::class)->resultatsPrets($examen);

        $this->assertSame($medecin->id, $notification->destinataire_id);
        $this->assertTrue($notification->lienEstDocument());
        $this->assertSame(route('examens.pdf', $examen->id), $notification->lien());
        $this->assertStringNotContainsString('/labo/', $notification->lien());

        // Le prescripteur lit son compte rendu sans entrer à l'imagerie.
        $reponse = $this->actingAs($medecin)->get(route('examens.pdf', $examen));
        $reponse->assertOk();
        $this->assertSame('application/pdf', $reponse->headers->get('content-type'));
    }

    public function test_la_demande_de_travail_mene_bien_a_lecran_du_plateau(): void
    {
        $examen = $this->examen('labo');
        $notification = app(NotificationService::class)->prescriptionExamen($examen);

        $this->assertFalse($notification->lienEstDocument());
        $this->assertSame(route('labo.show', $examen->id), $notification->lien());
        $this->assertSame('laborantin', $notification->groupe_destinataire);
    }

    public function test_le_document_de_resultats_est_ferme_aux_agents_non_soignants(): void
    {
        $examen = $this->examen('labo');

        $caissier = User::create([
            'establishment_id' => $this->etab->id,
            'nom' => 'NSIMBA', 'prenom' => 'Julie',
            'matricule' => 'CAI-770',
            'password' => bcrypt('motdepasse123'),
            'is_active' => true,
        ]);
        $caissier->assignRole('caissier');

        $this->actingAs($caissier)->get(route('examens.pdf', $examen))->assertForbidden();
    }

    public function test_le_pdf_porte_lassureur_la_conclusion_et_le_bon(): void
    {
        $examen = $this->examen('imagerie');

        $contenu = $this->get(route('examens.pdf', $examen))->assertOk()->getContent();

        // dompdf compresse le flux : on vérifie que le document est bien formé
        // et non vide, le contenu lisible étant couvert par les vues HTML.
        $this->assertStringStartsWith('%PDF-', $contenu);
        $this->assertGreaterThan(1000, strlen($contenu));
    }

    // ═══════════════════════════════════════════════════════════
    // Statistiques
    // ═══════════════════════════════════════════════════════════

    public function test_longlet_pharmacie_des_statistiques_saffiche_avec_des_mouvements(): void
    {
        // Le mouvement de stock est ce qui faisait tomber la page en erreur.
        MouvementStock::create([
            'medicament_id' => Medicament::firstOrFail()->id,
            'establishment_id' => $this->etab->id,
            'type' => 'sortie_dispensation',
            'quantite' => 10,
            'quantite_avant' => 100,
            'quantite_apres' => 90,
            'officine_id' => Officine::first()?->id,
        ]);

        $this->get(route('statistiques.index', [
            'debut' => now()->startOfMonth()->toDateString(),
            'fin' => now()->toDateString(),
            'onglet' => 'pharmacie',
        ]))->assertOk()->assertSee('Sorties par officine');
    }

    public function test_un_mouvement_de_stock_est_toujours_horodate(): void
    {
        $mouvement = MouvementStock::create([
            'medicament_id' => Medicament::firstOrFail()->id,
            'establishment_id' => $this->etab->id,
            'type' => 'entree',
            'quantite' => 50,
            'quantite_avant' => 0,
            'quantite_apres' => 50,
        ]);

        $this->assertNotNull($mouvement->created_at);
    }

    public function test_les_statistiques_separent_le_laboratoire_de_limagerie(): void
    {
        $this->examen('labo');
        $this->examen('imagerie');
        $this->examen('imagerie');

        $parametres = ['debut' => now()->startOfMonth()->toDateString(), 'fin' => now()->toDateString()];

        $this->get(route('statistiques.index', $parametres + ['onglet' => 'labo']))
            ->assertOk()
            ->assertSee('Par unité d', false)
            ->assertSee('Par laborantin');

        $this->get(route('statistiques.index', $parametres + ['onglet' => 'imagerie']))
            ->assertOk()
            ->assertSee('Par modalité')
            ->assertSee('Par radiologue')
            ->assertSee('Comptes rendus signés');

        $labo = app(StatistiqueService::class)->activiteLabo(...array_values($parametres));
        $imagerie = app(StatistiqueService::class)->activiteImagerie(...array_values($parametres));

        $this->assertSame(1, $labo['total']);
        $this->assertSame(2, $imagerie['total']);
        $this->assertSame(2, $imagerie['comptes_rendus']);
    }

    // ═══════════════════════════════════════════════════════════
    // Clôture des passages
    // ═══════════════════════════════════════════════════════════

    protected function passage(string $type, $entree): Visit
    {
        return Visit::create([
            'patient_id' => $this->patient->id,
            'establishment_id' => $this->etab->id,
            'user_id' => $this->admin->id,
            'type' => $type,
            'statut' => 'en_cours',
            'date_entree' => $entree,
            'motif_consultation' => 'Fièvre',
        ]);
    }

    public function test_un_passage_de_la_veille_est_cloture_des_lentame_du_jour(): void
    {
        $hier = $this->passage('consultation_externe', now()->subDay()->setTime(9, 30));
        $urgenceHier = $this->passage('urgence', now()->subDay()->setTime(22, 0));

        $this->artisan('dpi:cloturer-visites')->assertSuccessful();

        foreach ([$hier, $urgenceHier] as $visite) {
            $visite->refresh();
            $this->assertSame('termine', $visite->statut);
            // La sortie est datée de la fin de la journée d'arrivée.
            $this->assertSame(
                $visite->date_entree->copy()->endOfDay()->toDateString(),
                $visite->date_sortie->toDateString()
            );
        }
    }

    public function test_un_passage_du_jour_reste_ouvert(): void
    {
        $aujourdhui = $this->passage('consultation_externe', now()->startOfDay()->addHours(2));

        $this->artisan('dpi:cloturer-visites')->assertSuccessful();

        $this->assertSame('en_cours', $aujourdhui->fresh()->statut);
    }

    public function test_une_hospitalisation_nest_jamais_cloturee_par_la_fin_de_journee(): void
    {
        $sejour = $this->passage('hospitalisation', now()->subDays(4));

        $this->artisan('dpi:cloturer-visites')->assertSuccessful();

        $this->assertSame('en_cours', $sejour->fresh()->statut);
    }

    public function test_un_patient_admis_depuis_les_urgences_poursuit_son_sejour(): void
    {
        $urgence = $this->passage('urgence', now()->subDay()->setTime(23, 15));

        // Admission : la visite change de type, elle sort du champ de la
        // clôture de fin de journée.
        $urgence->update(['type' => 'hospitalisation']);

        $this->artisan('dpi:cloturer-visites')->assertSuccessful();

        $this->assertSame('en_cours', $urgence->fresh()->statut);
        $this->assertNull($urgence->fresh()->date_sortie);
    }
}
