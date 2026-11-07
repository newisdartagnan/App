<?php

namespace Tests\Feature\Teleconsultation;

use App\Enums\StatutConsultation;
use App\Enums\StatutRendezVous;
use App\Models\Consultation;
use App\Models\MessageConsultation;
use App\Services\Teleconsultation\AccesConsultation;
use App\Services\Teleconsultation\AccesRefuse;
use App\Services\Teleconsultation\Messagerie;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Support\ParcoursConsultation;
use Tests\Support\ParcoursRendezVous;
use Tests\Support\PersonnelDeTest;
use Tests\Support\PhotosDeTest;
use Tests\TestCase;

/** TLC-03 : messagerie de la consultation, entre le patient et son médecin seulement. */
class MessagerieConsultationTest extends TestCase
{
    use ParcoursConsultation, ParcoursRendezVous, PersonnelDeTest, PhotosDeTest, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesSeeder::class);
        Storage::fake('local');
        $this->preparerConsultation();
        $this->entrerTousLesDeux();
    }

    private function envoyerTextePatient(string $texte): TestResponse
    {
        return $this->commePatientRdv()->postJson($this->adresse('consultation.messages.envoyer'), ['texte' => $texte]);
    }

    public function test_le_patient_et_le_medecin_echangent_des_messages(): void
    {
        $this->envoyerTextePatient('J\'ai toujours de la fièvre.')->assertCreated()
            ->assertJsonPath('message.auteur', 'patient')->assertJsonPath('message.moi', true);
        $this->commeMedecinRdv()->postJson($this->adresse('personnel.medecin.consultation.messages.envoyer'), ['texte' => 'Prenez votre température.'])
            ->assertCreated()->assertJsonPath('message.auteur', 'medecin');

        $this->commePatientRdv()->getJson($this->adresse('consultation.messages'))
            ->assertOk()->assertJsonCount(2, 'messages')
            ->assertJsonPath('messages.0.texte', 'J\'ai toujours de la fièvre.')
            ->assertJsonPath('messages.1.moi', false);
    }

    public function test_la_lecture_reprend_apres_le_dernier_message_recu(): void
    {
        $this->envoyerTextePatient('Un');
        $premier = MessageConsultation::sole()->id;
        $this->travel(1)->seconds();
        $this->envoyerTextePatient('Deux');

        $this->commeMedecinRdv()->getJson($this->adresse('personnel.medecin.consultation.messages', ['apres' => $premier]))
            ->assertOk()->assertJsonCount(1, 'messages')->assertJsonPath('messages.0.texte', 'Deux');
    }

    public function test_des_messages_de_la_meme_seconde_restent_dans_l_ordre(): void
    {
        // L'horodatage est à la seconde et l'heure est figée : seul le rang fixe l'ordre.
        foreach (range(1, 8) as $i) {
            $this->envoyerTextePatient("Message {$i}");
        }

        $textes = array_column($this->commeMedecinRdv()->getJson($this->adresse('personnel.medecin.consultation.messages'))->json('messages'), 'texte');
        $this->assertSame(array_map(fn ($i) => "Message {$i}", range(1, 8)), $textes);
    }

    public function test_un_texte_vide_ou_trop_long_est_refuse(): void
    {
        $this->envoyerTextePatient('')->assertStatus(422);
        $this->envoyerTextePatient(str_repeat('a', 2001))->assertStatus(422);

        $this->assertSame(0, MessageConsultation::count());
    }

    public function test_une_photo_est_gardee_sur_le_disque_prive_sous_un_nom_aleatoire_et_servie_aux_participants(): void
    {
        $this->commePatientRdv()->post($this->adresse('consultation.messages.envoyer'), ['photo' => $this->photo($this->jpegAvecGps(), 'eruption-bras.jpg')], ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('message.photo', true);

        $message = MessageConsultation::sole();
        $this->assertSame('image/jpeg', $message->piece_type);
        $this->assertStringNotContainsString('eruption', $message->piece_chemin, 'Le nom d\'origine n\'est pas gardé.');
        $this->assertMatchesRegularExpression('#^consultations/'.$message->consultation_id.'/[A-Za-z0-9]{40}$#', $message->piece_chemin);
        Storage::disk('local')->assertExists($message->piece_chemin);

        $this->commeMedecinRdv()->get($this->adresse('personnel.medecin.consultation.messages.photo', ['message' => $message->id]))
            ->assertOk()->assertHeader('Content-Type', 'image/jpeg')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('no-store', $this->commePatientRdv()->get($this->adresse('consultation.messages.photo', ['message' => $message->id]))->headers->get('Cache-Control'));
    }

    public function test_une_photo_au_type_falsifie_est_refusee(): void
    {
        // Un texte nommé .jpg, puis un en-tête PNG suivi de n'importe quoi :
        // le type est lu dans le contenu (règle de validation, puis service).
        foreach ([
            UploadedFile::fake()->createWithContent('photo.jpg', 'ceci n\'est pas une image'),
            UploadedFile::fake()->createWithContent('photo.png', "\x89PNG\r\n\x1a\n".str_repeat('x', 200)),
            // En-tête JPEG valide pour finfo, mais aucune image décodable derrière.
            UploadedFile::fake()->createWithContent('photo.jpg', "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00".str_repeat('x', 200)),
        ] as $fausse) {
            $statut = $this->commePatientRdv()->post($this->adresse('consultation.messages.envoyer'), ['photo' => $fausse], ['Accept' => 'application/json'])->status();
            $this->assertContains($statut, [403, 422]);
        }

        $this->assertSame(0, MessageConsultation::count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_le_service_verifie_l_image_elle_meme(): void
    {
        $this->expectException(AccesRefuse::class);
        // Reconnu « image/jpeg » par finfo, mais pas une image : getimagesize le refuse.
        Messagerie::verifierPhoto(UploadedFile::fake()->createWithContent('photo.jpg', "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00".str_repeat('x', 200)));
    }

    public function test_une_photo_trop_lourde_est_refusee(): void
    {
        config(['doc-and-i.teleconsultation.photo_octets_max' => 2048]);

        $this->commePatientRdv()->post($this->adresse('consultation.messages.envoyer'), ['photo' => $this->photo($this->jpegSimple().str_repeat("\0", 3000), 'grande.jpg')], ['Accept' => 'application/json'])
            ->assertStatus(422);

        $this->assertSame(0, MessageConsultation::count());
        $this->expectException(AccesRefuse::class);
        Messagerie::verifierPhoto($this->photo($this->jpegSimple().str_repeat("\0", 3000), 'grande.jpg'));
    }

    public function test_hors_de_la_fenetre_ou_salle_close_on_lit_mais_on_n_ecrit_plus(): void
    {
        $this->envoyerTextePatient('Avant la fin.');
        $consultation = Consultation::sole();
        $consultation->forceFill(['statut' => StatutConsultation::Terminee])->save();

        $this->envoyerTextePatient('Après la fin.')->assertForbidden();
        $this->commePatientRdv()->getJson($this->adresse('consultation.messages'))->assertOk()->assertJsonCount(1, 'messages');
        $this->commeMedecinRdv()->getJson($this->adresse('personnel.medecin.consultation.messages'))->assertOk()->assertJsonCount(1, 'messages');
    }

    public function test_apres_la_fermeture_de_la_salle_on_n_ecrit_plus(): void
    {
        $this->travelTo($this->lundi('10:21'));

        $this->envoyerTextePatient('Trop tard.')->assertForbidden()->assertJsonPath('message', 'La salle de cette consultation est fermée.');
        $this->assertSame(0, MessageConsultation::count());
    }

    public function test_sans_salle_ouverte_pas_de_messagerie(): void
    {
        $autre = $this->rendezVousExistant($this->medecinRdv, $this->patientRdv, $this->lundi('11:00'));

        $this->commePatientRdv()->getJson(route('consultation.messages', ['rendezVous' => $autre->id]))->assertForbidden();
        $this->commePatientRdv()->postJson(route('consultation.messages.envoyer', ['rendezVous' => $autre->id]), ['texte' => 'Allô ?'])->assertForbidden();
    }

    public function test_le_debit_d_envoi_est_borne(): void
    {
        for ($i = 0; $i < Messagerie::MESSAGES_PAR_MINUTE; $i++) {
            $this->envoyerTextePatient("Message {$i}")->assertCreated();
        }

        $this->envoyerTextePatient('Un de trop')->assertForbidden();
        $this->assertSame(Messagerie::MESSAGES_PAR_MINUTE, MessageConsultation::count());
    }

    public function test_un_message_ne_se_modifie_ni_ne_se_supprime(): void
    {
        $this->envoyerTextePatient('Gardé au dossier.');

        $this->expectException(\LogicException::class);
        MessageConsultation::sole()->delete();
    }

    public function test_la_photo_d_une_autre_consultation_n_est_pas_servie(): void
    {
        $this->commePatientRdv()->post($this->adresse('consultation.messages.envoyer'), ['photo' => $this->photo($this->pngSimple(), 'a.png')], ['Accept' => 'application/json']);
        $message = MessageConsultation::sole();

        // Même patient, même médecin, autre rendez-vous : le message n'appartient pas à cette salle.
        $this->travelTo($this->lundi('10:55'));
        $autre = $this->rendezVousExistant($this->medecinRdv, $this->patientRdv, $this->lundi('11:00'));
        app(AccesConsultation::class)->entrer($this->patientRdv, $autre);

        $this->commePatientRdv()->get(route('consultation.messages.photo', ['rendezVous' => $autre->id, 'message' => $message->id]))->assertForbidden();
    }

    private function envoyerPhotoPatient(string $octets, string $nom = 'photo.jpg'): TestResponse
    {
        return $this->commePatientRdv()->post($this->adresse('consultation.messages.envoyer'), ['photo' => $this->photo($octets, $nom)], ['Accept' => 'application/json']);
    }

    public function test_la_photo_est_stockee_sans_sa_position_gps_et_reste_lisible(): void
    {
        foreach ([[$this->jpegAvecGps(), 'a.jpg', 'image/jpeg'], [$this->pngAvecGps(), 'b.png', 'image/png'], [$this->webpAvecGps(), 'c.webp', 'image/webp']] as [$octets, $nom, $type]) {
            $this->assertStringContainsString('GPSLatitude', $octets);
            $this->envoyerPhotoPatient($octets, $nom)->assertCreated();

            $message = MessageConsultation::latest('rang')->first();
            $stockee = Storage::disk('local')->get($message->piece_chemin);
            $this->assertStringNotContainsString('GPSLatitude', $stockee, "Position retirée ({$type}).");
            $this->assertStringNotContainsString($this->exifAvecGps(), $stockee);
            $this->assertStringNotContainsString('Lemba', $stockee);
            $this->assertSame($type, getimagesizefromstring($stockee)['mime']);
            $this->assertSame(strlen($stockee), $message->piece_taille);

            $servie = $this->commeMedecinRdv()->get($this->adresse('personnel.medecin.consultation.messages.photo', ['message' => $message->id]))->streamedContent();
            $this->assertSame($stockee, $servie);
        }
    }

    public function test_le_nombre_de_photos_par_participant_est_borne(): void
    {
        config(['doc-and-i.teleconsultation.photos_par_participant' => 2]);

        $this->envoyerPhotoPatient($this->jpegSimple())->assertCreated();
        $this->envoyerPhotoPatient($this->pngSimple(), 'a.png')->assertCreated();
        $this->envoyerPhotoPatient($this->webpSimple(), 'b.webp')->assertForbidden()
            ->assertJsonPath('message', 'Limite de photos atteinte pour cette consultation (2 photos, 40 Mo).');

        // Le texte reste possible, et le quota du médecin est le sien.
        $this->envoyerTextePatient('Je vous écris plutôt.')->assertCreated();
        $this->commeMedecinRdv()->post($this->adresse('personnel.medecin.consultation.messages.envoyer'), ['photo' => $this->photo($this->jpegSimple())], ['Accept' => 'application/json'])->assertCreated();

        $this->assertSame(3, MessageConsultation::whereNotNull('piece_chemin')->count());
        $this->assertCount(3, Storage::disk('local')->allFiles());
    }

    public function test_le_volume_de_photos_par_participant_est_borne(): void
    {
        $taille = strlen($this->jpegSimple());
        config(['doc-and-i.teleconsultation.photos_octets_par_participant' => 2 * $taille + 10]);

        $this->envoyerPhotoPatient($this->jpegSimple())->assertCreated();
        $this->envoyerPhotoPatient($this->jpegSimple())->assertCreated();
        $this->envoyerPhotoPatient($this->jpegSimple())->assertForbidden();

        $this->assertSame(2, MessageConsultation::count());
        $this->assertCount(2, Storage::disk('local')->allFiles(), 'Rien n\'est gardé sur le disque pour une photo refusée.');
    }

    public function test_le_quota_compte_la_taille_apres_nettoyage(): void
    {
        // La photo brute dépasse le volume permis, la photo nettoyée non.
        config(['doc-and-i.teleconsultation.photos_octets_par_participant' => strlen($this->jpegAvecGps()) - 1]);

        $this->envoyerPhotoPatient($this->jpegAvecGps())->assertCreated();
    }

    public function test_un_rendez_vous_qui_n_est_plus_confirme_n_accepte_plus_de_message(): void
    {
        $this->envoyerTextePatient('Avant.')->assertCreated();
        $this->rdv->forceFill(['statut' => StatutRendezVous::AnnuleParMedecin])->save();

        $this->envoyerTextePatient('Après.')->assertForbidden()
            ->assertJsonPath('message', 'Ce rendez-vous n\'est plus confirmé : on n\'écrit plus dans cette consultation.');
        $this->envoyerPhotoPatient($this->jpegSimple())->assertForbidden();
        $this->assertSame(1, MessageConsultation::count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_les_reponses_json_de_la_consultation_ne_sont_pas_mises_en_cache(): void
    {
        foreach ([
            $this->envoyerTextePatient('Bonjour'),
            $this->commePatientRdv()->getJson($this->adresse('consultation.messages')),
            $this->commeMedecinRdv()->getJson($this->adresse('personnel.medecin.consultation.messages')),
            $this->commePatientRdv()->getJson($this->adresse('consultation.fin')),
        ] as $reponse) {
            $this->assertStringContainsString('no-store', (string) $reponse->headers->get('Cache-Control'));
        }
        // Un refus aussi.
        $this->travelTo($this->lundi('10:21'));
        $refus = $this->envoyerTextePatient('Trop tard.')->assertForbidden();
        $this->assertStringContainsString('no-store', (string) $refus->headers->get('Cache-Control'));
    }
}
