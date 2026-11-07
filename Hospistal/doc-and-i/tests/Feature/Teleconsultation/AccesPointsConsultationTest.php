<?php

namespace Tests\Feature\Teleconsultation;

use App\Models\Consultation;
use App\Models\MessageConsultation;
use App\Models\PieceJointeDemande;
use App\Models\Remboursement;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ParcoursConsultation;
use Tests\Support\ParcoursPaiement;
use Tests\Support\ParcoursRendezVous;
use Tests\Support\PersonnelDeTest;
use Tests\TestCase;

/**
 * Chaque point d'accès de la consultation, refusé à un autre patient, à un
 * autre médecin, à la caisse et à l'administration ; rien n'est écrit.
 */
class AccesPointsConsultationTest extends TestCase
{
    use ParcoursConsultation, ParcoursPaiement, ParcoursRendezVous, PersonnelDeTest, RefreshDatabase;

    private MessageConsultation $message;

    private PieceJointeDemande $piece;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesSeeder::class);
        Storage::fake('local');
        $this->preparerConsultation();
        $this->paiementExistant($this->rdv);
        $this->entrer($this->patientRdv);
        $this->travelTo($this->lundi('09:12'));

        $this->message = new MessageConsultation;
        $this->message->forceFill([
            'id' => (string) Str::uuid(), 'consultation_id' => Consultation::sole()->id, 'auteur_id' => $this->patientRdv->id,
            'piece_chemin' => 'consultations/x/photo', 'piece_type' => 'image/png', 'piece_taille' => 10, 'cree_le' => now(),
        ])->save();
        Storage::disk('local')->put('consultations/x/photo', 'png');
        Storage::disk('local')->put('pieces-jointes/p', 'pdf');
        $this->piece = new PieceJointeDemande(['chemin' => 'pieces-jointes/p', 'nom_original' => 'p.pdf', 'type_mime' => 'application/pdf', 'taille_octets' => 3]);
        $this->piece->demande_id = $this->rdv->demande_id;
        $this->piece->save();
    }

    /** @return array<string, array{string, string, array<string, string>}> */
    public static function pointsPatient(): array
    {
        return [
            'messages (lecture)' => ['get', 'consultation.messages', []],
            'messages (envoi)' => ['post', 'consultation.messages.envoyer', ['texte' => 'Intrus']],
            'photo' => ['get', 'consultation.messages.photo', []],
            'état de fin' => ['get', 'consultation.fin', []],
            'médecin absent' => ['post', 'consultation.medecin-absent', []],
        ];
    }

    /** @return array<string, array{string, string, array<string, string>}> */
    public static function pointsMedecin(): array
    {
        return [
            'messages (lecture)' => ['get', 'personnel.medecin.consultation.messages', []],
            'messages (envoi)' => ['post', 'personnel.medecin.consultation.messages.envoyer', ['texte' => 'Intrus']],
            'photo' => ['get', 'personnel.medecin.consultation.messages.photo', []],
            'dossier' => ['get', 'personnel.medecin.consultation.dossier', []],
            'pièce jointe' => ['get', 'personnel.medecin.consultation.piece-jointe', []],
            'état de fin' => ['get', 'personnel.medecin.consultation.fin', []],
            'terminer' => ['post', 'personnel.medecin.consultation.terminer', []],
            'patient absent' => ['post', 'personnel.medecin.consultation.patient-absent', []],
        ];
    }

    private function appeler(string $methode, string $route, array $donnees): TestResponse
    {
        $adresse = route($route, array_filter([
            'rendezVous' => $this->rdv->id,
            'message' => str_contains($route, 'photo') ? $this->message->id : null,
            'piece' => str_contains($route, 'piece-jointe') ? $this->piece->id : null,
        ]));

        return $methode === 'get' ? $this->getJson($adresse) : $this->postJson($adresse, $donnees);
    }

    private function rienNEstEcrit(): void
    {
        $this->assertSame(1, MessageConsultation::count());
        $this->assertSame('en_attente', Consultation::sole()->statut->value);
        $this->assertSame(0, Remboursement::count());
    }

    #[DataProvider('pointsPatient')]
    public function test_un_autre_patient_est_refuse(string $methode, string $route, array $donnees): void
    {
        $this->actingAs($this->patient());
        $this->appeler($methode, $route, $donnees)->assertForbidden();
        $this->rienNEstEcrit();
    }

    #[DataProvider('pointsMedecin')]
    public function test_un_autre_medecin_est_refuse(string $methode, string $route, array $donnees): void
    {
        $this->commePersonnel($this->medecinActive()->user);
        $this->appeler($methode, $route, $donnees)->assertForbidden();
        $this->rienNEstEcrit();
    }

    #[DataProvider('pointsMedecin')]
    public function test_la_caisse_et_l_administration_sont_refusees_cote_medecin(string $methode, string $route, array $donnees): void
    {
        foreach (['caisse', 'admin_fonctionnel'] as $role) {
            $this->commePersonnel($this->personnelActive($role));
            $this->appeler($methode, $route, $donnees)->assertForbidden();
        }
        $this->rienNEstEcrit();
    }

    #[DataProvider('pointsPatient')]
    public function test_la_caisse_et_l_administration_sont_refusees_cote_patient(string $methode, string $route, array $donnees): void
    {
        foreach (['caisse', 'admin_fonctionnel'] as $role) {
            $this->actingAs($this->personnelActive($role));
            $this->appeler($methode, $route, $donnees)->assertForbidden();
        }
        $this->rienNEstEcrit();
    }

    #[DataProvider('pointsMedecin')]
    public function test_le_patient_du_rendez_vous_n_entre_pas_cote_medecin(string $methode, string $route, array $donnees): void
    {
        $this->actingAs($this->patientRdv);
        $this->appeler($methode, $route, $donnees)->assertForbidden();
        $this->rienNEstEcrit();
    }

    #[DataProvider('pointsPatient')]
    public function test_le_medecin_du_rendez_vous_n_entre_pas_cote_patient(string $methode, string $route, array $donnees): void
    {
        $this->commeMedecinRdv();
        $this->appeler($methode, $route, $donnees)->assertForbidden();
        $this->rienNEstEcrit();
    }

    #[DataProvider('pointsMedecin')]
    public function test_un_visiteur_est_renvoye_vers_la_connexion_du_personnel(string $methode, string $route, array $donnees): void
    {
        $adresse = route($route, array_filter(['rendezVous' => $this->rdv->id, 'message' => str_contains($route, 'photo') ? $this->message->id : null, 'piece' => str_contains($route, 'piece-jointe') ? $this->piece->id : null]));
        ($methode === 'get' ? $this->get($adresse) : $this->post($adresse, $donnees))->assertRedirect(route('personnel.connexion'));
    }

    public function test_un_rendez_vous_inconnu_repond_404(): void
    {
        $this->actingAs($this->patientRdv)->getJson(route('consultation.messages', ['rendezVous' => (string) Str::uuid()]))->assertNotFound();
        $this->commeMedecinRdv()->getJson(route('personnel.medecin.consultation.dossier', ['rendezVous' => (string) Str::uuid()]))->assertNotFound();
    }

    public function test_les_participants_eux_memes_sont_admis(): void
    {
        $this->actingAs($this->patientRdv)->getJson(route('consultation.messages', ['rendezVous' => $this->rdv->id]))->assertOk();
        $this->commeMedecinRdv()->getJson(route('personnel.medecin.consultation.dossier', ['rendezVous' => $this->rdv->id]))->assertOk();
    }
}
