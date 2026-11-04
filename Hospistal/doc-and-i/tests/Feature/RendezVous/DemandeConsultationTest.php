<?php

namespace Tests\Feature\RendezVous;

use App\Models\DemandeConsultation;
use App\Models\PieceJointeDemande;
use App\Models\RendezVous;
use App\Services\RendezVous\QuestionnaireTri;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Support\ParcoursRendezVous;
use Tests\TestCase;

/** RDV-01 (demande, pièces jointes) et RDV-02 (tri des signes d'alerte). */
class DemandeConsultationTest extends TestCase
{
    use ParcoursRendezVous, RefreshDatabase;

    /** @var list<string> */
    private array $fichiersTemporaires = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesSeeder::class);
        Storage::fake('local');
    }

    protected function tearDown(): void
    {
        foreach ($this->fichiersTemporaires as $chemin) {
            @unlink($chemin);
        }
        parent::tearDown();
    }

    /** Vrai fichier sur disque : le type MIME est lu dans son contenu, pas dans son nom. */
    private function fichier(string $nom, string $contenu, string $typeAnnonce): UploadedFile
    {
        $chemin = tempnam(sys_get_temp_dir(), 'pj');
        file_put_contents($chemin, $contenu);
        $this->fichiersTemporaires[] = $chemin;

        return new UploadedFile($chemin, $nom, $typeAnnonce, null, true);
    }

    private function pdf(string $nom = 'resultat.pdf', int $remplissage = 100): UploadedFile
    {
        return $this->fichier($nom, "%PDF-1.4\n%".str_repeat('x', $remplissage)."\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n", 'application/pdf');
    }

    private function png(string $nom = 'photo.png'): UploadedFile
    {
        return $this->fichier($nom, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='), 'image/png');
    }

    private function jpeg(string $nom = 'photo.jpg'): UploadedFile
    {
        return $this->fichier($nom, "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00".str_repeat("\x00", 64)."\xFF\xD9", 'image/jpeg');
    }

    private function webp(string $nom = 'photo.webp'): UploadedFile
    {
        $corps = 'WEBPVP8 '.pack('V', 10).str_repeat("\x00", 10);

        return $this->fichier($nom, 'RIFF'.pack('V', strlen($corps)).$corps, 'image/webp');
    }

    private function envoyer(array $donnees = []): TestResponse
    {
        return $this->post(route('rdv.demande.enregistrer'), array_merge([
            'motif' => 'Toux et fièvre',
            'symptomes' => 'Je tousse la nuit.',
            'depuis' => 'quelques_jours',
            'tri' => $this->reponsesTri(),
        ], $donnees));
    }

    public function test_l_espace_patient_ouvre_le_parcours(): void
    {
        $this->actingAs($this->patient())
            ->get(route('rdv.demande.creer'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('RendezVous/Demande')
                ->has('questions', count(QuestionnaireTri::QUESTIONS))
                ->where('questions.0.cle', 'respiration')
                ->has('durees')
                ->where('piecesMax', 5));
    }

    public function test_une_demande_sans_signe_d_alerte_mene_au_choix_du_medecin(): void
    {
        $patient = $this->patient();

        $reponse = $this->actingAs($patient)->envoyer();

        $demande = DemandeConsultation::sole();
        $reponse->assertRedirect(route('rdv.demande.choix', $demande));
        $this->assertSame($patient->id, $demande->patient_id);
        $this->assertSame('ouverte', $demande->statut);
        $this->assertFalse($demande->tri_alerte);
        $this->assertSame('Toux et fièvre', $demande->motif);
        $this->assertSame('Je tousse la nuit.', $demande->symptomes);
        $this->assertSame('quelques_jours', $demande->depuis);
        $this->assertSame(QuestionnaireTri::VERSION, $demande->tri_reponses['version']);
        $this->assertSame(['cle' => 'respiration', 'question' => QuestionnaireTri::QUESTIONS['respiration'], 'reponse' => 'non'], $demande->tri_reponses['reponses'][0]);
        $this->assertCount(count(QuestionnaireTri::QUESTIONS), $demande->tri_reponses['reponses']);
    }

    public function test_le_motif_est_obligatoire(): void
    {
        $this->actingAs($this->patient())->envoyer(['motif' => '  '])->assertSessionHasErrors('motif');

        $this->assertSame(0, DemandeConsultation::count());
    }

    public function test_la_duree_doit_etre_une_des_reponses_proposees(): void
    {
        $this->actingAs($this->patient())->envoyer(['depuis' => 'n importe quoi'])->assertSessionHasErrors('depuis');
    }

    public function test_chaque_question_du_tri_exige_une_reponse_explicite(): void
    {
        $patient = $this->patient();
        $reponses = $this->reponsesTri();
        unset($reponses['poitrine']);

        // Une question sans réponse n'est pas un « non ».
        $this->actingAs($patient)->envoyer(['tri' => $reponses])->assertSessionHasErrors('tri.poitrine');
        $this->envoyer(['tri' => []])->assertSessionHasErrors(['tri']);
        $this->post(route('rdv.demande.enregistrer'), ['motif' => 'Toux'])->assertSessionHasErrors(['tri']);

        $this->assertSame(0, DemandeConsultation::count());
    }

    public function test_seuls_oui_et_non_sont_des_reponses(): void
    {
        $patient = $this->patient();

        foreach (['', '0', 'false', 'on', '1', 'peut-etre'] as $valeur) {
            $this->actingAs($patient)
                ->envoyer(['tri' => array_merge($this->reponsesTri(), ['saignement' => $valeur])])
                ->assertSessionHasErrors('tri.saignement');
        }

        $this->assertSame(0, DemandeConsultation::count());
    }

    public function test_un_seul_oui_oriente_vers_les_urgences(): void
    {
        foreach (array_keys(QuestionnaireTri::QUESTIONS) as $cle) {
            $patient = $this->patient();

            $this->actingAs($patient)->envoyer(['tri' => $this->reponsesTri([$cle])]);

            $demande = DemandeConsultation::where('patient_id', $patient->id)->sole();
            $this->assertSame('orientee_urgence', $demande->statut, $cle);
            $this->assertTrue($demande->tri_alerte, $cle);
        }
    }

    public function test_l_ecran_d_urgence_donne_l_etablissement_et_le_numero_configures(): void
    {
        config(['doc-and-i.urgences' => ['etablissement' => 'Cliniques Universitaires de Kinshasa', 'telephone' => '+243810000000']]);
        $patient = $this->patient();

        $reponse = $this->actingAs($patient)->envoyer(['tri' => $this->reponsesTri(['respiration'])]);

        $demande = DemandeConsultation::sole();
        $reponse->assertRedirect(route('rdv.demande.urgence', $demande));
        $this->get(route('rdv.demande.urgence', $demande))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('RendezVous/Urgence')
                ->where('etablissement', 'Cliniques Universitaires de Kinshasa')
                ->where('telephone', '+243810000000'));
    }

    public function test_sans_etablissement_configure_l_ecran_renvoie_vers_l_hopital_le_plus_proche(): void
    {
        config(['doc-and-i.urgences' => ['etablissement' => null, 'telephone' => null]]);
        $patient = $this->patient();
        $demande = $this->demande($patient, 'orientee_urgence');

        $this->actingAs($patient)
            ->get(route('rdv.demande.urgence', $demande))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('etablissement', null)
                ->where('telephone', null)
                ->where('consigne', fn ($consigne) => str_contains($consigne, 'hôpital le plus proche')));
    }

    public function test_une_demande_orientee_vers_les_urgences_ne_mene_a_aucune_reservation(): void
    {
        $patient = $this->patient();
        $demande = $this->demande($patient, 'orientee_urgence');
        $medecin = $this->medecin();
        $type = $this->typeConsultation($medecin);

        $this->actingAs($patient);
        $this->get(route('rdv.demande.choix', $demande))->assertRedirect(route('rdv.demande.urgence', $demande));
        $this->get(route('rdv.demande.creneaux', [$demande, 'type' => $type->id, 'medecin' => $medecin->id]))
            ->assertRedirect(route('rdv.demande.urgence', $demande));
        $this->post(route('rdv.demande.reserver', $demande), [
            'type_consultation_id' => $type->id, 'medecin_id' => $medecin->id, 'debut' => now()->addDay()->toIso8601String(),
        ])->assertRedirect(route('rdv.demande.urgence', $demande));

        $this->assertSame(0, RendezVous::count());
    }

    public function test_l_ecran_d_urgence_n_existe_que_pour_une_demande_en_alerte(): void
    {
        $patient = $this->patient();
        $demande = $this->demande($patient);

        $this->actingAs($patient)->get(route('rdv.demande.urgence', $demande))->assertRedirect(route('rdv.demande.choix', $demande));
    }

    public function test_les_pieces_jointes_vont_sur_le_disque_prive_sous_un_nom_impossible_a_deviner(): void
    {
        Storage::fake('public');
        $patient = $this->patient();

        $this->actingAs($patient)->envoyer(['pieces' => [$this->pdf('Analyse sang Kabila.pdf'), $this->png(), $this->jpeg(), $this->webp()]])
            ->assertSessionHasNoErrors();

        $pieces = PieceJointeDemande::orderBy('nom_original')->get();
        $this->assertCount(4, $pieces);
        $this->assertEqualsCanonicalizing(
            ['application/pdf', 'image/png', 'image/jpeg', 'image/webp'],
            $pieces->pluck('type_mime')->all()
        );

        $pdf = $pieces->firstWhere('nom_original', 'Analyse sang Kabila.pdf');
        Storage::disk('local')->assertExists($pdf->chemin);
        $this->assertStringNotContainsString('Analyse', $pdf->chemin, 'Le nom d\'origine n\'apparaît pas dans le chemin.');
        $this->assertMatchesRegularExpression('#^pieces-jointes/[0-9a-f-]{36}/[A-Za-z0-9]{40}$#', $pdf->chemin);
        $this->assertGreaterThan(0, $pdf->taille_octets);
        $this->assertArrayNotHasKey('chemin', $pdf->toArray(), 'Le chemin ne sort jamais vers l\'interface.');
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_le_nom_d_une_piece_jointe_perd_ses_caracteres_de_controle(): void
    {
        $patient = $this->patient();

        $this->actingAs($patient)->envoyer(['pieces' => [$this->pdf("analyse\r\nSet-Cookie: x=1.pdf")]])
            ->assertSessionHasNoErrors();

        $nom = PieceJointeDemande::sole()->nom_original;
        $this->assertDoesNotMatchRegularExpression('/[\x00-\x1F\x7F]/', $nom);
        $this->assertSame('analyse Set-Cookie: x=1.pdf', $nom);
    }

    public function test_le_type_reel_du_fichier_compte_pas_son_extension(): void
    {
        $patient = $this->patient();

        $faux = [
            $this->fichier('ordonnance.pdf', 'Ceci est un simple texte.', 'application/pdf'),
            $this->fichier('photo.png', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'image/png'),
            $this->fichier('page.jpg', '<html><body>bonjour</body></html>', 'image/jpeg'),
        ];

        foreach ($faux as $fichier) {
            $this->actingAs($patient)->envoyer(['pieces' => [$fichier]])->assertSessionHasErrors('pieces.0');
        }

        $this->assertSame(0, DemandeConsultation::count());
    }

    public function test_seuls_les_formats_autorises_sont_acceptes_meme_avec_un_bon_contenu(): void
    {
        $patient = $this->patient();

        // Contenu PDF valide mais extension inattendue ; GIF réel refusé.
        $this->actingAs($patient)->envoyer(['pieces' => [$this->pdf('script.exe')]])->assertSessionHasErrors('pieces.0');
        $this->envoyer(['pieces' => [$this->fichier('anime.gif', "GIF89a\x01\x00\x01\x00\x00\x00\x00;", 'image/gif')]])->assertSessionHasErrors('pieces.0');

        $this->assertSame(0, PieceJointeDemande::count());
    }

    public function test_cinq_fichiers_au_plus(): void
    {
        $this->actingAs($this->patient())
            ->envoyer(['pieces' => array_map(fn ($i) => $this->png("photo{$i}.png"), range(1, 6))])
            ->assertSessionHasErrors('pieces');

        $this->assertSame(0, PieceJointeDemande::count());
    }

    public function test_cinq_mega_octets_au_plus_par_fichier(): void
    {
        $patient = $this->patient();

        $this->actingAs($patient)->envoyer(['pieces' => [$this->pdf('gros.pdf', 5 * 1024 * 1024)]])->assertSessionHasErrors('pieces.0');
        $this->envoyer(['pieces' => [$this->pdf('juste.pdf', 5 * 1024 * 1024 - 200)]])->assertSessionHasNoErrors();

        $this->assertSame(1, PieceJointeDemande::count());
    }

    public function test_le_patient_telecharge_sa_piece_jointe_par_une_route_controlee(): void
    {
        $patient = $this->patient();
        $this->actingAs($patient)->envoyer(['pieces' => [$this->pdf('resultat.pdf')]]);
        $piece = PieceJointeDemande::sole();

        $reponse = $this->get(route('rdv.piece-jointe', $piece));

        $reponse->assertOk();
        $reponse->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringContainsString('attachment', $reponse->headers->get('Content-Disposition'));
        $this->assertStringContainsString('no-store', $reponse->headers->get('Cache-Control'));
        $this->assertStringStartsWith('%PDF-1.4', $reponse->streamedContent());
    }

    public function test_un_autre_patient_n_accede_ni_a_la_demande_ni_aux_pieces_jointes(): void
    {
        $proprietaire = $this->patient();
        $this->actingAs($proprietaire)->envoyer(['pieces' => [$this->pdf()]]);
        $demande = DemandeConsultation::sole();
        $piece = PieceJointeDemande::sole();

        $intrus = $this->patient();
        $this->actingAs($intrus);
        $this->get(route('rdv.piece-jointe', $piece))->assertNotFound();
        $this->get(route('rdv.demande.choix', $demande))->assertNotFound();
        $this->get(route('rdv.demande.urgence', $demande))->assertNotFound();
    }

    public function test_un_identifiant_mal_forme_donne_404_et_non_une_erreur(): void
    {
        $this->actingAs($this->patient());

        $this->get('/consultation/pieces-jointes/abc')->assertNotFound();
        $this->get('/consultation/demandes/1/choix')->assertNotFound();
        $this->get('/mes-rendez-vous/1')->assertNotFound();
    }

    // --- Revue de sécurité du lot : quota de stockage des pièces jointes ---

    public function test_le_nombre_de_demandes_avec_fichiers_par_jour_est_plafonne(): void
    {
        config(['doc-and-i.pieces_jointes.demandes_par_jour' => 2]);
        $patient = $this->patient();
        $this->actingAs($patient);

        $this->envoyer(['pieces' => [$this->pdf()]])->assertSessionHasNoErrors();
        $this->envoyer(['pieces' => [$this->pdf()]])->assertSessionHasNoErrors();
        $this->envoyer(['pieces' => [$this->pdf()]])
            ->assertSessionHasErrors(['pieces' => 'Vous avez déjà envoyé beaucoup de fichiers aujourd\'hui. Envoyez votre demande sans fichier, ou réessayez demain.']);
        $this->assertSame(2, DemandeConsultation::count());
        $this->assertSame(2, PieceJointeDemande::count());
        $this->assertCount(2, Storage::disk('local')->allFiles());

        // Sans fichier, la demande passe : le quota ne bloque jamais l'accès aux soins.
        $this->envoyer()->assertSessionHasNoErrors();
        $this->assertSame(3, DemandeConsultation::count());

        // Un autre patient n'est pas concerné ; le lendemain, le quota repart.
        $this->actingAs($this->patient())->envoyer(['pieces' => [$this->pdf()]])->assertSessionHasNoErrors();
        $this->travel(25)->hours();
        $this->actingAs($patient)->envoyer(['pieces' => [$this->pdf()]])->assertSessionHasNoErrors();
    }

    public function test_le_volume_de_fichiers_par_jour_est_plafonne(): void
    {
        config(['doc-and-i.pieces_jointes.octets_par_jour' => 1000]);
        $patient = $this->patient();
        $this->actingAs($patient);

        $this->envoyer(['pieces' => [$this->pdf('a.pdf', 500)]])->assertSessionHasNoErrors();
        $this->envoyer(['pieces' => [$this->pdf('b.pdf', 500)]])->assertSessionHasErrors('pieces');

        $this->assertSame(1, DemandeConsultation::count());
        $this->assertSame(['a.pdf'], PieceJointeDemande::pluck('nom_original')->all());
    }
}
