<?php

namespace Tests\Feature;

use App\Models\Accouchement;
use App\Models\Establishment;
use App\Models\Grossesse;
use App\Models\Patient;
use App\Models\User;
use App\Services\MaterniteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Maternité : fiche obstétricale, suivi prénatal, accouchement.
 *
 * Le point sensible est le calcul du terme et les alertes de la consultation
 * prénatale : c'est là que se joue le dépistage de la pré-éclampsie.
 */
class MaterniteTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Establishment $etab;

    protected Patient $mere;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->admin = User::where('email', 'admin@dpi-rdc.local')->firstOrFail();
        $this->etab = Establishment::firstOrFail();
        $this->actingAs($this->admin);

        $this->mere = Patient::create([
            'establishment_id' => $this->etab->id,
            'dossier_number' => 'PAT-2026-010100',
            'nom' => 'KABILA', 'postnom' => 'Mwamba', 'prenom' => 'Blandine',
            'sexe' => 'F',
            'date_naissance' => now()->subYears(28)->toDateString(),
            'type_prise_en_charge' => 'prive',
            'groupe_sanguin' => 'O+',
        ]);
    }

    protected function grossesse(int $semaines = 39): Grossesse
    {
        return app(MaterniteService::class)->ouvrirGrossesse($this->mere, [
            'date_dernieres_regles' => now()->subWeeks($semaines)->toDateString(),
            'gestite' => 3,
            'parite' => 2,
            'avortements' => 0,
        ]);
    }

    // ═══════════════════════════════════════════════════════════
    // Ouverture de la fiche
    // ═══════════════════════════════════════════════════════════

    public function test_la_date_prevue_se_calcule_selon_la_regle_de_naegele(): void
    {
        $grossesse = app(MaterniteService::class)->ouvrirGrossesse($this->mere, [
            'date_dernieres_regles' => '2026-01-01',
            'gestite' => 1, 'parite' => 0,
        ]);

        // Dernières règles + 280 jours.
        $this->assertSame('2026-10-08', $grossesse->date_prevue_accouchement->toDateString());
        $this->assertSame('G1 P0 A0', $grossesse->formuleObstetricale());
    }

    public function test_le_terme_se_compte_en_semaines_damenorrhee(): void
    {
        $grossesse = $this->grossesse(32);

        $this->assertSame(32, $grossesse->termeSemaines());
    }

    public function test_une_patiente_na_quune_grossesse_en_cours(): void
    {
        $premiere = $this->grossesse();

        $this->post(route('maternite.grossesses.store'), [
            'patient_id' => $this->mere->id,
            'gestite' => 4, 'parite' => 3,
        ])->assertRedirect()->assertSessionHas('info');

        $this->assertSame(1, Grossesse::where('patient_id', $this->mere->id)->count());
        $this->assertSame(3, $premiere->fresh()->gestite);
    }

    public function test_une_fiche_obstetricale_ne_souvre_pas_pour_un_homme(): void
    {
        $homme = Patient::create([
            'establishment_id' => $this->etab->id,
            'dossier_number' => 'PAT-2026-010101',
            'nom' => 'MUKENDI', 'prenom' => 'Pascal', 'sexe' => 'M',
            'date_naissance' => now()->subYears(40)->toDateString(),
            'type_prise_en_charge' => 'prive',
        ]);

        $this->post(route('maternite.grossesses.store'), [
            'patient_id' => $homme->id, 'gestite' => 1, 'parite' => 0,
        ])->assertRedirect()->assertSessionHas('error');

        $this->assertSame(0, Grossesse::count());
    }

    // ═══════════════════════════════════════════════════════════
    // Consultation prénatale
    // ═══════════════════════════════════════════════════════════

    public function test_une_consultation_normale_ne_declenche_aucune_alerte(): void
    {
        $grossesse = $this->grossesse(28);

        $this->post(route('maternite.cpn', $grossesse), [
            'poids_kg' => 65,
            'tension_systolique' => 115,
            'tension_diastolique' => 70,
            'hauteur_uterine_cm' => 26,
            'bruits_coeur_foetal' => 140,
            'hemoglobine' => 12.5,
            'albuminurie' => 'negatif',
        ])->assertRedirect()->assertSessionHas('success');

        $consultation = $grossesse->consultations()->firstOrFail();

        $this->assertSame(1, $consultation->numero);
        $this->assertSame(28, $consultation->terme_semaines);
        $this->assertSame([], $consultation->alertes());
    }

    public function test_une_tension_elevee_avec_albuminurie_fait_penser_a_la_preeclampsie(): void
    {
        $grossesse = $this->grossesse(34);

        $this->post(route('maternite.cpn', $grossesse), [
            'tension_systolique' => 150,
            'tension_diastolique' => 95,
            'albuminurie' => '++',
            'hemoglobine' => 9.8,
            'bruits_coeur_foetal' => 140,
        ])->assertRedirect()->assertSessionHas('error');

        $alertes = $grossesse->consultations()->firstOrFail()->alertes();

        $this->assertCount(3, $alertes);
        $this->assertStringContainsString('pré-éclampsie', $alertes[0]);
        $this->assertStringContainsString('Albuminurie', $alertes[1]);
        $this->assertStringContainsString('Anémie', $alertes[2]);
    }

    public function test_un_rythme_cardiaque_foetal_anormal_est_signale(): void
    {
        $grossesse = $this->grossesse(36);

        $this->post(route('maternite.cpn', $grossesse), ['bruits_coeur_foetal' => 95]);

        $this->assertStringContainsString(
            'Rythme cardiaque fœtal anormal',
            implode(' ', $grossesse->consultations()->firstOrFail()->alertes())
        );
    }

    public function test_les_consultations_se_numerotent_dans_lordre(): void
    {
        $grossesse = $this->grossesse(20);

        foreach ([20, 24, 28] as $terme) {
            $this->post(route('maternite.cpn', $grossesse), ['poids_kg' => 60 + $terme / 10]);
        }

        $this->assertSame([1, 2, 3], $grossesse->consultations()->pluck('numero')->all());
    }

    public function test_une_grossesse_close_naccepte_plus_de_consultation(): void
    {
        $grossesse = $this->grossesse();
        $grossesse->update(['statut' => 'accouchee']);

        $this->post(route('maternite.cpn', $grossesse), ['poids_kg' => 70])
            ->assertSessionHas('error');

        $this->assertSame(0, $grossesse->consultations()->count());
    }

    // ═══════════════════════════════════════════════════════════
    // Accouchement
    // ═══════════════════════════════════════════════════════════

    /** @param array<int, array<string, mixed>> $enfants */
    protected function accoucher(Grossesse $grossesse, array $donnees = [], ?array $enfants = null): Accouchement
    {
        $this->post(route('maternite.accouchement', $grossesse), [
            'date_accouchement' => now()->format('Y-m-d\TH:i'),
            'mode' => 'voie_basse',
            'etat_mere' => 'bon',
            'enfants' => $enfants ?? [['sexe' => 'F', 'poids_g' => 3200, 'apgar_1' => 9, 'apgar_5' => 10, 'statut' => 'vivant']],
            ...$donnees,
        ])->assertRedirect();

        return $grossesse->fresh('accouchement')->accouchement;
    }

    public function test_laccouchement_clot_la_grossesse_et_ouvre_le_dossier_de_lenfant(): void
    {
        $grossesse = $this->grossesse();

        $accouchement = $this->accoucher($grossesse);
        $grossesse->refresh();

        $this->assertSame('accouchee', $grossesse->statut);
        // La parité de la mère avance d'un cran, ses enfants vivants aussi.
        $this->assertSame(3, $grossesse->parite);
        $this->assertSame(1, $grossesse->enfants_vivants);

        $enfant = $accouchement->nouveauNes->first();
        $this->assertNotNull($enfant->patient_id);
        $this->assertSame('KABILA', $enfant->patient->nom);
        $this->assertSame('Mère', $enfant->patient->contact_urgence_lien);
        $this->assertSame(
            $accouchement->date_accouchement->toDateString(),
            $enfant->patient->date_naissance->toDateString()
        );
    }

    public function test_un_mort_ne_est_declare_sans_dossier_de_patient(): void
    {
        $grossesse = $this->grossesse();

        $accouchement = $this->accoucher($grossesse, [], [
            ['sexe' => 'M', 'poids_g' => 2800, 'statut' => 'mort_ne'],
        ]);

        $enfant = $accouchement->nouveauNes->first();

        $this->assertSame('mort_ne', $enfant->statut);
        $this->assertNull($enfant->patient_id);
        $this->assertSame(0, $grossesse->fresh()->enfants_vivants);
    }

    public function test_une_grossesse_gemellaire_inscrit_les_deux_enfants(): void
    {
        $grossesse = $this->grossesse();

        $accouchement = $this->accoucher($grossesse, [], [
            ['sexe' => 'F', 'poids_g' => 2350, 'apgar_5' => 9, 'statut' => 'vivant'],
            ['sexe' => 'M', 'poids_g' => 2100, 'apgar_1' => 3, 'apgar_5' => 5, 'statut' => 'vivant'],
        ]);

        $this->assertTrue($accouchement->estMultiple());
        $this->assertSame([1, 2], $accouchement->nouveauNes->pluck('rang')->all());
        $this->assertSame(2, $grossesse->fresh()->enfants_vivants);

        // Deux petits poids, dont un en souffrance néonatale.
        $this->assertTrue($accouchement->nouveauNes[0]->estPetitPoids());
        $this->assertFalse($accouchement->nouveauNes[0]->souffranceNeonatale());
        $this->assertTrue($accouchement->nouveauNes[1]->souffranceNeonatale());
    }

    public function test_une_hemorragie_de_la_delivrance_est_reconnue(): void
    {
        $grossesse = $this->grossesse();

        $accouchement = $this->accoucher($grossesse, ['saignement_ml' => 650]);

        $this->assertTrue($accouchement->estHemorragique());

        // Après césarienne, le seuil est de mille millilitres.
        $autre = new Accouchement(['mode' => 'cesarienne', 'saignement_ml' => 650]);
        $this->assertFalse($autre->estHemorragique());
    }

    public function test_un_accouchement_sans_enfant_est_refuse(): void
    {
        $grossesse = $this->grossesse();

        $this->post(route('maternite.accouchement', $grossesse), [
            'date_accouchement' => now()->format('Y-m-d\TH:i'),
            'mode' => 'voie_basse',
            'etat_mere' => 'bon',
            'enfants' => [['sexe' => '', 'poids_g' => '']],
        ])->assertSessionHas('error');

        $this->assertSame('en_cours', $grossesse->fresh()->statut);
    }

    public function test_une_grossesse_deja_close_ne_saccouche_pas_deux_fois(): void
    {
        $grossesse = $this->grossesse();
        $this->accoucher($grossesse);

        $this->post(route('maternite.accouchement', $grossesse), [
            'date_accouchement' => now()->format('Y-m-d\TH:i'),
            'mode' => 'cesarienne',
            'etat_mere' => 'bon',
            'enfants' => [['sexe' => 'M', 'poids_g' => 3000]],
        ])->assertSessionHas('error');

        $this->assertSame(1, $grossesse->fresh()->accouchement()->count());
    }

    // ═══════════════════════════════════════════════════════════
    // Registre
    // ═══════════════════════════════════════════════════════════

    public function test_le_registre_compte_ce_que_reclame_le_rapport_mensuel(): void
    {
        $grossesse = $this->grossesse(35);
        $this->accoucher($grossesse, ['mode' => 'cesarienne', 'saignement_ml' => 1200], [
            ['sexe' => 'F', 'poids_g' => 2100, 'apgar_5' => 8, 'statut' => 'vivant'],
            ['sexe' => 'M', 'poids_g' => 2000, 'statut' => 'mort_ne'],
        ]);

        $indicateurs = app(MaterniteService::class)->indicateurs(
            now()->startOfMonth()->toDateString(),
            now()->toDateString()
        );

        $this->assertSame(1, $indicateurs['accouchements']);
        $this->assertSame(1, $indicateurs['cesariennes']);
        $this->assertSame(1, $indicateurs['prematures']);
        $this->assertSame(1, $indicateurs['hemorragies']);
        $this->assertSame(2, $indicateurs['naissances']);
        $this->assertSame(1, $indicateurs['vivants']);
        $this->assertSame(1, $indicateurs['mort_nes']);
        $this->assertSame(2, $indicateurs['petit_poids']);

        $this->get(route('maternite.registre'))
            ->assertOk()
            ->assertSee('KABILA')
            ->assertSee('Césarienne')
            ->assertSee('Mort-nés');
    }

    public function test_la_fiche_imprimable_porte_le_suivi_et_laccouchement(): void
    {
        $grossesse = $this->grossesse();
        $this->post(route('maternite.cpn', $grossesse), [
            'tension_systolique' => 150, 'tension_diastolique' => 95, 'albuminurie' => '++',
        ]);
        $this->accoucher($grossesse, ['saignement_ml' => 700]);

        $this->get(route('maternite.fiche', $grossesse))
            ->assertOk()
            ->assertSee('Fiche obstétricale')
            ->assertSee('G3 P3 A0')
            ->assertSee('150/95 mmHg')
            ->assertSee('hémorragie de la délivrance')
            ->assertSee('Prise en charge');
    }
}
