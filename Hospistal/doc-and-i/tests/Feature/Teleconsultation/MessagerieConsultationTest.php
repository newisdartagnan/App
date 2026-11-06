<?php

namespace Tests\Feature\Teleconsultation;

use App\Enums\StatutConsultation;
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
use Tests\TestCase;

/** TLC-03 : messagerie de la consultation, entre le patient et son médecin seulement. */
class MessagerieConsultationTest extends TestCase
{
    use ParcoursConsultation, ParcoursRendezVous, PersonnelDeTest, RefreshDatabase;

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
        $this->commePatientRdv()->post($this->adresse('consultation.messages.envoyer'), ['photo' => UploadedFile::fake()->image('eruption-bras.jpg', 40, 40)], ['Accept' => 'application/json'])
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

        $this->commePatientRdv()->post($this->adresse('consultation.messages.envoyer'), ['photo' => UploadedFile::fake()->image('grande.jpg', 400, 400)->size(50)], ['Accept' => 'application/json'])
            ->assertStatus(422);

        $this->assertSame(0, MessageConsultation::count());
        $this->expectException(AccesRefuse::class);
        Messagerie::verifierPhoto(UploadedFile::fake()->image('grande.jpg', 400, 400)->size(50));
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
        $this->commePatientRdv()->post($this->adresse('consultation.messages.envoyer'), ['photo' => UploadedFile::fake()->image('a.png', 10, 10)], ['Accept' => 'application/json']);
        $message = MessageConsultation::sole();

        // Même patient, même médecin, autre rendez-vous : le message n'appartient pas à cette salle.
        $this->travelTo($this->lundi('10:55'));
        $autre = $this->rendezVousExistant($this->medecinRdv, $this->patientRdv, $this->lundi('11:00'));
        app(AccesConsultation::class)->entrer($this->patientRdv, $autre);

        $this->commePatientRdv()->get(route('consultation.messages.photo', ['rendezVous' => $autre->id, 'message' => $message->id]))->assertForbidden();
    }
}
