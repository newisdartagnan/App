<?php

namespace Tests\Feature;

use App\Models\Consultation;
use App\Models\Establishment;
use App\Models\Lit;
use App\Models\NotificationInterne;
use App\Models\Patient;
use App\Models\Service;
use App\Models\User;
use App\Models\Visit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Ce que devient le patient à la fin de la consultation.
 *
 * La consultation s'arrêtait sur une conduite à tenir écrite en toutes
 * lettres. Personne ne savait, en la lisant, si le patient rentrait chez lui
 * ou s'il fallait lui trouver un lit — et le service d'hospitalisation
 * l'apprenait quand le patient se présentait à sa porte, s'il s'y présentait.
 *
 * La décision médicale et la logistique restent séparées : le médecin nomme
 * le service, le service attribue le lit. Un médecin en consultation ne sait
 * pas quel lit vient de se libérer en pédiatrie, et ce n'est pas son travail
 * de le savoir.
 */
class OrientationPatientTest extends TestCase
{
    use RefreshDatabase;

    protected User $medecin;

    protected User $infirmierChef;

    protected Establishment $etab;

    protected Service $service;

    protected Visit $visite;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->etab = Establishment::firstOrFail();

        $this->medecin = $this->agent('medecin', 'KAZADI', 'MED-ORI-1');
        $this->infirmierChef = $this->agent('infirmier_chef', 'MAJOR', 'INFC-ORI-1');

        $this->service = Service::create([
            'establishment_id' => $this->etab->id,
            'code' => 'PED-ORI', 'nom' => 'Pédiatrie', 'type' => 'pediatrie',
            'capacite_lits' => 2, 'is_active' => true,
        ]);

        Lit::create([
            'service_id' => $this->service->id,
            'establishment_id' => $this->etab->id,
            'numero' => '12', 'statut' => 'libre', 'is_active' => true,
        ]);

        $patient = Patient::create([
            'establishment_id' => $this->etab->id,
            'dossier_number' => 'ORI-0001',
            'nom' => 'NSIMBA', 'prenom' => 'Gloire', 'sexe' => 'M',
            'date_naissance' => now()->subYears(3)->toDateString(),
            'type_prise_en_charge' => 'prive',
        ]);

        $this->visite = Visit::create([
            'patient_id' => $patient->id,
            'establishment_id' => $this->etab->id,
            'user_id' => $this->medecin->id,
            'type' => 'consultation_externe', 'statut' => 'en_cours',
            'date_entree' => now()->subHour(),
            'motif_consultation' => 'Fièvre et convulsions',
            'gratuite' => true,
        ]);

        $this->actingAs($this->medecin);
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

    protected function consulter(array $champs = []): TestResponse
    {
        return $this->post(route('visites.consultation.store', $this->visite), array_merge([
            'diagnostics' => [['libelle' => 'Paludisme grave (1F45)']],
            'conclusion' => 'Paludisme grave du nourrisson.',
        ], $champs));
    }

    // ═══════════════════════════════════════════════════════════
    // La décision se prend pendant la consultation
    // ═══════════════════════════════════════════════════════════

    public function test_le_formulaire_demande_ce_que_devient_le_patient(): void
    {
        $page = $this->get(route('visites.consulter', $this->visite))->assertOk();

        $page->assertSee('Orientation du patient')
            ->assertSee('Retour à domicile')
            ->assertSee('Hospitalisation')
            ->assertSee('En attente de résultats')
            ->assertSee('name="service_oriente_id"', false)
            ->assertSee('Pédiatrie');
    }

    public function test_lorientation_est_conservee_avec_la_consultation(): void
    {
        $this->consulter(['orientation' => 'domicile'])->assertRedirect();

        $consultation = Consultation::firstOrFail();
        $this->assertSame('domicile', $consultation->orientation);
        $this->assertSame('Retour à domicile', $consultation->libelleOrientation());
    }

    public function test_lattente_de_resultats_est_une_decision(): void
    {
        // C'est la plus fréquente : on ne tranche pas avant la goutte
        // épaisse. Elle doit pouvoir s'écrire.
        $this->consulter(['orientation' => 'attente_examens'])->assertRedirect();

        $this->assertSame('attente_examens', Consultation::firstOrFail()->orientation);
        $this->assertFalse($this->visite->fresh()->attendUnLit());
    }

    public function test_une_orientation_inventee_est_refusee(): void
    {
        $this->consulter(['orientation' => 'chez_le_voisin'])
            ->assertSessionHasErrors('orientation');

        $this->assertDatabaseCount('consultations', 0);
    }

    public function test_une_consultation_sans_orientation_reste_possible(): void
    {
        // Le champ n'est pas obligatoire : une consultation interrompue vaut
        // mieux qu'une consultation perdue.
        $this->consulter()->assertRedirect();

        $this->assertNull(Consultation::firstOrFail()->orientation);
    }

    // ═══════════════════════════════════════════════════════════
    // Le patient apparaît dans le service avant d'y arriver
    // ═══════════════════════════════════════════════════════════

    public function test_lhospitalisation_pose_le_patient_dans_le_service(): void
    {
        $this->consulter([
            'orientation' => 'hospitalisation',
            'service_oriente_id' => $this->service->id,
        ])->assertRedirect();

        $visite = $this->visite->fresh();

        $this->assertTrue($visite->attendUnLit());
        $this->assertSame($this->service->id, $visite->admission_service_id);
        $this->assertSame($this->medecin->id, $visite->admission_par);
        // Il n'est pas encore hospitalisé : le lit n'est pas attribué.
        $this->assertSame('consultation_externe', $visite->type);
        $this->assertNull($visite->lit_id);
    }

    public function test_le_service_voit_le_patient_quon_lui_envoie(): void
    {
        $this->consulter([
            'orientation' => 'hospitalisation',
            'service_oriente_id' => $this->service->id,
        ]);

        // C'est ainsi qu'on prépare le lit au lieu de découvrir le patient
        // à la porte.
        $this->actingAs($this->infirmierChef)
            ->get(route('services.show', $this->service))
            ->assertOk()
            ->assertSee('Admissions demandées')
            ->assertSee('NSIMBA')
            ->assertSee('KAZADI');
    }

    public function test_le_service_est_prevenu(): void
    {
        $this->consulter([
            'orientation' => 'hospitalisation',
            'service_oriente_id' => $this->service->id,
        ]);

        $annonce = NotificationInterne::where('type', 'admission_demandee')->firstOrFail();

        $this->assertStringContainsString('Pédiatrie', $annonce->titre);
        $this->assertStringContainsString('NSIMBA', $annonce->message);
        $this->assertSame('infirmier_chef', $annonce->groupe_destinataire);
        // L'annonce suit le dossier, comme toutes les autres.
        $this->assertSame($this->visite->patient_id, $annonce->patient_id);
    }

    public function test_un_retour_a_domicile_ne_demande_aucun_lit(): void
    {
        $this->consulter([
            'orientation' => 'domicile',
            'service_oriente_id' => $this->service->id,
        ]);

        // Le service nommé par mégarde ne doit pas faire attendre un lit.
        $this->assertFalse($this->visite->fresh()->attendUnLit());
    }

    // ═══════════════════════════════════════════════════════════
    // Le service attribue le lit
    // ═══════════════════════════════════════════════════════════

    public function test_le_service_couche_le_patient(): void
    {
        $this->consulter([
            'orientation' => 'hospitalisation',
            'service_oriente_id' => $this->service->id,
        ]);

        $lit = Lit::where('service_id', $this->service->id)->firstOrFail();

        $this->actingAs($this->infirmierChef)
            ->post(route('services.admettre', [$this->service, $this->visite]), ['lit_id' => $lit->id])
            ->assertRedirect();

        $visite = $this->visite->fresh();

        $this->assertSame('hospitalisation', $visite->type);
        $this->assertSame($lit->id, $visite->lit_id);
        $this->assertSame($this->service->id, $visite->service_id);
        $this->assertSame('occupe', $lit->fresh()->statut);
    }

    public function test_on_nadmet_pas_dans_un_service_qui_na_pas_ete_nomme(): void
    {
        $autre = Service::create([
            'establishment_id' => $this->etab->id,
            'code' => 'CHIR-ORI', 'nom' => 'Chirurgie', 'type' => 'chirurgie',
            'capacite_lits' => 1, 'is_active' => true,
        ]);
        $litAutre = Lit::create([
            'service_id' => $autre->id, 'establishment_id' => $this->etab->id,
            'numero' => '1', 'statut' => 'libre', 'is_active' => true,
        ]);

        $this->consulter([
            'orientation' => 'hospitalisation',
            'service_oriente_id' => $this->service->id,
        ]);

        $this->actingAs($this->infirmierChef)
            ->from(route('services.show', $autre))
            ->post(route('services.admettre', [$autre, $this->visite]), ['lit_id' => $litAutre->id])
            ->assertRedirect(route('services.show', $autre));

        $this->assertSame('consultation_externe', $this->visite->fresh()->type);
    }

    public function test_le_caissier_nadmet_personne(): void
    {
        $this->consulter([
            'orientation' => 'hospitalisation',
            'service_oriente_id' => $this->service->id,
        ]);

        $caissier = User::factory()->create(['establishment_id' => $this->etab->id]);
        $caissier->assignRole('caissier');
        $lit = Lit::where('service_id', $this->service->id)->firstOrFail();

        $this->actingAs($caissier)
            ->post(route('services.admettre', [$this->service, $this->visite]), ['lit_id' => $lit->id])
            ->assertForbidden();
    }

    public function test_un_lit_pris_entre_temps_ne_fait_pas_tomber_lecran(): void
    {
        $this->consulter([
            'orientation' => 'hospitalisation',
            'service_oriente_id' => $this->service->id,
        ]);

        $lit = Lit::where('service_id', $this->service->id)->firstOrFail();
        $lit->update(['statut' => 'occupe']);

        // Deux infirmières peuvent cliquer en même temps.
        $this->actingAs($this->infirmierChef)
            ->post(route('services.admettre', [$this->service, $this->visite]), ['lit_id' => $lit->id])
            ->assertRedirect();

        $this->assertSame('consultation_externe', $this->visite->fresh()->type);
    }

    // ═══════════════════════════════════════════════════════════
    // L'admission directe ne dépend plus d'un script
    // ═══════════════════════════════════════════════════════════

    public function test_ladmission_depuis_le_sejour_marche_sans_script(): void
    {
        $this->visite->update(['type' => 'urgence']);

        // Les deux listes étaient liées par un onchange écrit dans la page :
        // sur un poste qui interdit les scripts en ligne, la liste des lits
        // restait vide et l'on ne pouvait pas admettre du tout.
        $page = $this->get(route('visites.show', $this->visite))->assertOk();

        $page->assertDontSee('onchange=', false);
        $page->assertSee('<optgroup label="Pédiatrie', false);
    }

    public function test_le_lit_choisi_determine_le_service(): void
    {
        $this->visite->update(['type' => 'urgence']);
        $lit = Lit::where('service_id', $this->service->id)->firstOrFail();

        // Le service n'est plus demandé séparément : deux listes liées, c'est
        // une paire incohérente possible.
        $this->post(route('visites.hospitaliser', $this->visite), ['lit_id' => $lit->id])
            ->assertRedirect();

        $this->assertSame($this->service->id, $this->visite->fresh()->service_id);
    }
}
