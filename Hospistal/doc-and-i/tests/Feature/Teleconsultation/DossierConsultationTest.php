<?php

namespace Tests\Feature\Teleconsultation;

use App\Enums\StatutRendezVous;
use App\Models\PieceJointeDemande;
use App\Services\RendezVous\QuestionnaireTri;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ParcoursConsultation;
use Tests\Support\ParcoursRendezVous;
use Tests\Support\PersonnelDeTest;
use Tests\TestCase;

/** TLC-05 : dossier du patient pendant l'appel, au médecin de ce rendez-vous seulement. */
class DossierConsultationTest extends TestCase
{
    use ParcoursConsultation, ParcoursRendezVous, PersonnelDeTest, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesSeeder::class);
        Storage::fake('local');
        $this->preparerConsultation();
        $this->patientRdv->forceFill(['date_naissance' => '1990-03-15', 'sexe' => 'F', 'commune' => 'Lemba'])->save();
        $this->rdv->demande->forceFill([
            'motif' => 'Fièvre et maux de tête', 'symptomes' => 'Frissons le soir', 'depuis' => 'quelques_jours',
            'tri_reponses' => QuestionnaireTri::trace($this->reponsesTri()),
        ])->save();
    }

    private function piece(): PieceJointeDemande
    {
        Storage::disk('local')->put('pieces-jointes/'.$this->rdv->demande_id.'/fichier', 'contenu de l\'analyse');
        $piece = new PieceJointeDemande(['chemin' => 'pieces-jointes/'.$this->rdv->demande_id.'/fichier', 'nom_original' => 'analyse.pdf', 'type_mime' => 'application/pdf', 'taille_octets' => 21]);
        $piece->demande_id = $this->rdv->demande_id;
        $piece->save();

        return $piece;
    }

    public function test_le_medecin_voit_l_identite_utile_la_demande_et_le_tri_sans_telephone(): void
    {
        $reponse = $this->commeMedecinRdv()->getJson($this->adresse('personnel.medecin.consultation.dossier'))
            ->assertOk()
            ->assertJsonPath('patient.age', 36)
            ->assertJsonPath('patient.sexe', 'Femme')
            ->assertJsonPath('patient.commune', 'Lemba')
            ->assertJsonPath('demande.motif', 'Fièvre et maux de tête')
            ->assertJsonPath('demande.symptomes', 'Frissons le soir')
            ->assertJsonPath('demande.depuis', 'Depuis quelques jours')
            ->assertJsonPath('demande.alerte', false)
            ->assertJsonCount(count(QuestionnaireTri::QUESTIONS), 'demande.tri')
            ->assertJsonPath('antecedents', null);

        $this->assertStringNotContainsString(substr($this->patientRdv->telephone, 4), $reponse->getContent(), 'Jamais le téléphone.');
        $this->assertStringContainsString('no-store', $reponse->headers->get('Cache-Control'));
    }

    public function test_les_pieces_jointes_sont_servies_au_medecin_de_ce_rendez_vous(): void
    {
        $piece = $this->piece();

        $this->commeMedecinRdv()->getJson($this->adresse('personnel.medecin.consultation.dossier'))
            ->assertJsonPath('piecesJointes.0.id', $piece->id)->assertJsonPath('piecesJointes.0.nom', 'analyse.pdf');
        $this->commeMedecinRdv()->get($this->adresse('personnel.medecin.consultation.piece-jointe', ['piece' => $piece->id]))
            ->assertOk()->assertDownload('analyse.pdf');
    }

    public function test_une_piece_jointe_d_une_autre_demande_n_est_pas_servie(): void
    {
        $autreRdv = $this->rendezVousExistant($this->medecinRdv, $this->patient(), $this->lundi('11:00'));
        Storage::disk('local')->put('pieces-jointes/x/fichier', 'autre');
        $autrePiece = new PieceJointeDemande(['chemin' => 'pieces-jointes/x/fichier', 'nom_original' => 'autre.pdf', 'type_mime' => 'application/pdf', 'taille_octets' => 5]);
        $autrePiece->demande_id = $autreRdv->demande_id;
        $autrePiece->save();

        // Même médecin, mais la pièce n'appartient pas à la demande de CE rendez-vous.
        $this->commeMedecinRdv()->get($this->adresse('personnel.medecin.consultation.piece-jointe', ['piece' => $autrePiece->id]))->assertForbidden();
    }

    public function test_l_historique_montre_les_rendez_vous_passes_avec_ce_medecin_seulement(): void
    {
        $passe = $this->rendezVousExistant($this->medecinRdv, $this->patientRdv, $this->lundi('07:00')->subWeek(), statut: StatutRendezVous::Termine);
        $this->rendezVousExistant($this->medecinActive(), $this->patientRdv, $this->lundi('07:00')->subWeeks(2), statut: StatutRendezVous::Termine);

        $this->commeMedecinRdv()->getJson($this->adresse('personnel.medecin.consultation.dossier'))
            ->assertJsonCount(1, 'historique')
            ->assertJsonPath('historique.0.id', $passe->id)
            ->assertJsonPath('historique.0.statut', 'Terminé');
    }

    public function test_un_rendez_vous_annule_n_ouvre_plus_le_dossier(): void
    {
        $this->rdv->forceFill(['statut' => StatutRendezVous::AnnuleParPatient])->save();

        $this->commeMedecinRdv()->getJson($this->adresse('personnel.medecin.consultation.dossier'))->assertForbidden();
        $this->commeMedecinRdv()->get($this->adresse('personnel.medecin.consultation.piece-jointe', ['piece' => $this->piece()->id]))->assertForbidden();
    }

    public function test_une_consultation_terminee_garde_le_dossier_ouvert_au_medecin(): void
    {
        $this->rdv->forceFill(['statut' => StatutRendezVous::Termine])->save();

        $this->commeMedecinRdv()->getJson($this->adresse('personnel.medecin.consultation.dossier'))->assertOk();
    }
}
