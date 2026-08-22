<?php

namespace Tests\Feature;

use App\Models\Consultation;
use App\Models\Establishment;
use App\Models\Patient;
use App\Models\User;
use App\Models\Visit;
use App\Services\StatistiquesAttenteService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * L'attente à l'échelle de l'hôpital.
 *
 * La chronologie dit ce qu'un patient a attendu. Elle ne dit pas qu'il manque
 * un caissier le lundi matin : pour cela il faut empiler les parcours et
 * regarder où les minutes s'accumulent.
 */
class StatistiquesAttenteTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $infirmier;

    protected User $medecin;

    protected Establishment $etab;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->admin = User::where('email', 'admin@dpi-rdc.local')->firstOrFail();
        $this->etab = Establishment::firstOrFail();
        $this->actingAs($this->admin);

        $this->infirmier = $this->agent('infirmier', 'MPUTU', 'INF-800');
        $this->medecin = $this->agent('medecin', 'LUKUSA', 'MED-800');
    }

    protected function agent(string $role, string $nom, string $matricule): User
    {
        $user = User::create([
            'establishment_id' => $this->etab->id,
            'nom' => $nom, 'prenom' => 'Test', 'matricule' => $matricule,
            'password' => 'motdepasse123', 'is_active' => true,
        ]);
        $user->assignRole($role);

        return $user;
    }

    /**
     * Un passage avec une attente connue avant le cabinet.
     *
     * Triage à l'heure dite, entrée au cabinet $attente minutes plus tard,
     * consultation rédigée vingt minutes après.
     */
    protected function passage(Carbon $arrivee, int $attente, string $nom = 'PATIENT'): Visit
    {
        $patient = Patient::create([
            'establishment_id' => $this->etab->id,
            'dossier_number' => 'ATT-'.random_int(10000, 99999),
            'nom' => $nom, 'prenom' => 'Test', 'sexe' => 'F',
            'date_naissance' => now()->subYears(30)->toDateString(),
            'type_prise_en_charge' => 'prive',
        ]);

        $visite = Visit::create([
            'patient_id' => $patient->id,
            'establishment_id' => $this->etab->id,
            'user_id' => $this->admin->id,
            'type' => 'consultation_externe',
            'statut' => 'termine',
            'date_entree' => $arrivee,
            'motif_consultation' => 'Fièvre',
            'triage_fait_at' => $arrivee,
            'triage_par' => $this->infirmier->id,
            'consultation_debutee_at' => $arrivee->copy()->addMinutes($attente),
            'consultation_par' => $this->medecin->id,
        ]);

        Consultation::create([
            'visit_id' => $visite->id,
            'user_id' => $this->medecin->id,
            'date_consultation' => $arrivee->copy()->addMinutes($attente),
            'finalise_at' => $arrivee->copy()->addMinutes($attente + 20),
            'diagnostics' => [['libelle' => 'RAS', 'type' => 'principal']],
            'statut' => 'finalise',
        ]);

        return $visite;
    }

    protected function analyse(string $debut = '2026-08-01', string $fin = '2026-08-31'): array
    {
        return app(StatistiquesAttenteService::class)->analyse($debut, $fin, $this->etab->id);
    }

    // ═══════════════════════════════════════════════════════════
    // Ce que la période dit
    // ═══════════════════════════════════════════════════════════

    public function test_lattente_se_compte_sur_lensemble_des_passages(): void
    {
        // Trois lundis d'affilée, une attente de plus en plus longue.
        $this->passage(Carbon::create(2026, 8, 3, 8, 0), 30);
        $this->passage(Carbon::create(2026, 8, 10, 8, 0), 60);
        $this->passage(Carbon::create(2026, 8, 17, 8, 0), 90);

        $analyse = $this->analyse();

        $this->assertSame(3, $analyse['visites']);
        $this->assertSame(3, $analyse['mesurables']);
        $this->assertSame(60, $analyse['global']['mediane']);
        $this->assertSame(60, $analyse['global']['moyenne']);
        $this->assertSame(90, $analyse['global']['pire']);
        $this->assertSame(180, $analyse['global']['total']);
    }

    public function test_la_mediane_resiste_a_un_dossier_oublie(): void
    {
        foreach ([20, 20, 20, 20] as $attente) {
            $this->passage(Carbon::create(2026, 8, 5, 9, 0), $attente);
        }
        // Un dossier oublié tout l'après-midi.
        $this->passage(Carbon::create(2026, 8, 5, 9, 0), 400);

        $analyse = $this->analyse();

        // La moyenne s'envole, la médiane dit ce que vit le patient ordinaire.
        $this->assertSame(20, $analyse['global']['mediane']);
        $this->assertGreaterThan(90, $analyse['global']['moyenne']);
    }

    public function test_lattente_est_rattachee_a_son_poste(): void
    {
        $this->passage(Carbon::create(2026, 8, 4, 8, 0), 45);

        $postes = $this->analyse()['par_poste'];

        // Entre le triage et le cabinet, le patient attend le médecin.
        $this->assertArrayHasKey('cabinet', $postes->all());
        $this->assertSame(45, $postes['cabinet']['mediane']);
    }

    // ═══════════════════════════════════════════════════════════
    // Le croisement jour × heure : la question posée
    // ═══════════════════════════════════════════════════════════

    public function test_le_creneau_charge_se_designe(): void
    {
        // Le lundi à 8 h, quatre patients attendent longtemps.
        foreach ([1, 8, 15, 22] as $jour) {
            $this->passage(Carbon::create(2026, 8, 3, 8, 0)->addDays($jour - 1), 75);
        }
        // Le mercredi à 14 h, deux patients attendent peu.
        foreach ([5, 12] as $jour) {
            $this->passage(Carbon::create(2026, 8, $jour, 14, 0), 10);
        }

        $creneaux = $this->analyse()['creneaux_noirs'];

        $this->assertNotEmpty($creneaux);
        // Le pire créneau est en tête : c'est là qu'il manque quelqu'un.
        $this->assertSame('08h', $creneaux->first()['heure']);
        $this->assertGreaterThanOrEqual(2, $creneaux->first()['patients']);
    }

    public function test_un_creneau_isole_nest_pas_un_probleme(): void
    {
        // Deux personnes qui attendent ne font pas un problème à elles seules :
        // le seuil est à deux patients, un cas unique n'apparaît pas.
        $this->passage(Carbon::create(2026, 8, 6, 11, 0), 200);

        $this->assertCount(0, $this->analyse()['creneaux_noirs']);
    }

    public function test_lattente_se_ventile_par_jour_de_la_semaine(): void
    {
        $this->passage(Carbon::create(2026, 8, 3, 8, 0), 90);   // lundi
        $this->passage(Carbon::create(2026, 8, 5, 8, 0), 15);   // mercredi

        $jours = $this->analyse()['par_jour_semaine'];

        $this->assertSame(90, $jours['Lundi']['mediane']);
        $this->assertSame(15, $jours['Mercredi']['mediane']);
    }

    public function test_lattente_se_ventile_par_heure(): void
    {
        $this->passage(Carbon::create(2026, 8, 4, 8, 0), 80);
        $this->passage(Carbon::create(2026, 8, 4, 15, 0), 12);

        $heures = $this->analyse()['par_heure'];

        $this->assertSame(80, $heures[8]['mediane']);
        $this->assertSame(12, $heures[15]['mediane']);
    }

    public function test_les_pires_attentes_sont_nommees(): void
    {
        $this->passage(Carbon::create(2026, 8, 7, 9, 0), 20, 'ORDINAIRE');
        $this->passage(Carbon::create(2026, 8, 7, 9, 0), 240, 'OUBLIE');

        $pires = $this->analyse()['pires'];

        $this->assertSame('OUBLIE Test', $pires->first()['patient']->nom_complet);
        $this->assertSame(240, $pires->first()['minutes']);
    }

    // ═══════════════════════════════════════════════════════════
    // Bornes et filtres
    // ═══════════════════════════════════════════════════════════

    public function test_la_periode_borne_le_compte(): void
    {
        $this->passage(Carbon::create(2026, 8, 10, 8, 0), 60);
        $this->passage(Carbon::create(2026, 9, 10, 8, 0), 60);

        $this->assertSame(1, $this->analyse('2026-08-01', '2026-08-31')['visites']);
    }

    public function test_le_type_de_passage_se_filtre(): void
    {
        $ambulatoire = $this->passage(Carbon::create(2026, 8, 11, 8, 0), 30);
        $urgence = $this->passage(Carbon::create(2026, 8, 11, 9, 0), 30);
        $urgence->update(['type' => 'urgence']);

        $service = app(StatistiquesAttenteService::class);

        $this->assertSame(1, $service->analyse('2026-08-01', '2026-08-31', $this->etab->id, 'urgence')['visites']);
        $this->assertSame(1, $service->analyse('2026-08-01', '2026-08-31', $this->etab->id, 'consultation_externe')['visites']);
        $this->assertSame(2, $service->analyse('2026-08-01', '2026-08-31', $this->etab->id)['visites']);
    }

    public function test_une_periode_sans_attente_mesurable_le_dit(): void
    {
        $analyse = $this->analyse('2020-01-01', '2020-01-31');

        $this->assertSame(0, $analyse['visites']);
        $this->assertSame(0, $analyse['global']['nombre']);
        $this->assertSame(0, $analyse['global']['mediane']);
    }

    public function test_un_passage_sans_seconde_heure_ne_produit_aucune_attente(): void
    {
        Visit::create([
            'patient_id' => Patient::create([
                'establishment_id' => $this->etab->id,
                'dossier_number' => 'ATT-SEUL',
                'nom' => 'SEUL', 'prenom' => 'Test', 'sexe' => 'M',
                'type_prise_en_charge' => 'prive',
            ])->id,
            'establishment_id' => $this->etab->id,
            'user_id' => $this->admin->id,
            'type' => 'consultation_externe',
            'statut' => 'en_cours',
            'date_entree' => Carbon::create(2026, 8, 12, 8, 0),
            'motif_consultation' => 'Douleur',
        ]);

        $analyse = $this->analyse();

        // Une attente ne se mesure qu'entre deux heures connues.
        $this->assertSame(1, $analyse['visites']);
        $this->assertSame(0, $analyse['mesurables']);
    }

    // ═══════════════════════════════════════════════════════════
    // L'écran
    // ═══════════════════════════════════════════════════════════

    public function test_lecran_de_lattente_repond(): void
    {
        foreach ([3, 10, 17] as $jour) {
            $this->passage(Carbon::create(2026, 8, $jour, 8, 0), 75, 'ATTENDANT');
        }

        $this->get(route('parcours.attente', ['debut' => '2026-08-01', 'fin' => '2026-08-31']))
            ->assertOk()
            ->assertSee('attente à l')
            ->assertSee('Attente médiane')
            ->assertSee('Créneaux à renforcer')
            ->assertSee('Où l')
            ->assertSee('Quel jour de la semaine')
            ->assertSee('Lundi')
            ->assertSee('ATTENDANT');
    }

    public function test_lecran_dit_quand_il_ny_a_rien_a_mesurer(): void
    {
        $this->get(route('parcours.attente', ['debut' => '2020-01-01', 'fin' => '2020-01-31']))
            ->assertOk()
            ->assertSee('Aucune attente mesurable');
    }

    public function test_le_pilotage_de_lattente_reste_a_lencadrement(): void
    {
        $caissier = User::factory()->create(['establishment_id' => $this->etab->id]);
        $caissier->assignRole('caissier');

        $this->actingAs($caissier)->get(route('parcours.attente'))->assertForbidden();

        $chef = $this->agent('infirmier_chef', 'MAJOR', 'INFC-800');
        $this->actingAs($chef)->get(route('parcours.attente'))->assertOk();
    }

    public function test_le_lien_est_offert_depuis_les_statistiques(): void
    {
        $this->get(route('statistiques.index'))
            ->assertOk()
            ->assertSee(route('parcours.attente'), false);
    }
}
