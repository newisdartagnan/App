<?php

namespace Tests\Feature;

use App\Models\DonneurSang;
use App\Models\Establishment;
use App\Models\ExamenLaboratoire;
use App\Models\Facture;
use App\Models\Patient;
use App\Models\User;
use App\Models\Visit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * La recherche depuis n'importe quel écran.
 *
 * On ne pouvait chercher un patient que depuis l'écran Patients : il fallait
 * quitter ce qu'on faisait, chercher, revenir. Et quand on a un papier en
 * main — une facture, un bon d'examen — sans savoir à qui il est, il n'y
 * avait rien du tout.
 */
class RechercheGlobaleTest extends TestCase
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

    protected function patient(string $nom, string $dossier, ?string $telephone = null): Patient
    {
        return Patient::create([
            'establishment_id' => $this->etab->id,
            'dossier_number' => $dossier,
            'nom' => $nom, 'prenom' => 'Espérance', 'sexe' => 'F',
            'date_naissance' => now()->subYears(30)->toDateString(),
            'telephone' => $telephone,
            'type_prise_en_charge' => 'prive',
        ]);
    }

    // ═══════════════════════════════════════════════════════════
    // Trouver un patient
    // ═══════════════════════════════════════════════════════════

    public function test_on_trouve_un_patient_par_son_nom(): void
    {
        $this->patient('KIBWANGA', 'REC-0001');
        $this->patient('MWEMBO', 'REC-0002');

        $this->get(route('recherche', ['q' => 'kibwanga']))
            ->assertOk()
            ->assertSee('KIBWANGA')
            ->assertDontSee('MWEMBO');
    }

    public function test_on_trouve_un_patient_par_son_prenom_ou_son_telephone(): void
    {
        $this->patient('NGOMA', 'REC-0003', '0815550001');

        $this->get(route('recherche', ['q' => 'Espérance']))->assertOk()->assertSee('NGOMA');
        $this->get(route('recherche', ['q' => '0815550001']))->assertOk()->assertSee('NGOMA');
    }

    public function test_le_numero_se_retrouve_quelle_que_soit_sa_forme(): void
    {
        $this->patient('APPELABLE', 'REC-0004', '0815550002');

        // On recopie un numéro comme on le trouve : sur une ordonnance, dans
        // un carnet, tel qu'il s'affiche sur le téléphone.
        foreach (['0815550002', '+243 81 555 0002', '243815550002', '81 555 00 02'] as $forme) {
            $this->get(route('recherche', ['q' => $forme]))
                ->assertOk()
                ->assertSee('APPELABLE');
        }
    }

    public function test_le_numero_dun_dossier_deja_ouvert_reste_cherchable(): void
    {
        // Le numéro change : l'empreinte doit suivre, sinon on retrouverait
        // encore le patient par l'ancien.
        $patient = $this->patient('CHANGEANT', 'REC-0005', '0815550003');
        $patient->update(['telephone' => '0997770003']);

        $this->get(route('recherche', ['q' => '0997770003']))->assertOk()->assertSee('CHANGEANT');
        $this->get(route('recherche', ['q' => '0815550003']))->assertOk()->assertSee('Rien ne correspond');
    }

    public function test_lecran_des_patients_retrouve_aussi_par_le_telephone(): void
    {
        // L'écran Patients promettait la recherche par téléphone depuis
        // toujours : le champ étant chiffré, aucun LIKE n'y répondait.
        $this->patient('JOIGNABLE', 'REC-0006', '0815550004');

        $this->get(route('patients.index', ['search' => '0815550004']))
            ->assertOk()
            ->assertSee('JOIGNABLE');
    }

    public function test_un_numero_de_dossier_exact_mene_droit_a_la_fiche(): void
    {
        $patient = $this->patient('DIRECT', 'REC-EXACT-01');

        // Un numéro désigne une seule personne : afficher une liste d'un
        // élément ferait perdre le geste qu'on vient de gagner.
        $this->get(route('recherche', ['q' => 'REC-EXACT-01']))
            ->assertRedirect(route('patients.show', $patient));
    }

    public function test_la_casse_du_numero_est_indifferente(): void
    {
        $patient = $this->patient('CASSE', 'REC-CASSE-01');

        $this->get(route('recherche', ['q' => 'rec-casse-01']))
            ->assertRedirect(route('patients.show', $patient));
    }

    // ═══════════════════════════════════════════════════════════
    // Le papier qu'on a en main
    // ═══════════════════════════════════════════════════════════

    public function test_un_numero_de_facture_mene_a_la_facture(): void
    {
        $patient = $this->patient('FACTURE', 'REC-0010');

        $visite = Visit::create([
            'patient_id' => $patient->id,
            'establishment_id' => $this->etab->id,
            'user_id' => $this->admin->id,
            'type' => 'consultation_externe', 'statut' => 'en_cours',
            'date_entree' => now(), 'motif_consultation' => 'Contrôle',
        ]);

        $facture = Facture::create([
            'patient_id' => $patient->id, 'visit_id' => $visite->id,
            'establishment_id' => $this->etab->id,
            'numero_facture' => 'FAC-REC-0001',
            'date_facture' => now(), 'statut' => 'emise',
            'type_prise_en_charge' => 'prive', 'devise' => 'CDF', 'taux_change' => 1,
            'total_ht' => 50000, 'total_ttc' => 50000,
            'patient_part' => 50000, 'assurance_part' => 0,
        ]);

        $this->get(route('recherche', ['q' => 'FAC-REC-0001']))
            ->assertRedirect(route('caisse.show', $facture));
    }

    public function test_un_numero_de_bon_mene_a_lexamen(): void
    {
        $patient = $this->patient('BON', 'REC-0011');

        $examen = ExamenLaboratoire::create([
            'numero_bon' => 'LAB-REC-0001',
            'patient_id' => $patient->id,
            'prescripteur_id' => $this->admin->id,
            'date_prescription' => now(),
            'statut' => 'prescrit', 'domaine' => 'labo',
        ]);

        $this->get(route('recherche', ['q' => 'LAB-REC-0001']))
            ->assertRedirect(route('labo.show', $examen));
    }

    public function test_un_fragment_de_numero_liste_au_lieu_de_rediriger(): void
    {
        $patient = $this->patient('FRAGMENT', 'REC-0012');

        ExamenLaboratoire::create([
            'numero_bon' => 'LAB-REC-0002',
            'patient_id' => $patient->id,
            'prescripteur_id' => $this->admin->id,
            'date_prescription' => now(),
            'statut' => 'prescrit', 'domaine' => 'labo',
        ]);

        // Un fragment peut désigner plusieurs choses : on liste.
        $this->get(route('recherche', ['q' => 'LAB-REC']))
            ->assertOk()
            ->assertSee('LAB-REC-0002');
    }

    // ═══════════════════════════════════════════════════════════
    // Les familles de résultats
    // ═══════════════════════════════════════════════════════════

    public function test_un_sejour_en_cours_apparait_sous_le_nom_du_patient(): void
    {
        $patient = $this->patient('HOSPITALISE', 'REC-0020');

        Visit::create([
            'patient_id' => $patient->id,
            'establishment_id' => $this->etab->id,
            'user_id' => $this->admin->id,
            'type' => 'hospitalisation', 'statut' => 'en_cours',
            'date_entree' => now()->subDays(2), 'motif_consultation' => 'Paludisme grave',
        ]);

        $this->get(route('recherche', ['q' => 'HOSPITALISE']))
            ->assertOk()
            ->assertSee('Patients')
            ->assertSee('Séjours en cours')
            ->assertSee('Hospitalisation');
    }

    public function test_les_examens_du_patient_remontent_avec_son_nom(): void
    {
        $patient = $this->patient('EXAMENS', 'REC-0021');

        ExamenLaboratoire::create([
            'numero_bon' => 'IMG-REC-0003',
            'patient_id' => $patient->id,
            'prescripteur_id' => $this->admin->id,
            'date_prescription' => now(),
            'statut' => 'valide', 'domaine' => 'imagerie',
        ]);

        $this->get(route('recherche', ['q' => 'EXAMENS']))
            ->assertOk()
            ->assertSee('IMG-REC-0003')
            ->assertSee('Imagerie');
    }

    // ═══════════════════════════════════════════════════════════
    // Ce que la recherche ne doit pas ouvrir
    // ═══════════════════════════════════════════════════════════

    public function test_la_recherche_nest_pas_la_porte_de_service(): void
    {
        $caissier = User::factory()->create([
            'establishment_id' => $this->etab->id,
            'nom' => 'ZORRO',
        ]);
        $caissier->assignRole('caissier');

        // Le caissier n'a pas accès aux comptes du personnel : la recherche
        // ne doit pas les lui montrer non plus.
        $this->actingAs($caissier)
            ->get(route('recherche', ['q' => 'ZORRO']))
            ->assertOk()
            ->assertDontSee('Comptes du personnel');

        $this->actingAs($this->admin)
            ->get(route('recherche', ['q' => 'ZORRO']))
            ->assertOk()
            ->assertSee('Comptes du personnel');
    }

    public function test_la_banque_de_sang_ne_sort_que_pour_ceux_qui_y_travaillent(): void
    {
        DonneurSang::create([
            'establishment_id' => $this->etab->id,
            'code' => 'DON-REC-0001',
            'nom' => 'MUKENDI', 'prenom' => 'Bienvenu', 'sexe' => 'M',
            'groupe_sanguin' => 'O+', 'type_donneur' => 'benevole',
        ]);

        $caissier = User::factory()->create(['establishment_id' => $this->etab->id]);
        $caissier->assignRole('caissier');

        // Le menu porte le mot « Banque de sang » pour tout le monde ;
        // c'est le contenu du registre qui ne doit pas sortir ici.
        $this->actingAs($caissier)
            ->get(route('recherche', ['q' => 'DON-REC']))
            ->assertOk()
            ->assertDontSee('MUKENDI');

        $this->actingAs($this->admin)
            ->get(route('recherche', ['q' => 'DON-REC']))
            ->assertOk()
            ->assertSee('MUKENDI');
    }

    // ═══════════════════════════════════════════════════════════
    // Les bords
    // ═══════════════════════════════════════════════════════════

    public function test_une_lettre_seule_ne_cherche_rien(): void
    {
        $this->patient('ABCDEF', 'REC-0030');

        $this->get(route('recherche', ['q' => 'A']))
            ->assertOk()
            ->assertSee('Deux lettres au moins')
            ->assertDontSee('ABCDEF');
    }

    public function test_une_recherche_vide_explique_ce_quon_peut_chercher(): void
    {
        $this->get(route('recherche'))
            ->assertOk()
            ->assertSee('numéro de dossier')
            ->assertSee('bon d');
    }

    public function test_une_recherche_sans_resultat_le_dit_clairement(): void
    {
        $this->get(route('recherche', ['q' => 'ZZZINTROUVABLE']))
            ->assertOk()
            ->assertSee('Rien ne correspond')
            ->assertSee('ZZZINTROUVABLE');
    }

    public function test_un_patient_fusionne_ne_ressort_plus(): void
    {
        $patient = $this->patient('DOUBLON', 'REC-0040');
        $patient->update(['merge_status' => 'merged']);

        $this->get(route('recherche', ['q' => 'DOUBLON']))
            ->assertOk()
            ->assertSee('Rien ne correspond');
    }

    // ═══════════════════════════════════════════════════════════
    // La barre de l'en-tête
    // ═══════════════════════════════════════════════════════════

    public function test_la_barre_est_sur_chaque_ecran(): void
    {
        foreach (['dashboard', 'patients.index', 'consultations.index', 'caisse.index'] as $route) {
            $this->get(route($route))
                ->assertOk()
                ->assertSee(route('recherche'), false)
                ->assertSee('q-entete', false);
        }
    }

    public function test_la_barre_reprend_le_terme_cherche(): void
    {
        $this->get(route('recherche', ['q' => 'KIBWANGA']))
            ->assertOk()
            ->assertSee('value="KIBWANGA"', false);
    }

    public function test_la_recherche_ne_depend_daucun_script(): void
    {
        $this->get(route('recherche', ['q' => 'test']))
            ->assertOk()
            ->assertDontSee('wire:', false)
            ->assertDontSee('onchange=', false);
    }
}
