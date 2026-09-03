<?php

namespace Tests\Feature;

use App\Models\Consultation;
use App\Models\Establishment;
use App\Models\LignePrescription;
use App\Models\Medicament;
use App\Models\Patient;
use App\Models\Prescription;
use App\Models\User;
use App\Models\Visit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * L'ordonnance : la voie, la quantité qu'on voit, et les consignes au patient.
 *
 * Trois manques que la prescription quotidienne fait sentir.
 *
 * La voie suivait la fiche produit sans qu'on puisse la dire — or le
 * métronidazole se donne per os ou en perfusion, et c'est le prescripteur
 * qui tranche.
 *
 * La quantité affichait « auto » : le médecin signait un nombre qu'il ne
 * voyait pas, et le découvrait au comptoir.
 *
 * Enfin, une seule case d'instructions mélangeait ce qui tient à la
 * molécule et ce qui tient au patient. « Boire trois litres d'eau » écrit
 * dans la case d'un médicament disparaît avec ce médicament.
 */
class OrdonnanceVoieEtConsignesTest extends TestCase
{
    use RefreshDatabase;

    protected User $medecin;

    protected Establishment $etab;

    protected Consultation $consultation;

    protected Medicament $medicament;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->etab = Establishment::firstOrFail();

        $this->medecin = User::create([
            'establishment_id' => $this->etab->id,
            'nom' => 'KALALA', 'prenom' => 'Pierre', 'matricule' => 'MED-ORD-1',
            'password' => 'motdepasse123', 'is_active' => true,
        ]);
        $this->medecin->assignRole('medecin');
        $this->actingAs($this->medecin);

        $patient = Patient::create([
            'establishment_id' => $this->etab->id,
            'dossier_number' => 'ORD-0001',
            'nom' => 'ILUNGA', 'prenom' => 'Sarah', 'sexe' => 'F',
            'date_naissance' => now()->subYears(31)->toDateString(),
            'type_prise_en_charge' => 'prive',
        ]);

        $visite = Visit::create([
            'patient_id' => $patient->id,
            'establishment_id' => $this->etab->id,
            'user_id' => $this->medecin->id,
            'type' => 'consultation_externe', 'statut' => 'en_cours',
            'date_entree' => now()->subHour(),
            'motif_consultation' => 'Fièvre',
        ]);

        $this->consultation = Consultation::create([
            'visit_id' => $visite->id,
            'user_id' => $this->medecin->id,
            'date_consultation' => now(),
            'diagnostics' => [['libelle' => 'Amibiase', 'type' => 'principal']],
            'statut' => 'finalise',
        ]);

        $this->medicament = Medicament::where('voie_administration', 'orale')->firstOrFail();
    }

    /** @param  array<string, mixed>  $ligne */
    protected function prescrire(array $ligne, array $entete = []): TestResponse
    {
        return $this->post(route('prescriptions.store', $this->consultation), array_merge([
            'lignes' => [array_merge([
                'medicament_id' => $this->medicament->id,
                'dose' => 2, 'frequence' => 3, 'duree_jours' => 5,
            ], $ligne)],
        ], $entete));
    }

    // ═══════════════════════════════════════════════════════════
    // La voie, dite par le prescripteur
    // ═══════════════════════════════════════════════════════════

    public function test_le_formulaire_offre_la_voie_a_cote_de_la_quantite(): void
    {
        $page = $this->get(route('prescriptions.create', $this->consultation))->assertOk();

        $page->assertSee('name="lignes[0][voie_administration]"', false);
        $page->assertSee('Voie');

        // « À côté de la quantité » : les deux champs se suivent dans la
        // même rangée, la voie après le nombre.
        $html = $page->getContent();
        $this->assertLessThan(
            strpos($html, 'lignes[0][voie_administration]'),
            strpos($html, 'lignes[0][quantite_totale]')
        );
    }

    public function test_la_voie_choisie_lemporte_sur_celle_du_produit(): void
    {
        // Le même produit se donne per os ou en perfusion.
        $this->assertSame('orale', $this->medicament->voie_administration);

        $this->prescrire(['voie_administration' => 'injectable'])->assertRedirect();

        $this->assertSame('injectable', LignePrescription::firstOrFail()->voie_administration);
    }

    public function test_sans_choix_la_voie_reste_celle_du_produit(): void
    {
        $this->prescrire(['voie_administration' => ''])->assertRedirect();

        $this->assertSame('orale', LignePrescription::firstOrFail()->voie_administration);
    }

    public function test_une_voie_inventee_est_refusee(): void
    {
        $this->prescrire(['voie_administration' => 'par_la_pensee'])
            ->assertSessionHasErrors('lignes.0.voie_administration');

        $this->assertDatabaseCount('lignes_prescription', 0);
    }

    public function test_la_voie_prescrite_figure_sur_lordonnance(): void
    {
        $this->prescrire(['voie_administration' => 'injectable']);

        $this->get(route('prescriptions.ordonnance', Prescription::firstOrFail()))
            ->assertOk()
            ->assertSee('injectable');
    }

    // ═══════════════════════════════════════════════════════════
    // La quantité qu'on voit
    // ═══════════════════════════════════════════════════════════

    public function test_le_champ_quantite_ne_dit_plus_seulement_auto(): void
    {
        $page = $this->get(route('prescriptions.create', $this->consultation))->assertOk();

        // Le mot « auto » ne remplaçait pas le nombre : il le cachait.
        $page->assertDontSee('placeholder="auto"', false);
        $page->assertSee('data-quantite-de="0"', false);
        $page->assertSee('data-detail-quantite="0"', false);
    }

    public function test_le_calcul_est_branche_sans_script_en_ligne(): void
    {
        $page = $this->get(route('prescriptions.create', $this->consultation))->assertOk();

        $page->assertDontSee('onchange=', false);
        $page->assertDontSee('oninput=', false);

        $js = file_get_contents(resource_path('js/app.js'));
        $this->assertStringContainsString('data-quantite-de', $js);
        $this->assertStringContainsString('brancherLeCalculDesQuantites', $js);
    }

    public function test_la_quantite_reste_calculee_par_le_serveur(): void
    {
        // Le navigateur montre le nombre ; le serveur reste la référence,
        // même si le poste bloque les scripts.
        $this->prescrire([]);

        $this->assertSame(30.0, (float) LignePrescription::firstOrFail()->quantite_totale);
    }

    public function test_le_prescripteur_garde_le_dernier_mot_sur_la_quantite(): void
    {
        $this->prescrire(['quantite_totale' => 20]);

        $this->assertSame(20.0, (float) LignePrescription::firstOrFail()->quantite_totale);
    }

    // ═══════════════════════════════════════════════════════════
    // Les consignes liées au patient, pas au produit
    // ═══════════════════════════════════════════════════════════

    public function test_lordonnance_porte_une_case_de_consignes_distincte(): void
    {
        $this->get(route('prescriptions.create', $this->consultation))
            ->assertOk()
            ->assertSee('Consignes liées à l')
            ->assertSee('name="consignes_patient"', false)
            // Les deux cases coexistent et ne disent pas la même chose.
            ->assertSee('name="lignes[0][instructions]"', false)
            ->assertSee('note du prescripteur');
    }

    public function test_les_consignes_se_conservent_et_sont_distinctes_des_instructions(): void
    {
        $this->prescrire(
            ['instructions' => 'À prendre après le repas'],
            ['consignes_patient' => 'Boire 3 litres d\'eau par jour. Revenir si la fièvre dépasse 39 °C.']
        );

        $prescription = Prescription::firstOrFail();
        $ligne = LignePrescription::firstOrFail();

        $this->assertStringContainsString('Boire 3 litres', $prescription->consignes_patient);
        $this->assertSame('À prendre après le repas', $ligne->instructions);

        // Ce qui tient au patient ne doit pas se ranger dans la note du
        // prescripteur, qui est autre chose.
        $this->assertNull($prescription->observations);
    }

    public function test_les_consignes_sont_mises_en_evidence_sur_le_papier(): void
    {
        // C'est le papier que le patient emporte : un proche doit pouvoir
        // les lire d'un coup d'œil.
        $this->prescrire([], ['consignes_patient' => 'Boire 3 litres d\'eau par jour.']);

        $this->get(route('prescriptions.ordonnance', Prescription::firstOrFail()))
            ->assertOk()
            ->assertSee('Consignes pour le patient')
            ->assertSee('Boire 3 litres');
    }

    public function test_une_ordonnance_sans_consigne_nimprime_pas_de_cadre_vide(): void
    {
        $this->prescrire([]);

        $this->get(route('prescriptions.ordonnance', Prescription::firstOrFail()))
            ->assertOk()
            ->assertDontSee('Consignes pour le patient');
    }

    public function test_les_consignes_ne_debordent_pas(): void
    {
        $this->prescrire([], ['consignes_patient' => str_repeat('a', 1001)])
            ->assertSessionHasErrors('consignes_patient');
    }
}
