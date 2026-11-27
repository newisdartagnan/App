<?php

namespace Tests\Feature;

use App\Models\Establishment;
use App\Models\ExamenLaboratoire;
use App\Models\Patient;
use App\Models\ResultatExamen;
use App\Models\Service;
use App\Models\TypeExamen;
use App\Models\User;
use App\Models\Visit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Accès rapides, Mes tâches (avec saisie de résultat par Entrée) et la liste
 * des patients avec son dernier épisode et ses filtres.
 */
class TachesEtAccesRapidesTest extends TestCase
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

    protected function patient(string $nom, string $sexe = 'M', int $age = 30): Patient
    {
        return Patient::create([
            'establishment_id' => $this->etab->id,
            'dossier_number' => 'PAT-T-'.random_int(100000, 999999),
            'nom' => $nom, 'prenom' => 'Test', 'sexe' => $sexe,
            'date_naissance' => now()->subYears($age)->subDays(10)->toDateString(),
            'type_prise_en_charge' => 'prive',
        ]);
    }

    protected function visite(Patient $p, string $statut = 'en_cours', ?Service $service = null): Visit
    {
        return Visit::create([
            'patient_id' => $p->id,
            'establishment_id' => $this->etab->id,
            'user_id' => $this->admin->id,
            'type' => 'hospitalisation',
            'statut' => $statut,
            'date_entree' => now()->subDay(),
            'date_sortie' => $statut === 'termine' ? now() : null,
            'service_id' => $service?->id,
        ]);
    }

    /** Un examen de deux paramètres sans résultat, sur un séjour (servi à crédit). */
    protected function examenEnAttente(Patient $p): ExamenLaboratoire
    {
        $visit = $this->visite($p);
        $examen = ExamenLaboratoire::create([
            'numero_bon' => 'LAB-2026-'.random_int(10000, 99999),
            'visit_id' => $visit->id,
            'patient_id' => $p->id,
            'prescripteur_id' => $this->admin->id,
            'date_prescription' => now()->subMinutes(5),
            'statut' => 'prescrit',
            'domaine' => 'labo',
        ]);
        foreach (TypeExamen::where('domaine', 'labo')->take(2)->get() as $type) {
            ResultatExamen::create([
                'examen_id' => $examen->id,
                'type_examen_id' => $type->id,
                'parametre' => $type->libelle,
                'valeurs_reference' => [],
            ]);
        }

        return $examen->fresh('resultats');
    }

    public function test_acces_rapides_montre_les_tuiles_et_les_services(): void
    {
        $service = Service::firstOrFail();

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Accès rapides')
            ->assertSee('Mes tâches')
            ->assertSee('Patients')
            ->assertSee(route('patients.create'), false)
            ->assertSee($service->nom);
    }

    public function test_un_caissier_ne_voit_pas_les_tuiles_d_administration(): void
    {
        $caissier = User::factory()->create(['establishment_id' => $this->etab->id]);
        $caissier->assignRole('caissier');

        $this->actingAs($caissier)->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('Utilisateurs')
            ->assertDontSee('Maintenance');
    }

    public function test_la_liste_des_patients_montre_le_dernier_episode_et_filtre(): void
    {
        $service = Service::firstOrFail();
        $enCours = $this->patient('MBUYI', 'M', 40);
        $this->visite($enCours, 'en_cours', $service);
        $cloture = $this->patient('KABEYA', 'F', 7);
        $this->visite($cloture, 'termine');
        $this->patient('TSHIKALA', 'F', 60);

        $this->get(route('patients.index'))
            ->assertOk()
            ->assertSee('MBUYI')->assertSee('En cours')->assertSee($service->nom)
            ->assertSee('Clôturé')->assertSee('7 ans')
            ->assertSee('Gérer');

        $this->get(route('patients.index', ['episode' => 'en_cours']))
            ->assertSee('MBUYI')->assertDontSee('KABEYA')->assertDontSee('TSHIKALA');

        $this->get(route('patients.index', ['episode' => 'aucun']))
            ->assertSee('TSHIKALA')->assertDontSee('MBUYI');

        $this->get(route('patients.index', ['service' => $service->id]))
            ->assertSee('MBUYI')->assertDontSee('KABEYA');

        $this->get(route('patients.index', ['sexe' => 'F', 'age_min' => 50]))
            ->assertSee('TSHIKALA')->assertDontSee('KABEYA')->assertDontSee('MBUYI');

        $this->get(route('patients.index', ['age_max' => 10]))
            ->assertSee('KABEYA')->assertDontSee('TSHIKALA');
    }

    public function test_les_taches_du_laboratoire_et_la_saisie_par_entree(): void
    {
        $examen = $this->examenEnAttente($this->patient('UAYKANI'));
        [$r1, $r2] = $examen->resultats->values()->all();

        $this->get(route('taches.index'))
            ->assertOk()
            ->assertSee('Examens de laboratoire en attente de résultat')
            ->assertSee('UAYKANI')
            ->assertSee('Entrer résultat puis Entrée');

        $this->postJson(route('taches.resultat', $r1), ['valeur' => '12,5'])
            ->assertOk()->assertJson(['ok' => true, 'examen_statut' => 'en_analyse']);
        $this->assertSame(12.5, (float) $r1->fresh()->valeur_numerique);

        // Le dernier paramètre complète l'examen : il quitte la liste.
        $this->postJson(route('taches.resultat', $r2), ['valeur' => 'Négatif'])
            ->assertOk()->assertJson(['examen_statut' => 'resultat_disponible']);

        $fragment = $this->getJson(route('taches.fragment'))->assertOk()->json();
        $this->assertStringNotContainsString('UAYKANI', $fragment['html']);
    }

    public function test_un_examen_impaye_n_accepte_pas_de_resultat(): void
    {
        $examen = $this->examenEnAttente($this->patient('IMPAYE'));
        $examen->visit->update(['type' => 'consultation_externe']);
        $facture = \App\Models\Facture::create([
            'patient_id' => $examen->patient_id,
            'visit_id' => $examen->visit_id,
            'establishment_id' => $this->etab->id,
            'numero_facture' => 'FAC-T-'.random_int(1000, 9999),
            'date_facture' => now(),
            'statut' => 'emise',
            'total_ht' => 10, 'total_ttc' => 10, 'patient_part' => 10,
        ]);
        $examen->update(['facture_id' => $facture->id]);

        $this->get(route('taches.index'))->assertSee('En attente de paiement au guichet');
        $this->postJson(route('taches.resultat', $examen->resultats->first()), ['valeur' => '5'])
            ->assertStatus(422);
    }

    public function test_un_agent_sans_role_metier_voit_un_message(): void
    {
        $sansRole = User::factory()->create(['establishment_id' => $this->etab->id]);

        $this->actingAs($sansRole)->get(route('taches.index'))
            ->assertOk()->assertSee('Aucune tâche');
    }
}
