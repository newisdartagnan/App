<?php

namespace Tests\Feature;

use App\Models\Consultation;
use App\Models\ConsultationPrenatale;
use App\Models\Establishment;
use App\Models\ExamenFichier;
use App\Models\ExamenLaboratoire;
use App\Models\Grossesse;
use App\Models\Patient;
use App\Models\ResultatExamen;
use App\Models\TypeExamen;
use App\Models\User;
use App\Models\Visit;
use App\Services\AssemblagePdfService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Les pièces jointes d'un compte rendu.
 *
 * Annoncer une pièce en fin de document — « consultable dans le dossier du
 * patient » — revient à demander au prescripteur d'aller la chercher dans un
 * service où il n'entre pas. Une image s'incorpore, un PDF se relie à la
 * suite : dans les deux cas le document qu'il reçoit est complet.
 */
class PiecesJointesExamensTest extends TestCase
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
            'dossier_number' => 'PAT-2026-014000',
            'nom' => 'LOKOLE', 'prenom' => 'Serge', 'sexe' => 'M',
            'date_naissance' => now()->subYears(44)->toDateString(),
            'type_prise_en_charge' => 'prive',
        ]);
    }

    protected function examen(string $domaine = 'imagerie'): ExamenLaboratoire
    {
        $examen = ExamenLaboratoire::create([
            'numero_bon' => ($domaine === 'imagerie' ? 'IMG' : 'LAB').'-2026-00'.random_int(1000, 9999),
            'patient_id' => $this->patient->id,
            'prescripteur_id' => $this->admin->id,
            'laborantin_id' => $this->admin->id,
            'date_prescription' => now(),
            'date_resultat' => now(),
            'statut' => 'valide',
            'domaine' => $domaine,
            'conclusion' => 'Pas de lésion visible.',
        ]);

        $type = TypeExamen::where('domaine', $domaine)->firstOrFail();

        ResultatExamen::create([
            'examen_id' => $examen->id,
            'type_examen_id' => $type->id,
            'parametre' => $type->libelle,
            'valeur_brute' => 'Sans particularité',
            'valeurs_reference' => [],
        ]);

        return $examen->fresh();
    }

    /** Un vrai PDF, produit par le moteur de rendu de l'application. */
    protected function pdfDeTest(int $pages = 2): string
    {
        $html = '<html><body>';

        for ($i = 1; $i <= $pages; $i++) {
            $html .= '<div'.($i > 1 ? ' style="page-break-before: always;"' : '')
                .'>Page annexe '.$i.'</div>';
        }

        return $html.'</body></html>';
    }

    protected function joindrePdf(ExamenLaboratoire $examen, int $pages = 2, string $nom = 'Protocole.pdf'): ExamenFichier
    {
        $contenu = Pdf::loadHTML($this->pdfDeTest($pages))->setPaper('a4')->output();
        $chemin = 'examens/'.$examen->id.'/'.uniqid('piece-').'.pdf';

        Storage::disk('public')->put($chemin, $contenu);

        return ExamenFichier::create([
            'examen_id' => $examen->id,
            'nom_original' => $nom,
            'chemin' => $chemin,
            'type' => 'pdf',
            'description' => 'Protocole du constructeur',
            'ajoute_par' => $this->admin->id,
        ]);
    }

    protected function nombreDePages(string $pdf): int
    {
        $fichier = tempnam(sys_get_temp_dir(), 'test-pdf-').'.pdf';
        file_put_contents($fichier, $pdf);
        $pages = app(AssemblagePdfService::class)->nombreDePages($fichier);
        @unlink($fichier);

        return $pages ?? 0;
    }

    // ═══════════════════════════════════════════════════════════
    // La reliure
    // ═══════════════════════════════════════════════════════════

    public function test_loutil_de_reliure_est_present_sur_le_serveur(): void
    {
        // Sans lui, les pièces PDF ne peuvent qu'être annoncées.
        $this->assertTrue(app(AssemblagePdfService::class)->disponible(),
            'qpdf est absent : les pièces jointes PDF ne seront pas reliées au compte rendu.');
    }

    public function test_un_pdf_joint_est_reproduit_a_la_suite_du_compte_rendu(): void
    {
        $examen = $this->examen();

        $sansAnnexe = $this->get(route('examens.pdf', $examen))->assertOk()->getContent();
        $pagesSeul = $this->nombreDePages($sansAnnexe);

        $this->joindrePdf($examen, 3);

        $avecAnnexe = $this->get(route('examens.pdf', $examen))->assertOk()->getContent();

        $this->assertStringStartsWith('%PDF-', $avecAnnexe);
        // Le sommaire des annexes ajoute une page, puis les trois pages du
        // document joint : il est dans le document, non plus annoncé.
        $this->assertSame($pagesSeul + 1 + 3, $this->nombreDePages($avecAnnexe));
    }

    public function test_plusieurs_pdf_se_relient_dans_lordre(): void
    {
        $examen = $this->examen('labo');

        $this->joindrePdf($examen, 1, 'Premier.pdf');
        $this->joindrePdf($examen, 2, 'Second.pdf');

        $sans = $this->nombreDePages(
            Pdf::loadHTML('<html><body>x</body></html>')->output()
        );

        $contenu = $this->get(route('examens.pdf', $examen))->assertOk()->getContent();

        $this->assertGreaterThanOrEqual($sans + 3, $this->nombreDePages($contenu));
    }

    public function test_un_fichier_qui_nest_pas_un_pdf_ne_casse_pas_le_document(): void
    {
        $examen = $this->examen();

        // Une pièce annoncée PDF mais illisible : le compte rendu doit partir
        // quand même, amputé de cette annexe seulement.
        Storage::disk('public')->put('examens/'.$examen->id.'/corrompu.pdf', 'ceci nest pas un pdf');

        ExamenFichier::create([
            'examen_id' => $examen->id,
            'nom_original' => 'Corrompu.pdf',
            'chemin' => 'examens/'.$examen->id.'/corrompu.pdf',
            'type' => 'pdf',
            'ajoute_par' => $this->admin->id,
        ]);

        $contenu = $this->get(route('examens.pdf', $examen))->assertOk()->getContent();

        $this->assertStringStartsWith('%PDF-', $contenu);
        $this->assertGreaterThan(1000, strlen($contenu));
    }

    public function test_un_fichier_disparu_du_serveur_ne_casse_pas_le_document(): void
    {
        $examen = $this->examen();

        ExamenFichier::create([
            'examen_id' => $examen->id,
            'nom_original' => 'Envolé.pdf',
            'chemin' => 'examens/'.$examen->id.'/inexistant.pdf',
            'type' => 'pdf',
            'ajoute_par' => $this->admin->id,
        ]);

        $this->get(route('examens.pdf', $examen))->assertOk();
    }

    public function test_un_compte_rendu_sans_piece_reste_inchange(): void
    {
        $contenu = $this->get(route('examens.pdf', $this->examen()))->assertOk()->getContent();

        $this->assertStringStartsWith('%PDF-', $contenu);
    }

    public function test_la_reliure_sarrete_avant_de_rendre_le_document_inouvrable(): void
    {
        $principal = tempnam(sys_get_temp_dir(), 'test-').'.pdf';
        file_put_contents($principal, Pdf::loadHTML($this->pdfDeTest(1))->output());

        $enorme = tempnam(sys_get_temp_dir(), 'test-').'.pdf';
        file_put_contents($enorme, Pdf::loadHTML($this->pdfDeTest(450))->output());

        $relie = app(AssemblagePdfService::class)->relier($principal, [$enorme]);

        // 450 pages d'annexe ne partent pas au médecin de garde.
        $this->assertNull($relie);

        @unlink($principal);
        @unlink($enorme);
    }

    // ═══════════════════════════════════════════════════════════
    // L'accès aux pièces
    // ═══════════════════════════════════════════════════════════

    public function test_une_piece_souvre_par_lapplication(): void
    {
        $examen = $this->examen();
        $fichier = $this->joindrePdf($examen);

        $this->get(route('examens.piece', [$examen, $fichier]))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    public function test_une_piece_nest_pas_ouverte_par_un_agent_non_soignant(): void
    {
        $examen = $this->examen();
        $fichier = $this->joindrePdf($examen);

        $caissier = User::factory()->create(['establishment_id' => $this->etab->id]);
        $caissier->assignRole('caissier');

        // Une radiographie nominative n'est pas servie comme une feuille de style.
        $this->actingAs($caissier)
            ->get(route('examens.piece', [$examen, $fichier]))
            ->assertForbidden();
    }

    public function test_une_piece_dun_autre_examen_est_refusee(): void
    {
        $examen = $this->examen();
        $autre = $this->examen('labo');
        $fichier = $this->joindrePdf($autre);

        $this->get(route('examens.piece', [$examen, $fichier]))->assertNotFound();
    }

    public function test_lecran_du_laboratoire_pointe_vers_lapplication(): void
    {
        $examen = $this->examen();
        $this->joindrePdf($examen);

        $this->get(route('labo.show', $examen))
            ->assertOk()
            ->assertSee(route('examens.piece', [$examen, $examen->fichiers->first()]), false)
            ->assertDontSee('storage/examens', false);
    }

    public function test_une_image_reste_incorporee_au_document(): void
    {
        $examen = $this->examen();

        Storage::disk('public')->putFileAs(
            'examens/'.$examen->id,
            UploadedFile::fake()->image('cliche.jpg', 400, 300),
            'cliche.jpg'
        );

        ExamenFichier::create([
            'examen_id' => $examen->id,
            'nom_original' => 'Cliché.jpg',
            'chemin' => 'examens/'.$examen->id.'/cliche.jpg',
            'type' => 'image',
            'ajoute_par' => $this->admin->id,
        ]);

        $contenu = $this->get(route('examens.pdf', $examen))->assertOk()->getContent();

        $this->assertStringStartsWith('%PDF-', $contenu);
        // Une image incorporée pèse : le document grossit franchement.
        $this->assertGreaterThan(3000, strlen($contenu));
    }

    // ═══════════════════════════════════════════════════════════
    // Ce qui était saisi et jamais relu
    // ═══════════════════════════════════════════════════════════

    public function test_la_consultation_reaffiche_les_antecedents_et_les_traitements(): void
    {
        $visite = Visit::create([
            'patient_id' => $this->patient->id,
            'establishment_id' => $this->etab->id,
            'user_id' => $this->admin->id,
            'type' => 'consultation_externe',
            'statut' => 'en_cours',
            'date_entree' => now(),
            'motif_consultation' => 'Céphalées',
        ]);

        $consultation = Consultation::create([
            'visit_id' => $visite->id,
            'user_id' => $this->admin->id,
            'date_consultation' => now(),
            'antecedents_familiaux' => 'Père hypertendu, mère diabétique',
            'antecedents_chirurgicaux' => 'Appendicectomie en 2019',
            'traitements_en_cours' => ['Amlodipine 5 mg le matin'],
            'diagnostics' => [['libelle' => 'Céphalées de tension', 'type' => 'principal']],
            'statut' => 'finalise',
        ]);

        // Le confrère qui reprend le dossier prescrivait sans savoir ce que
        // le patient prend déjà.
        $this->get(route('consultations.show', $consultation))
            ->assertOk()
            ->assertSee('Père hypertendu')
            ->assertSee('Appendicectomie en 2019')
            ->assertSee('Amlodipine 5 mg le matin');
    }

    public function test_le_paquet_preventif_de_la_grossesse_se_relit(): void
    {
        $grossesse = Grossesse::create([
            'patient_id' => $this->patient->id,
            'establishment_id' => $this->etab->id,
            'user_id' => $this->admin->id,
            'gestite' => 3, 'parite' => 2, 'avortements' => 0,
            'date_dernieres_regles' => now()->subWeeks(24)->toDateString(),
            'date_prevue_accouchement' => now()->addWeeks(16)->toDateString(),
            'statut' => 'en_cours',
        ]);

        foreach ([[1, true, true, 1], [2, true, false, 2]] as [$numero, $fer, $sp, $vat]) {
            ConsultationPrenatale::create([
                'grossesse_id' => $grossesse->id,
                'user_id' => $this->admin->id,
                'date_consultation' => now()->subWeeks(4 * $numero),
                'numero' => $numero,
                'fer_folates' => $fer,
                'sulfadoxine_pyrimethamine' => $sp,
                'vat_dose' => $vat,
                'moustiquaire_remise' => false,
                'prochain_rendez_vous' => now()->addWeeks(4)->toDateString(),
                'conduite_a_tenir' => 'Revenir avec le carnet',
            ]);
        }

        $paquet = $grossesse->fresh()->paquetPreventif();

        $this->assertSame(2, $paquet['vat_dose']);
        $this->assertSame(1, $paquet['sp_recues']);
        $this->assertSame(2, $paquet['sp_restantes']);
        $this->assertSame(2, $paquet['fer_visites']);
        $this->assertFalse($paquet['moustiquaire']);
        $this->assertContains('Moustiquaire imprégnée non remise', $paquet['manques']);

        // La sage-femme redonnait ou oubliait, faute de pouvoir relire.
        $this->get(route('maternite.show', $grossesse))
            ->assertOk()
            ->assertSee('Paquet préventif')
            ->assertSee('2 dose(s) de SP à donner')
            ->assertSee('Moustiquaire imprégnée non remise')
            ->assertSee('Revenir avec le carnet')
            ->assertSee('Prochain rendez-vous');
    }

    public function test_la_nationalite_saisie_a_laccueil_se_relit(): void
    {
        $this->patient->update(['nationalite' => 'Angolaise']);

        $this->get(route('patients.show', $this->patient))
            ->assertOk()
            ->assertSee('Nationalité')
            ->assertSee('Angolaise');
    }
}
