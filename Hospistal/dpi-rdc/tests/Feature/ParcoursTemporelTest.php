<?php

namespace Tests\Feature;

use App\Models\Consultation;
use App\Models\Establishment;
use App\Models\ExamenLaboratoire;
use App\Models\Facture;
use App\Models\Paiement;
use App\Models\Patient;
use App\Models\ResultatExamen;
use App\Models\Service;
use App\Models\TypeExamen;
use App\Models\User;
use App\Models\Visit;
use App\Services\ParcoursTemporelService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Le temps du parcours patient.
 *
 * Un hôpital ne se juge pas seulement à ce qu'il fait, mais à ce qu'il fait
 * attendre. Les heures étaient toutes en base sans que personne ne les mette
 * bout à bout : on les reconstitue, on ne les invente pas.
 */
class ParcoursTemporelTest extends TestCase
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

        $this->patient = Patient::create([
            'establishment_id' => $this->etab->id,
            'dossier_number' => 'PAT-2026-015000',
            'nom' => 'MBALA', 'prenom' => 'Thérèse', 'sexe' => 'F',
            'date_naissance' => now()->subYears(34)->toDateString(),
            'type_prise_en_charge' => 'prive',
        ]);
    }

    protected function agent(string $role, string $nom, string $matricule): User
    {
        $user = User::create([
            'establishment_id' => $this->etab->id,
            'nom' => $nom, 'prenom' => 'Test',
            'matricule' => $matricule,
            'password' => bcrypt('motdepasse123'),
            'is_active' => true,
        ]);
        $user->assignRole($role);

        return $user;
    }

    /**
     * Un passage ambulatoire complet, minuté.
     *
     * 08:00 accueil · 08:05 facture · 08:35 encaissement · 08:50 triage
     * · 09:40 entrée au cabinet · 10:00 consultation rédigée
     * · 10:05 examens prescrits · 10:45 prélèvement · 12:15 résultats
     * · 12:30 sortie.
     *
     * @return array{visite: Visit, agents: array<string, User>}
     */
    protected function scenario(): array
    {
        $accueil = $this->agent('agent_admin', 'NGALULA', 'ADM-900');
        $caissier = $this->agent('caissier', 'KABEYA', 'CAI-900');
        $infirmier = $this->agent('infirmier', 'MPUTU', 'INF-900');
        $medecin = $this->agent('medecin', 'LUKUSA', 'MED-900');
        $laborantin = $this->agent('laborantin', 'BOKUNGU', 'LAB-900');

        $jour = Carbon::create(2026, 8, 20, 8, 0, 0);

        $visite = Visit::create([
            'patient_id' => $this->patient->id,
            'establishment_id' => $this->etab->id,
            'user_id' => $accueil->id,
            'type' => 'consultation_externe',
            'statut' => 'termine',
            'date_entree' => $jour,
            'date_sortie' => $jour->copy()->setTime(12, 30),
            'service_id' => Service::value('id'),
            'motif_consultation' => 'Fièvre',
            'triage_fait_at' => $jour->copy()->setTime(8, 50),
            'triage_par' => $infirmier->id,
            'consultation_debutee_at' => $jour->copy()->setTime(9, 40),
            'consultation_par' => $medecin->id,
        ]);
        $visite->forceFill(['created_at' => $jour])->save();

        $facture = Facture::create([
            'patient_id' => $this->patient->id,
            'visit_id' => $visite->id,
            'establishment_id' => $this->etab->id,
            'numero_facture' => 'FAC-TEMPS-0001',
            'date_facture' => $jour->copy()->setTime(8, 5),
            'statut' => 'payee',
            'type_prise_en_charge' => 'prive',
            'devise' => 'CDF', 'taux_change' => 1,
            'total_ht' => 60000, 'total_ttc' => 60000,
            'patient_part' => 60000, 'assurance_part' => 0,
        ]);

        Paiement::create([
            'facture_id' => $facture->id,
            'caissier_id' => $caissier->id,
            'date_paiement' => $jour->copy()->setTime(8, 35),
            'montant' => 60000, 'devise' => 'CDF',
            'taux_change' => 1, 'montant_cdf' => 60000,
            'mode_paiement' => 'especes',
        ]);

        $consultation = Consultation::create([
            'visit_id' => $visite->id,
            'user_id' => $medecin->id,
            'date_consultation' => $jour->copy()->setTime(9, 40),
            'finalise_at' => $jour->copy()->setTime(10, 0),
            'diagnostics' => [['libelle' => 'Paludisme simple', 'type' => 'principal']],
            'statut' => 'finalise',
        ]);

        $examen = ExamenLaboratoire::create([
            'numero_bon' => 'LAB-TEMPS-0001',
            'patient_id' => $this->patient->id,
            'visit_id' => $visite->id,
            'prescripteur_id' => $medecin->id,
            'laborantin_id' => $laborantin->id,
            'date_prescription' => $jour->copy()->setTime(10, 5),
            'date_prelevement' => $jour->copy()->setTime(10, 45),
            'date_resultat' => $jour->copy()->setTime(12, 15),
            'statut' => 'valide',
            'domaine' => 'labo',
            'conclusion' => 'Goutte épaisse positive.',
        ]);

        $type = TypeExamen::where('domaine', 'labo')->firstOrFail();
        ResultatExamen::create([
            'examen_id' => $examen->id,
            'type_examen_id' => $type->id,
            'parametre' => $type->libelle,
            'valeur_brute' => 'positif',
            'valeurs_reference' => [],
        ]);

        return ['visite' => $visite->fresh(), 'consultation' => $consultation, 'agents' => [
            'accueil' => $accueil, 'caissier' => $caissier, 'infirmier' => $infirmier,
            'medecin' => $medecin, 'laborantin' => $laborantin,
        ]];
    }

    // ═══════════════════════════════════════════════════════════
    // La chronologie
    // ═══════════════════════════════════════════════════════════

    public function test_le_parcours_se_reconstitue_dans_lordre(): void
    {
        ['visite' => $visite] = $this->scenario();

        $jalons = app(ParcoursTemporelService::class)->jalons($visite);

        $moments = $jalons->pluck('moment')->map->format('H:i')->all();

        $this->assertSame($moments, collect($moments)->sort()->values()->all(),
            'Les jalons doivent être rendus du plus ancien au plus récent.');
        $this->assertContains('08:00', $moments);
        $this->assertContains('12:30', $moments);
    }

    public function test_chaque_jalon_porte_son_acteur_et_son_poste(): void
    {
        ['visite' => $visite, 'agents' => $agents] = $this->scenario();

        $jalons = app(ParcoursTemporelService::class)->jalons($visite);

        $triage = $jalons->firstWhere('poste', 'triage');
        $this->assertSame($agents['infirmier']->id, $triage['acteur']->id);
        $this->assertStringContainsString('Infirmier', $triage['role']);

        $encaissement = $jalons->first(fn ($j) => str_contains($j['libelle'], 'Encaissement'));
        $this->assertSame($agents['caissier']->id, $encaissement['acteur']->id);

        $resultats = $jalons->first(fn ($j) => str_contains($j['libelle'], 'Résultats rendus'));
        $this->assertSame($agents['laborantin']->id, $resultats['acteur']->id);
    }

    public function test_lattente_et_la_prise_en_charge_ne_se_confondent_pas(): void
    {
        ['visite' => $visite] = $this->scenario();

        $segments = app(ParcoursTemporelService::class)->segments($visite);

        // 08:50 triage → 09:40 cabinet : le patient attend le médecin, ces
        // cinquante minutes ne sont imputées à personne.
        $attente = $segments->first(fn ($s) => $s['attente']
            && $s['depuis']->format('H:i') === '08:50');

        $this->assertNotNull($attente);
        $this->assertSame(50, $attente['minutes']);
        $this->assertNull($attente['acteur']);

        // 09:40 → 10:00, deux jalons du cabinet : c'est du temps médical.
        $consultation = $segments->first(fn ($s) => ! $s['attente']
            && $s['poste'] === 'cabinet');

        $this->assertSame(20, $consultation['minutes']);
        $this->assertNotNull($consultation['acteur']);
    }

    public function test_la_synthese_compte_le_sejour_de_bout_en_bout(): void
    {
        ['visite' => $visite] = $this->scenario();

        $synthese = app(ParcoursTemporelService::class)->synthese($visite);

        // 08:00 → 12:30 = 4 h 30.
        $this->assertSame(270, $synthese['total_minutes']);
        $this->assertSame(270, $synthese['prise_en_charge_minutes'] + $synthese['attente_minutes']);
        $this->assertGreaterThan(0, $synthese['attente_minutes']);
        $this->assertSame('08:00', $synthese['debut']->format('H:i'));
        $this->assertSame('12:30', $synthese['fin']->format('H:i'));
    }

    public function test_la_plus_longue_attente_est_designee(): void
    {
        ['visite' => $visite] = $this->scenario();

        $pire = app(ParcoursTemporelService::class)->synthese($visite)['pire_attente'];

        $this->assertNotNull($pire);
        // 10:45 prélèvement → 12:15 résultats est du travail de laboratoire ;
        // la plus longue attente reste celle du guichet ou du cabinet.
        $this->assertTrue($pire['attente']);
        $this->assertGreaterThanOrEqual(30, $pire['minutes']);
    }

    public function test_un_sejour_a_peine_ouvert_na_pas_de_duree_a_montrer(): void
    {
        $visite = Visit::create([
            'patient_id' => $this->patient->id,
            'establishment_id' => $this->etab->id,
            'user_id' => $this->admin->id,
            'type' => 'urgence',
            'statut' => 'en_attente',
            'date_entree' => now(),
            'motif_consultation' => 'Douleur',
        ]);

        $synthese = app(ParcoursTemporelService::class)->synthese($visite);

        $this->assertSame(1, $synthese['jalons']);
        $this->assertSame(0, $synthese['total_minutes']);

        $this->get(route('parcours.chronologie', $visite))
            ->assertOk()
            ->assertSee('pas encore de durée à mesurer');
    }

    public function test_lecran_de_chronologie_montre_le_deroule(): void
    {
        ['visite' => $visite] = $this->scenario();

        $this->get(route('parcours.chronologie', $visite))
            ->assertOk()
            ->assertSee('Chronologie du parcours')
            ->assertSee('MBALA')
            ->assertSee('Triage et constantes')
            ->assertSee('Résultats rendus')
            ->assertSee('4 h 30')
            ->assertSee('Passées à attendre');
    }

    // ═══════════════════════════════════════════════════════════
    // Le temps d'utilisation, agent par agent
    // ═══════════════════════════════════════════════════════════

    public function test_chaque_agent_retrouve_son_temps_dans_son_profil(): void
    {
        ['agents' => $agents] = $this->scenario();

        $service = app(ParcoursTemporelService::class);
        $bornes = ['2026-08-20', '2026-08-20'];

        $medecin = $service->activiteDe($agents['medecin'], ...$bornes);
        $laborantin = $service->activiteDe($agents['laborantin'], ...$bornes);
        $infirmier = $service->activiteDe($agents['infirmier'], ...$bornes);

        // Le médecin : 09:40 → 10:00 au cabinet.
        $this->assertSame(20, $medecin['minutes']);
        $this->assertSame(1, $medecin['patients']);

        // Le laboratoire : 10:45 prélèvement → 12:15 résultats.
        $this->assertSame(90, $laborantin['minutes']);

        // L'infirmier n'a qu'un jalon : son geste se compte en actes, pas en
        // minutes. On ne lui invente pas une durée.
        $this->assertSame(0, $infirmier['minutes']);
        $this->assertSame(1, $infirmier['interventions']);
    }

    public function test_le_profil_dit_ou_le_temps_a_ete_passe(): void
    {
        ['agents' => $agents] = $this->scenario();

        $activite = app(ParcoursTemporelService::class)
            ->activiteDe($agents['laborantin'], '2026-08-20', '2026-08-20');

        $this->assertSame(90, $activite['par_poste']['Laboratoire']);
        $this->assertSame(90, $activite['minutes_par_patient']);
        $this->assertSame('MBALA Thérèse', $activite['parcours']->first()['patient']->nom_complet);
    }

    public function test_un_agent_sans_intervention_a_un_releve_vide(): void
    {
        $this->scenario();
        $etranger = $this->agent('pharmacien', 'INCONNU', 'PHA-900');

        $activite = app(ParcoursTemporelService::class)
            ->activiteDe($etranger, '2026-08-20', '2026-08-20');

        $this->assertSame(0, $activite['minutes']);
        $this->assertSame(0, $activite['patients']);
        $this->assertSame(0, $activite['minutes_par_patient']);
    }

    public function test_lecran_du_profil_repond(): void
    {
        ['agents' => $agents] = $this->scenario();

        $this->get(route('parcours.profil', $agents['medecin']).'?debut=2026-08-20&fin=2026-08-20')
            ->assertOk()
            ->assertSee('LUKUSA')
            ->assertSee('Temps mesuré auprès des patients')
            ->assertSee('MBALA')
            ->assertSee('20 min');
    }

    public function test_chacun_voit_son_propre_releve(): void
    {
        ['agents' => $agents] = $this->scenario();

        $this->actingAs($agents['medecin'])
            ->get(route('parcours.moi'))
            ->assertOk()
            ->assertSee('Mon temps d\'utilisation');
    }

    public function test_un_agent_ne_lit_pas_le_releve_dun_autre(): void
    {
        ['agents' => $agents] = $this->scenario();

        // Un relevé de temps est un outil d'organisation, pas de surveillance.
        $this->actingAs($agents['caissier'])
            ->get(route('parcours.profil', $agents['medecin']))
            ->assertForbidden();
    }

    public function test_lencadrement_lit_le_releve_de_son_equipe(): void
    {
        ['agents' => $agents] = $this->scenario();
        $chef = $this->agent('infirmier_chef', 'MAJOR', 'INFC-900');

        $this->actingAs($chef)
            ->get(route('parcours.profil', $agents['infirmier']))
            ->assertOk();
    }

    public function test_le_lien_est_offert_depuis_len_tete_et_le_sejour(): void
    {
        ['visite' => $visite] = $this->scenario();

        $this->get(route('visites.show', $visite))
            ->assertOk()
            ->assertSee('Chronologie et temps')
            ->assertSee(route('parcours.chronologie', $visite), false);

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSee(route('parcours.moi'), false);
    }

    // ═══════════════════════════════════════════════════════════
    // La mise en forme des durées
    // ═══════════════════════════════════════════════════════════

    public function test_les_durees_se_lisent_comme_on_en_parle(): void
    {
        $this->assertSame('45 min', ParcoursTemporelService::duree(45));
        $this->assertSame('1 h 30', ParcoursTemporelService::duree(90));
        $this->assertSame('2 h', ParcoursTemporelService::duree(120));
        $this->assertSame('1 h 05', ParcoursTemporelService::duree(65));
        $this->assertSame('2 j 3 h', ParcoursTemporelService::duree(51 * 60));
        $this->assertSame('—', ParcoursTemporelService::duree(0));
        $this->assertSame('—', ParcoursTemporelService::duree(null));
    }

    // ═══════════════════════════════════════════════════════════
    // Le bulletin de sortie
    // ═══════════════════════════════════════════════════════════

    public function test_la_sortie_enregistre_enfin_ce_quon_lui_donne(): void
    {
        ['visite' => $visite] = $this->scenario();
        $visite->update(['statut' => 'en_cours', 'date_sortie' => null]);

        $this->post(route('visites.sortir', $visite), [
            'mode_sortie' => 'ameliore',
            'observations_sortie' => 'Apyrexie obtenue à J3, alimentation reprise.',
            'recommandations_sortie' => 'Poursuivre le traitement 5 jours.',
            'rendez_vous_controle' => now()->addWeek()->toDateString(),
        ])->assertRedirect(route('visites.bulletin', $visite));

        $visite->refresh();

        // Le service recevait ces observations et les jetait.
        $this->assertSame('Apyrexie obtenue à J3, alimentation reprise.', $visite->observations_sortie);
        $this->assertSame('Poursuivre le traitement 5 jours.', $visite->recommandations_sortie);
        $this->assertNotNull($visite->rendez_vous_controle);
        $this->assertSame($this->admin->id, $visite->sortie_par);
        $this->assertSame('termine', $visite->statut);
    }

    public function test_le_deces_peut_enfin_se_noter(): void
    {
        ['visite' => $visite] = $this->scenario();
        // Un décès aux urgences n'avait aucun écran pour s'écrire.
        $visite->update(['statut' => 'en_cours', 'date_sortie' => null, 'type' => 'urgence']);

        // Le formulaire n'offrait que quatre modes sur les huit acceptés :
        // un registre qui ne sait pas écrire le décès oblige à mentir.
        $this->get(route('visites.show', $visite))
            ->assertOk()
            ->assertSee('Décès')
            ->assertSee('Sortie contre avis médical');

        $this->post(route('visites.sortir', $visite), ['mode_sortie' => 'deces'])->assertRedirect();

        $this->assertSame('deces', $visite->fresh()->mode_sortie);
        $this->assertSame('Décès', $visite->fresh()->libelleModeSortie());
    }

    public function test_un_controle_ne_se_fixe_pas_dans_le_passe(): void
    {
        ['visite' => $visite] = $this->scenario();
        $visite->update(['statut' => 'en_cours', 'date_sortie' => null]);

        $this->post(route('visites.sortir', $visite), [
            'mode_sortie' => 'gueri',
            'rendez_vous_controle' => now()->subWeek()->toDateString(),
        ])->assertSessionHasErrors('rendez_vous_controle');
    }

    public function test_le_bulletin_rassemble_tout_le_sejour(): void
    {
        ['visite' => $visite] = $this->scenario();

        $visite->update([
            'observations_sortie' => 'Apyrexie obtenue à J3.',
            'recommandations_sortie' => 'Revenir si la fièvre reprend.',
            'rendez_vous_controle' => now()->addWeek()->toDateString(),
            'mode_sortie' => 'gueri',
        ]);

        $this->get(route('visites.bulletin', $visite))
            ->assertOk()
            ->assertSee('BULLETIN DE SORTIE')
            ->assertSee('MBALA')
            ->assertSee('Paludisme simple')
            ->assertSee('LAB-TEMPS-0001')
            ->assertSee('Goutte épaisse positive')
            ->assertSee('Apyrexie obtenue à J3')
            ->assertSee('Revenir si la fièvre reprend')
            ->assertSee('Contrôle à prévoir')
            ->assertSee('Guéri');
    }

    public function test_le_bulletin_dun_sejour_sans_examen_reste_lisible(): void
    {
        $visite = Visit::create([
            'patient_id' => $this->patient->id,
            'establishment_id' => $this->etab->id,
            'user_id' => $this->admin->id,
            'type' => 'consultation_externe',
            'statut' => 'termine',
            'date_entree' => now()->subHours(2),
            'date_sortie' => now(),
            'motif_consultation' => 'Contrôle',
            'mode_sortie' => 'gueri',
        ]);

        $this->get(route('visites.bulletin', $visite))
            ->assertOk()
            ->assertSee('BULLETIN DE SORTIE')
            ->assertSee('Contrôle');
    }

    public function test_le_sejour_clos_offre_son_bulletin(): void
    {
        ['visite' => $visite] = $this->scenario();

        $this->get(route('visites.show', $visite))
            ->assertOk()
            ->assertSee('Bulletin de sortie');
    }
}
