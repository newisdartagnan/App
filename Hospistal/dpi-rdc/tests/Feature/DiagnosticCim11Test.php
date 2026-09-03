<?php

namespace Tests\Feature;

use App\Models\Consultation;
use App\Models\Establishment;
use App\Models\Patient;
use App\Models\ReferentielMedical;
use App\Models\User;
use App\Models\Visit;
use App\Services\DiagnosticService;
use App\Services\RapportSnisService;
use Database\Seeders\DiagnosticCim11Seeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Le diagnostic : codé en CIM-11, écrit une fois, repris partout.
 *
 * Deux choses.
 *
 * Le code demandé était le CIM-10, que l'OMS a remplacé. Le référentiel
 * embarqué ne prétend pas être la CIM-11 — dix-sept mille entrées n'ont pas
 * leur place sur un poste hors ligne — mais couvre ce qu'on voit réellement
 * passer en RDC. Et il ne ferme rien : ce qui n'y figure pas s'écrit à la
 * main, sans code, et le dossier le garde tel quel.
 *
 * Ensuite, le libellé se retapait à chaque écran : consultation, bon
 * d'examen, transfusion, bulletin de sortie. Quatre saisies pour une idée,
 * et quatre occasions de diverger.
 */
class DiagnosticCim11Test extends TestCase
{
    use RefreshDatabase;

    protected User $medecin;

    protected Establishment $etab;

    protected Visit $visite;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->etab = Establishment::firstOrFail();

        $this->medecin = User::create([
            'establishment_id' => $this->etab->id,
            'nom' => 'KABEYA', 'prenom' => 'Anne', 'matricule' => 'MED-CIM-1',
            'password' => 'motdepasse123', 'is_active' => true,
        ]);
        $this->medecin->assignRole('medecin');
        $this->actingAs($this->medecin);

        $patient = Patient::create([
            'establishment_id' => $this->etab->id,
            'dossier_number' => 'CIM-0001',
            'nom' => 'MBALA', 'prenom' => 'Divine', 'sexe' => 'F',
            'date_naissance' => now()->subYears(4)->toDateString(),
            'type_prise_en_charge' => 'prive',
        ]);

        $this->visite = Visit::create([
            'patient_id' => $patient->id,
            'establishment_id' => $this->etab->id,
            'user_id' => $this->medecin->id,
            'type' => 'consultation_externe', 'statut' => 'en_cours',
            'date_entree' => now()->subHour(),
            'motif_consultation' => 'Fièvre depuis 3 jours',
            // Le médecin ne consulte qu'après passage en caisse.
            'gratuite' => true,
        ]);
    }

    protected function service(): DiagnosticService
    {
        return app(DiagnosticService::class);
    }

    protected function consulterAvec(array $diagnostics): void
    {
        Consultation::create([
            'visit_id' => $this->visite->id,
            'user_id' => $this->medecin->id,
            'date_consultation' => now(),
            'diagnostics' => $diagnostics,
            'statut' => 'finalise',
        ]);
    }

    // ═══════════════════════════════════════════════════════════
    // Le référentiel
    // ═══════════════════════════════════════════════════════════

    public function test_les_pathologies_courantes_en_rdc_sont_proposees(): void
    {
        $codes = ReferentielMedical::where('type', 'diagnostic')->pluck('code');

        // Ce qui remplit les salles d'attente ici.
        foreach (['1F40', '1B10', '1C62', '1A07', '5B50', '3A51'] as $attendu) {
            $this->assertContains($attendu, $codes->all(), "Le code {$attendu} manque au référentiel.");
        }

        $this->assertGreaterThan(50, $codes->count());
    }

    public function test_les_codes_sont_marques_a_verifier(): void
    {
        // Un code posé d'après la classification n'est pas un code relu par
        // un médecin de la maison. Un faux code dans un rapport se propage
        // sans qu'on le voie : mieux vaut le doute affiché.
        $this->assertFalse(
            (bool) ReferentielMedical::where('type', 'diagnostic')->where('code', '1F40')->value('code_verifie')
        );
    }

    public function test_le_referentiel_se_cherche_par_ce_quon_tape(): void
    {
        // Personne ne tape « Paludisme à Plasmodium falciparum ».
        $palu = ReferentielMedical::where('code', '1F40')->firstOrFail();

        $this->assertStringContainsString('palu', $palu->synonymes);
    }

    public function test_le_socle_ne_recrase_pas_une_correction_de_medecin(): void
    {
        ReferentielMedical::where('code', '1F40')->update([
            'libelle' => 'Paludisme à P. falciparum (corrigé)',
            'code_verifie' => true,
        ]);

        $this->seed(DiagnosticCim11Seeder::class);

        // Le seeder tourne à chaque déploiement : il ne doit pas défaire ce
        // qu'un médecin a relu.
        $palu = ReferentielMedical::where('code', '1F40')->firstOrFail();
        $this->assertSame('Paludisme à P. falciparum (corrigé)', $palu->libelle);
        $this->assertTrue($palu->code_verifie);
    }

    // ═══════════════════════════════════════════════════════════
    // Un seul champ, code compris
    // ═══════════════════════════════════════════════════════════

    public function test_le_choix_dans_la_liste_separe_le_libelle_du_code(): void
    {
        $pose = $this->service()->decomposer('Paludisme grave (1F45)');

        $this->assertSame('Paludisme grave', $pose['libelle']);
        $this->assertSame('1F45', $pose['code_cim11']);
    }

    public function test_une_saisie_libre_reste_intacte(): void
    {
        // Un catalogue incomplet ne doit jamais empêcher de poser un
        // diagnostic : le cas rare s'écrit en toutes lettres.
        $pose = $this->service()->decomposer('Suspicion de dengue');

        $this->assertSame('Suspicion de dengue', $pose['libelle']);
        $this->assertNull($pose['code_cim11']);
    }

    public function test_une_parenthese_qui_nest_pas_un_code_ne_devient_pas_un_code(): void
    {
        $pose = $this->service()->decomposer('Paludisme (forme grave du nourrisson)');

        $this->assertSame('Paludisme (forme grave du nourrisson)', $pose['libelle']);
        $this->assertNull($pose['code_cim11']);
    }

    public function test_un_code_seul_retrouve_son_libelle(): void
    {
        $pose = $this->service()->decomposer('1F40');

        $this->assertSame('Paludisme à Plasmodium falciparum', $pose['libelle']);
        $this->assertSame('1F40', $pose['code_cim11']);
    }

    public function test_le_formulaire_noffre_plus_deux_champs_pour_une_idee(): void
    {
        $page = $this->get(route('visites.consulter', $this->visite))->assertOk();

        $page->assertSee('list="cim11-diagnostics"', false);
        $page->assertSee('cim11-diagnostics', false);
        // Le second champ « code » a disparu : le code est dans la liste.
        $page->assertDontSee('diagnostics[0][code_cim10]', false);
        $page->assertSee('Paludisme grave (1F45)', false);
    }

    public function test_la_consultation_enregistre_le_code_cim11(): void
    {
        $this->post(route('visites.consultation.store', $this->visite), [
            'diagnostics' => [
                ['libelle' => 'Paludisme grave (1F45)'],
                ['libelle' => 'Anémie, sans précision (3A9Z)'],
            ],
        ])->assertRedirect();

        $diagnostics = Consultation::firstOrFail()->diagnostics;

        $this->assertSame('Paludisme grave', $diagnostics[0]['libelle']);
        $this->assertSame('1F45', $diagnostics[0]['code_cim11']);
        $this->assertSame('principal', $diagnostics[0]['type']);
        $this->assertSame('3A9Z', $diagnostics[1]['code_cim11']);
        $this->assertSame('associe', $diagnostics[1]['type']);
    }

    public function test_un_diagnostic_libre_senregistre_sans_code(): void
    {
        $this->post(route('visites.consultation.store', $this->visite), [
            'diagnostics' => [['libelle' => 'Fièvre au retour de Kikwit, à explorer']],
        ])->assertRedirect();

        $diagnostic = Consultation::firstOrFail()->diagnostics[0];

        $this->assertSame('Fièvre au retour de Kikwit, à explorer', $diagnostic['libelle']);
        $this->assertNull($diagnostic['code_cim11']);
    }

    // ═══════════════════════════════════════════════════════════
    // Le diagnostic suit l'épisode
    // ═══════════════════════════════════════════════════════════

    public function test_le_diagnostic_du_sejour_se_lit_dune_seule_ligne(): void
    {
        $this->consulterAvec([
            ['libelle' => 'Anémie', 'code_cim11' => '3A9Z', 'type' => 'associe'],
            ['libelle' => 'Paludisme grave', 'code_cim11' => '1F45', 'type' => 'principal'],
        ]);

        // Le principal d'abord : c'est celui qu'on reprend.
        $this->assertSame(
            'Paludisme grave (1F45) · Anémie (3A9Z)',
            $this->service()->pourIndication($this->visite->fresh())
        );
        $this->assertSame('Paludisme grave', $this->service()->principal($this->visite->fresh()));
    }

    public function test_un_sejour_sans_consultation_ne_reprend_rien(): void
    {
        $this->assertNull($this->service()->pourIndication($this->visite));
        $this->assertNull($this->service()->pourIndication(null));
    }

    public function test_la_demande_dexamen_reprend_le_diagnostic(): void
    {
        $this->consulterAvec([['libelle' => 'Paludisme grave', 'code_cim11' => '1F45', 'type' => 'principal']]);

        // Le laboratoire interprète mieux un résultat quand il sait ce
        // qu'on cherche.
        $this->get(route('labo.create', ['visit_id' => $this->visite->id, 'domaine' => 'labo']))
            ->assertOk()
            ->assertSee('Paludisme grave (1F45)')
            ->assertSee('Repris du diagnostic');
    }

    public function test_le_bulletin_de_sortie_reprend_le_diagnostic(): void
    {
        $this->consulterAvec([['libelle' => 'Paludisme grave', 'code_cim11' => '1F45', 'type' => 'principal']]);

        // L'écran de sortie n'existe que pour un séjour qu'on clôt à la main :
        // l'ambulatoire se ferme seul en fin de journée.
        $this->visite->update(['type' => 'hospitalisation']);

        $this->get(route('visites.show', $this->visite))
            ->assertOk()
            ->assertSee('Paludisme grave (1F45)');
    }

    public function test_la_demande_dacte_reprend_le_diagnostic(): void
    {
        $this->consulterAvec([['libelle' => 'Appendicite aiguë', 'code_cim11' => 'DC11', 'type' => 'principal']]);

        $this->get(route('examens-specialises.create', ['visit_id' => $this->visite->id]))
            ->assertOk()
            ->assertSee('Appendicite aiguë (DC11)');
    }

    public function test_la_reprise_se_corrige_et_ne_simpose_pas(): void
    {
        $this->consulterAvec([['libelle' => 'Paludisme grave', 'code_cim11' => '1F45', 'type' => 'principal']]);

        // Un examen peut se demander pour autre chose que le diagnostic
        // principal : le champ reste libre et la saisie l'emporte.
        $this->from(route('labo.create', ['visit_id' => $this->visite->id, 'domaine' => 'labo']))
            ->withSession(['_old_input' => ['observations' => 'Bilan préopératoire']])
            ->get(route('labo.create', ['visit_id' => $this->visite->id, 'domaine' => 'labo']))
            ->assertOk()
            ->assertSee('Bilan préopératoire')
            ->assertDontSee('Paludisme grave (1F45)');
    }

    // ═══════════════════════════════════════════════════════════
    // L'ancien code n'est pas perdu
    // ═══════════════════════════════════════════════════════════

    public function test_un_dossier_ancien_garde_son_code_cim10(): void
    {
        $this->consulterAvec([['libelle' => 'Paludisme', 'code_cim10' => 'B50', 'type' => 'principal']]);

        // Un dossier ne se réécrit pas.
        $this->assertSame('B50', $this->service()->code(
            $this->service()->diagnosticsDuSejour($this->visite->fresh())[0]
        ));
    }

    public function test_le_rapport_snis_classe_les_deux_nomenclatures(): void
    {
        $service = app(RapportSnisService::class);
        $methode = new \ReflectionMethod($service, 'pathologieDe');

        // Le classement par code doit valoir pour l'ancien comme pour le
        // nouveau, sinon l'historique se viderait du jour au lendemain.
        $this->assertSame('paludisme', $methode->invoke($service, 'Sans libellé utile', 'B50'));
        $this->assertSame('paludisme', $methode->invoke($service, 'Sans libellé utile', '1F45'));
        $this->assertSame('tuberculose', $methode->invoke($service, 'Sans libellé utile', '1B10'));
    }
}
