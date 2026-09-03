<?php

namespace Tests\Feature;

use App\Models\ActeInfirmier;
use App\Models\Establishment;
use App\Models\Patient;
use App\Models\User;
use App\Models\Visit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Les actes infirmiers, et la frontière entre soigner et prescrire.
 *
 * Le dossier savait tracer le pansement, le gavage, l'évaluation
 * neurologique et la transfusion. Tout le reste du travail infirmier —
 * l'injection posée à deux heures, la perfusion changée, la sonde placée,
 * l'oxygène branché, la toilette d'un grabataire — ne laissait aucune trace.
 *
 * Un acte non écrit est un acte qu'on refait ou qu'on oublie : à la relève,
 * l'équipe suivante ne sait pas si la deuxième injection a été faite. Et
 * c'est du travail qui ne se voit nulle part — ni dans l'activité du
 * service, ni dans ce qu'on peut montrer d'une nuit de garde.
 *
 * Le dossier de soins n'était par ailleurs gardé par rien : n'importe quel
 * compte connecté — la caisse, l'accueil — pouvait y inscrire un pansement.
 */
class ActesInfirmiersTest extends TestCase
{
    use RefreshDatabase;

    protected Establishment $etab;

    protected User $infirmier;

    protected User $medecin;

    protected User $caissier;

    protected Visit $visite;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->etab = Establishment::firstOrFail();

        $this->infirmier = $this->agent('infirmier', 'LOKO', 'INF-ACT-1');
        $this->medecin = $this->agent('medecin', 'MUYA', 'MED-ACT-1');
        $this->caissier = $this->agent('caissier', 'GUICHET', 'CAI-ACT-1');

        $patient = Patient::create([
            'establishment_id' => $this->etab->id,
            'dossier_number' => 'ACT-0001',
            'nom' => 'BOKETSHU', 'prenom' => 'Alain', 'sexe' => 'M',
            'date_naissance' => now()->subYears(52)->toDateString(),
            'type_prise_en_charge' => 'prive',
        ]);

        $this->visite = Visit::create([
            'patient_id' => $patient->id,
            'establishment_id' => $this->etab->id,
            'user_id' => $this->medecin->id,
            'type' => 'hospitalisation', 'statut' => 'en_cours',
            'date_entree' => now()->subDays(2),
            'motif_consultation' => 'Pneumonie',
        ]);
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

    protected function inscrire(array $champs = []): TestResponse
    {
        return $this->post(route('infirmier.actes', $this->visite), array_merge([
            'type' => 'injection_im',
            'precisions' => 'Ceftriaxone 1 g, fessier gauche',
        ], $champs));
    }

    // ═══════════════════════════════════════════════════════════
    // Ce que l'équipe fait, et qui ne se voyait nulle part
    // ═══════════════════════════════════════════════════════════

    public function test_le_registre_couvre_le_travail_reel_dune_equipe(): void
    {
        // Une liste courte à dessein : un catalogue de deux cents lignes ne
        // se parcourt pas à trois heures du matin.
        foreach ([
            'injection_im', 'injection_iv', 'perfusion_pose', 'sondage_vesical',
            'oxygenotherapie', 'aspiration', 'prelevement', 'nursing',
        ] as $type) {
            $this->assertArrayHasKey($type, ActeInfirmier::TYPES);
        }
    }

    public function test_linfirmier_inscrit_un_acte(): void
    {
        $this->actingAs($this->infirmier);

        $this->inscrire()->assertRedirect();

        $acte = ActeInfirmier::firstOrFail();
        $this->assertSame('injection_im', $acte->type);
        $this->assertSame('Injection intramusculaire', $acte->libelle);
        $this->assertStringContainsString('Ceftriaxone', $acte->precisions);
        $this->assertSame($this->visite->patient_id, $acte->patient_id);
    }

    public function test_lacte_porte_le_nom_de_qui_la_fait(): void
    {
        $this->actingAs($this->infirmier);
        $this->inscrire();

        // Ni l'auteur ni l'heure ne se choisissent : c'est l'agent connecté
        // et l'horloge. Une trace qu'on peut signer au nom d'un autre ne
        // vaut pas comme trace.
        $acte = ActeInfirmier::firstOrFail();
        $this->assertSame($this->infirmier->id, $acte->user_id);
        $this->assertTrue($acte->realise_a->isToday());
    }

    public function test_on_ne_signe_pas_au_nom_dun_autre(): void
    {
        $this->actingAs($this->infirmier);

        $this->inscrire(['user_id' => $this->medecin->id, 'realise_a' => '2020-01-01 03:00:00']);

        $acte = ActeInfirmier::firstOrFail();
        $this->assertSame($this->infirmier->id, $acte->user_id);
        $this->assertTrue($acte->realise_a->isToday());
    }

    public function test_lobservation_de_linfirmier_est_conservee(): void
    {
        $this->actingAs($this->infirmier);

        // C'est souvent là qu'une complication se voit avant tout le monde.
        $this->inscrire(['observation' => 'Point de ponction rouge et chaud.']);

        $this->assertStringContainsString(
            'rouge et chaud',
            ActeInfirmier::firstOrFail()->observation
        );
    }

    public function test_un_acte_invente_est_refuse(): void
    {
        $this->actingAs($this->infirmier);

        $this->inscrire(['type' => 'imposition_des_mains'])
            ->assertSessionHasErrors('type');

        $this->assertDatabaseCount('actes_infirmiers', 0);
    }

    public function test_un_soin_de_confort_ne_reclame_aucune_ordonnance(): void
    {
        $this->actingAs($this->infirmier);

        // Une toilette ne se prescrit pas : refuser l'acte faute d'ordonnance
        // reviendrait à ne pas le tracer.
        $this->inscrire(['type' => 'nursing', 'precisions' => 'Toilette complète, change'])
            ->assertRedirect();

        $this->assertDatabaseCount('actes_infirmiers', 1);
    }

    public function test_le_registre_se_lit_dans_le_dossier(): void
    {
        $this->actingAs($this->infirmier);
        $this->inscrire(['observation' => 'Bien toléré']);

        $this->get(route('infirmier.index', ['visit' => $this->visite->id, 'onglet' => 'actes']))
            ->assertOk()
            ->assertSee('Injection intramusculaire')
            ->assertSee('Ceftriaxone')
            ->assertSee('Bien toléré')
            ->assertSee('LOKO');
    }

    // ═══════════════════════════════════════════════════════════
    // Soigner n'est pas prescrire
    // ═══════════════════════════════════════════════════════════

    public function test_le_dossier_de_soins_nest_pas_ouvert_a_tout_le_monde(): void
    {
        // Il n'était gardé par rien : la caisse pouvait y inscrire un
        // pansement. Une trace que tout le monde peut écrire ne vaut rien.
        $this->actingAs($this->caissier);

        $this->inscrire()->assertForbidden();
        $this->assertDatabaseCount('actes_infirmiers', 0);
    }

    public function test_linfirmier_ne_prescrit_pas(): void
    {
        $this->actingAs($this->infirmier);

        $this->assertFalse($this->infirmier->can('prescription.create'));
        $this->assertFalse($this->infirmier->can('consultation.create'));
    }

    public function test_linfirmier_ne_consulte_pas(): void
    {
        $this->actingAs($this->infirmier);

        // Poser un diagnostic n'est pas son travail — et l'écran doit le
        // dire plutôt que de le laisser remplir un formulaire pour rien.
        $this->get(route('visites.consulter', $this->visite))->assertForbidden();
    }

    public function test_le_medecin_trace_aussi_ce_quil_fait_lui_meme(): void
    {
        // Un médecin de garde pose lui-même une voie à trois heures du
        // matin : lui refuser la trace serait la perdre.
        $this->actingAs($this->medecin);

        $this->inscrire(['type' => 'perfusion_pose', 'precisions' => 'Ringer 500 ml, 20 gouttes/min'])
            ->assertRedirect();

        $this->assertSame($this->medecin->id, ActeInfirmier::firstOrFail()->user_id);
    }

    public function test_linfirmier_garde_le_droit_de_lire_le_dossier(): void
    {
        // Séparer les rôles n'est pas aveugler l'équipe : on ne soigne pas
        // un patient dont on ne peut pas lire le dossier.
        $this->actingAs($this->infirmier)
            ->get(route('patients.show', $this->visite->patient))
            ->assertOk();

        $this->actingAs($this->infirmier)
            ->get(route('infirmier.index', ['visit' => $this->visite->id]))
            ->assertOk();
    }

    public function test_les_autres_soins_sont_gardes_de_la_meme_facon(): void
    {
        $this->actingAs($this->caissier);

        // Le pansement, le gavage et l'évaluation neurologique passaient par
        // les mêmes portes ouvertes.
        $this->post(route('infirmier.pansement', $this->visite), [
            'type_pansement' => 'simple', 'localisation' => 'Bras',
        ])->assertForbidden();

        $this->post(route('infirmier.gavage', $this->visite), [
            'produit' => 'Bouillie', 'volume_ml' => 200,
        ])->assertForbidden();
    }
}
