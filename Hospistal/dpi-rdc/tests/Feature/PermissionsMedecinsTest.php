<?php

namespace Tests\Feature;

use App\Models\Establishment;
use App\Models\ExamenLaboratoire;
use App\Models\NotificationInterne;
use App\Models\Patient;
use App\Models\TypeConsultation;
use App\Models\User;
use App\Models\Visit;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Un dossier n'appartient pas à un médecin.
 *
 * Le résultat d'un examen n'était annoncé qu'au confrère qui l'avait
 * demandé. Mais celui-là finit sa garde à six heures, part en congé, ou
 * consulte à l'autre bout de l'hôpital : pendant ce temps une hémoglobine à
 * 4 dort dans une boîte que personne d'autre n'ouvre, et le médecin qui
 * reprend le patient ne sait même pas qu'elle existe.
 *
 * De même, la file d'attente enfermait chaque médecin dans sa spécialité :
 * il ne pouvait pas prendre le patient d'un confrère absent, même en le
 * demandant explicitement.
 */
class PermissionsMedecinsTest extends TestCase
{
    use RefreshDatabase;

    protected Establishment $etab;

    protected User $prescripteur;

    protected User $confrere;

    protected Patient $patient;

    protected Visit $visite;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->etab = Establishment::firstOrFail();

        $this->prescripteur = $this->medecin('KALALA', 'MED-P1', 'Pédiatrie');
        $this->confrere = $this->medecin('MUKENDI', 'MED-C1', 'Cardiologie');

        $this->patient = Patient::create([
            'establishment_id' => $this->etab->id,
            'dossier_number' => 'PERM-0001',
            'nom' => 'TSHIALA', 'prenom' => 'Josué', 'sexe' => 'M',
            'date_naissance' => now()->subYears(6)->toDateString(),
            'type_prise_en_charge' => 'prive',
        ]);

        $this->visite = Visit::create([
            'patient_id' => $this->patient->id,
            'establishment_id' => $this->etab->id,
            'user_id' => $this->prescripteur->id,
            'type' => 'consultation_externe', 'statut' => 'en_cours',
            'date_entree' => now()->subHours(2),
            'motif_consultation' => 'Fièvre',
        ]);
    }

    protected function medecin(string $nom, string $matricule, ?string $specialite = null): User
    {
        $user = User::create([
            'establishment_id' => $this->etab->id,
            'nom' => $nom, 'prenom' => 'Test', 'matricule' => $matricule,
            'password' => 'motdepasse123', 'is_active' => true,
            'specialite' => $specialite,
        ]);
        $user->assignRole('medecin');

        return $user;
    }

    protected function examenAvecResultat(): ExamenLaboratoire
    {
        $examen = ExamenLaboratoire::create([
            'numero_bon' => 'LAB-PERM-01',
            'patient_id' => $this->patient->id,
            'visit_id' => $this->visite->id,
            'prescripteur_id' => $this->prescripteur->id,
            'date_prescription' => now(),
            'statut' => 'valide', 'domaine' => 'labo',
        ]);

        app(NotificationService::class)->resultatsPrets($examen);

        return $examen;
    }

    // ═══════════════════════════════════════════════════════════
    // L'annonce appartient au dossier
    // ═══════════════════════════════════════════════════════════

    public function test_un_resultat_se_rattache_au_dossier_du_patient(): void
    {
        $this->examenAvecResultat();

        $annonce = NotificationInterne::where('type', 'resultat_pret')->firstOrFail();

        $this->assertSame($this->patient->id, $annonce->patient_id);
        // Le prescripteur reste nommé : il faut savoir qui a demandé quoi.
        $this->assertSame($this->prescripteur->id, $annonce->destinataire_id);
    }

    public function test_un_confrere_voit_le_resultat_quil_na_pas_demande(): void
    {
        $this->examenAvecResultat();

        // C'est tout l'objet : le prescripteur a fini sa garde.
        $this->actingAs($this->confrere)
            ->get(route('notifications.index'))
            ->assertOk()
            ->assertSee('LAB-PERM-01');
    }

    public function test_le_caissier_ne_voit_pas_les_resultats_des_patients(): void
    {
        $this->examenAvecResultat();

        // Ouvrir aux médecins n'est pas ouvrir à tout le monde.
        $caissier = User::factory()->create(['establishment_id' => $this->etab->id]);
        $caissier->assignRole('caissier');

        $this->actingAs($caissier)
            ->get(route('notifications.index'))
            ->assertOk()
            ->assertDontSee('LAB-PERM-01');
    }

    public function test_lannonce_se_lit_depuis_le_dossier_du_patient(): void
    {
        $this->examenAvecResultat();

        // Le médecin qui reprend le patient ouvre son dossier, pas la boîte
        // d'un confrère.
        $this->actingAs($this->confrere)
            ->get(route('patients.show', $this->patient))
            ->assertOk()
            ->assertSee('Ce qui est arrivé sur ce dossier')
            ->assertSee('LAB-PERM-01');
    }

    public function test_le_dossier_ne_montre_que_ses_propres_annonces(): void
    {
        $this->examenAvecResultat();

        $autre = Patient::create([
            'establishment_id' => $this->etab->id,
            'dossier_number' => 'PERM-0002',
            'nom' => 'AUTRE', 'prenom' => 'Patient', 'sexe' => 'F',
            'type_prise_en_charge' => 'prive',
        ]);

        $this->actingAs($this->confrere)
            ->get(route('patients.show', $autre))
            ->assertOk()
            ->assertDontSee('LAB-PERM-01');
    }

    // ═══════════════════════════════════════════════════════════
    // Qui prend l'annonce en charge
    // ═══════════════════════════════════════════════════════════

    public function test_celui_qui_lit_lannonce_est_nomme(): void
    {
        $this->examenAvecResultat();
        $annonce = NotificationInterne::where('type', 'resultat_pret')->firstOrFail();

        $this->actingAs($this->confrere)
            ->post(route('notifications.lue', $annonce));

        // Sans cela, on traite le même résultat à trois — ou on croit que
        // personne ne l'a vu.
        $this->assertSame($this->confrere->id, $annonce->fresh()->lu_par);
    }

    public function test_le_dossier_dit_qui_sen_est_occupe(): void
    {
        $this->examenAvecResultat();
        $annonce = NotificationInterne::where('type', 'resultat_pret')->firstOrFail();

        $this->actingAs($this->confrere)->post(route('notifications.lue', $annonce));

        $this->actingAs($this->prescripteur)
            ->get(route('patients.show', $this->patient))
            ->assertOk()
            ->assertSee('pris en charge par')
            ->assertSee('MUKENDI');
    }

    // ═══════════════════════════════════════════════════════════
    // La file d'attente oriente, elle n'enferme pas
    // ═══════════════════════════════════════════════════════════

    public function test_la_file_met_en_avant_la_specialite_du_medecin(): void
    {
        $this->visiteEnAttente('Pédiatrie', 'PEDIA-01', 'ENFANT');
        $this->visiteEnAttente('Cardiologie', 'CARDIO-01', 'COEUR');

        // Par défaut, chacun voit sa file : c'est ce qui fait gagner du temps.
        $this->actingAs($this->prescripteur)
            ->get(route('consultations.index'))
            ->assertOk()
            ->assertSee('ENFANT')
            ->assertDontSee('COEUR');
    }

    public function test_un_medecin_peut_demander_la_file_dune_autre_specialite(): void
    {
        $this->visiteEnAttente('Cardiologie', 'CARDIO-01', 'COEUR');

        // Le cardiologue est absent : le pédiatre doit pouvoir prendre le
        // patient plutôt que de le laisser attendre un homme qui ne viendra
        // pas.
        $this->actingAs($this->prescripteur)
            ->get(route('consultations.index', ['specialite' => 'Cardiologie']))
            ->assertOk()
            ->assertSee('COEUR');
    }

    public function test_le_choix_des_autres_specialites_est_offert(): void
    {
        $this->visiteEnAttente('Cardiologie', 'CARDIO-01', 'COEUR');

        // La liste se calculait après filtrage : elle ne proposait au médecin
        // que sa propre spécialité, donc il ne pouvait jamais en sortir.
        $this->actingAs($this->prescripteur)
            ->get(route('consultations.index'))
            ->assertOk()
            ->assertSee('Cardiologie')
            // Le compte porte sur la file entière — la visite du dossier
            // suivi plus le patient de cardiologie — et non sur ce qui reste
            // après filtrage par spécialité.
            ->assertSee('Toutes spécialités (2)');
    }

    public function test_un_medecin_ouvre_le_dossier_dun_patient_quil_na_jamais_vu(): void
    {
        // Rien ne doit dépendre de « c'est mon patient » : la garde change,
        // le patient reste.
        $this->actingAs($this->confrere)
            ->get(route('patients.show', $this->patient))
            ->assertOk()
            ->assertSee('TSHIALA');

        $this->actingAs($this->confrere)
            ->get(route('dossier.show', $this->patient))
            ->assertOk();
    }

    protected function visiteEnAttente(string $specialite, string $code, string $nom): Visit
    {
        $type = TypeConsultation::create([
            'code' => $code, 'libelle' => 'Consultation '.$specialite,
            'categorie' => 'specialisee', 'specialite' => $specialite,
            'prix_usd' => 10, 'est_actif' => true,
        ]);

        $patient = Patient::create([
            'establishment_id' => $this->etab->id,
            'dossier_number' => 'PERM-'.$code,
            'nom' => $nom, 'prenom' => 'Test', 'sexe' => 'F',
            'type_prise_en_charge' => 'prive',
        ]);

        return Visit::create([
            'patient_id' => $patient->id,
            'establishment_id' => $this->etab->id,
            'user_id' => $this->prescripteur->id,
            'type' => 'consultation_externe', 'statut' => 'en_cours',
            'type_consultation_id' => $type->id,
            'date_entree' => now(),
            'motif_consultation' => 'Contrôle',
        ]);
    }
}
