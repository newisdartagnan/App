<?php

namespace Tests\Feature\Dossier;

use App\Enums\StatutRendezVous;
use App\Models\AvenantNote;
use App\Models\NoteConsultation;
use App\Services\Dossier\NoteService;
use App\Services\Teleconsultation\AccesRefuse;
use App\Support\Empreinte;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Tests\Support\ParcoursConsultation;
use Tests\Support\ParcoursRendezVous;
use Tests\Support\PersonnelDeTest;
use Tests\TestCase;

/** DOS-02, RG-11 : note de consultation du médecin de CE rendez-vous, signée puis figée ; avenants. */
class NoteConsultationTest extends TestCase
{
    use ParcoursConsultation, ParcoursRendezVous, PersonnelDeTest, RefreshDatabase;

    private const NOTE = [
        'motif' => 'Fièvre depuis trois jours',
        'examen' => 'Température 38,5 °C, gorge rouge',
        'hypotheses' => 'Angine virale probable',
        'diagnostic_code' => 'ca02',
        'diagnostic_libelle' => 'Pharyngite aiguë',
        'conduite' => 'Repos, hydratation, revoir si aggravation',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesSeeder::class);
        $this->preparerConsultation('09:05');
    }

    private function enregistrer(array $champs = self::NOTE): TestResponse
    {
        return $this->commeMedecinRdv()->putJson($this->adresse('personnel.medecin.consultation.note.enregistrer'), $champs);
    }

    private function signer(?array $champs = null): TestResponse
    {
        return $this->commeMedecinRdv()->postJson($this->adresse('personnel.medecin.consultation.note.signer'), $champs === null ? [] : ['champs' => $champs]);
    }

    private function avenant(string $contenu, ?string $cle = null): TestResponse
    {
        return $this->commeMedecinRdv()->postJson($this->adresse('personnel.medecin.consultation.note.avenant'), ['contenu' => $contenu, 'cle' => $cle ?? (string) Str::uuid()]);
    }

    // --- Brouillon ---------------------------------------------------------------------------------

    public function test_le_brouillon_s_enregistre_pendant_l_appel_et_se_reprend(): void
    {
        $this->enregistrer(['motif' => 'Fièvre'])->assertOk()->assertJsonPath('existe', true)->assertJsonPath('signee', false)->assertJsonPath('modifiable', true);
        $this->enregistrer(self::NOTE)->assertOk();

        $note = NoteConsultation::sole();
        $this->assertSame([$this->rdv->id, $this->medecinRdv->id, $this->patientRdv->id], [$note->rendez_vous_id, $note->medecin_id, $note->patient_id]);
        $this->assertSame('CA02', $note->diagnostic_code, 'Code normalisé en majuscules.');
        $this->assertNull($note->signee_le);

        $this->commeMedecinRdv()->getJson($this->adresse('personnel.medecin.consultation.note'))
            ->assertOk()->assertJsonPath('champs.conduite', self::NOTE['conduite'])->assertJsonPath('champs.diagnostic_code', 'CA02')
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private');
    }

    public function test_sans_note_le_motif_declare_par_le_patient_sert_de_point_de_depart(): void
    {
        $this->rdv->demande->forceFill(['motif' => 'Toux sèche'])->save();

        $this->commeMedecinRdv()->getJson($this->adresse('personnel.medecin.consultation.note'))
            ->assertOk()->assertJsonPath('existe', false)->assertJsonPath('champs.motif', 'Toux sèche');
        $this->assertSame(0, NoteConsultation::count(), 'Rien n\'est écrit tant que le médecin n\'enregistre pas.');
    }

    public function test_un_champ_vide_est_enregistre_vide_jamais_avec_une_valeur_supposee(): void
    {
        $this->enregistrer(['motif' => '  Fièvre  ', 'examen' => '   ', 'conduite' => ''])->assertOk();

        $note = NoteConsultation::sole();
        $this->assertSame(['Fièvre', null, null, null, null, null], [$note->motif, $note->examen, $note->hypotheses, $note->diagnostic_code, $note->diagnostic_libelle, $note->conduite]);
    }

    public function test_le_diagnostic_cim11_a_un_format_controle(): void
    {
        foreach (['BA00', '1A00.0', 'CA40.Z', '2C6Z&XH0YZ8', 'NE84/XA1234'] as $code) {
            $this->enregistrer(['diagnostic_code' => $code, 'diagnostic_libelle' => 'Libellé saisi'])->assertOk();
        }

        foreach ([
            ['diagnostic_code' => 'I10', 'diagnostic_libelle' => 'Hypertension'], // CIM-10, pas CIM-11
            ['diagnostic_code' => 'IA00', 'diagnostic_libelle' => 'x lettre I'],
            ['diagnostic_code' => 'BAA0', 'diagnostic_libelle' => 'Troisième caractère non chiffre'],
            ['diagnostic_code' => 'BA00.123', 'diagnostic_libelle' => 'Trop long'],
            ['diagnostic_code' => '<script>', 'diagnostic_libelle' => 'Injection'],
            ['diagnostic_code' => 'BA00'],
            ['diagnostic_libelle' => 'Libellé sans code'],
            ['diagnostic_code' => 'BA00', 'diagnostic_libelle' => "Ligne\nbrisée"],
            ['motif' => str_repeat('a', NoteService::TEXTE_MAX + 1)],
        ] as $invalide) {
            $this->enregistrer($invalide)->assertStatus(422);
        }

        $this->assertSame('NE84/XA1234', NoteConsultation::sole()->diagnostic_code);
    }

    // --- Accès ---------------------------------------------------------------------------------------

    public function test_seul_le_medecin_de_ce_rendez_vous_lit_et_ecrit_la_note(): void
    {
        $this->enregistrer()->assertOk();
        $note = NoteConsultation::sole();

        $confrere = $this->medecinActive()->user;
        $this->commePersonnel($confrere)->getJson($this->adresse('personnel.medecin.consultation.note'))->assertForbidden();
        $this->commePersonnel($confrere)->putJson($this->adresse('personnel.medecin.consultation.note.enregistrer'), ['motif' => 'Intrus'])->assertForbidden();
        $this->commePersonnel($confrere)->postJson($this->adresse('personnel.medecin.consultation.note.signer'))->assertForbidden();

        foreach (['caisse', 'admin_fonctionnel'] as $role) {
            $this->commePersonnel($this->personnelActive($role))->putJson($this->adresse('personnel.medecin.consultation.note.enregistrer'), ['motif' => 'Intrus'])->assertForbidden();
        }
        // Le patient n'entre pas dans l'espace du personnel.
        $this->assertNotSame(200, $this->commePatientRdv()->putJson($this->adresse('personnel.medecin.consultation.note.enregistrer'), ['motif' => 'Intrus'])->status());

        $this->assertSame(self::NOTE['motif'], $note->fresh()->motif);
    }

    public function test_un_rendez_vous_annule_expire_ou_absent_n_ouvre_pas_la_note(): void
    {
        foreach ([StatutRendezVous::AnnuleParPatient, StatutRendezVous::AnnuleParMedecin, StatutRendezVous::Expire, StatutRendezVous::AbsencePatient, StatutRendezVous::EnAttentePaiement] as $statut) {
            $this->rdv->forceFill(['statut' => $statut])->save();

            $this->commeMedecinRdv()->getJson($this->adresse('personnel.medecin.consultation.note'))->assertForbidden();
            $this->enregistrer()->assertForbidden();
        }
        $this->assertSame(0, NoteConsultation::count());
    }

    public function test_hors_de_la_fenetre_un_rendez_vous_confirme_se_lit_mais_ne_s_ecrit_pas(): void
    {
        // Avant l'ouverture de la salle (09:00 moins 10 minutes).
        $this->travelTo($this->lundi('08:45'));
        $this->enregistrer()->assertForbidden()->assertJsonPath('message', 'La note et l\'ordonnance se rédigent pendant la consultation, ou après quand elle est terminée.');

        $this->travelTo($this->lundi('08:50'));
        $this->enregistrer()->assertOk();

        // Après la fermeture, rendez-vous encore « confirmé » (la tâche de fin n'est pas passée).
        $this->travelTo($this->lundi('10:21'));
        $this->enregistrer(['motif' => 'Trop tard'])->assertForbidden();
        $this->signer()->assertForbidden();
        $this->commeMedecinRdv()->getJson($this->adresse('personnel.medecin.consultation.note'))->assertOk()->assertJsonPath('modifiable', false);
        $this->assertSame(self::NOTE['motif'], NoteConsultation::sole()->motif);
    }

    public function test_une_note_non_signee_se_complete_et_se_signe_apres_la_consultation(): void
    {
        $this->enregistrer(['motif' => 'Fièvre'])->assertOk();
        $this->rdv->forceFill(['statut' => StatutRendezVous::Termine])->save();

        $this->travelTo($this->lundi('18:00'));
        $this->enregistrer()->assertOk();
        $this->signer()->assertOk()->assertJsonPath('signee', true);
    }

    // --- Signature -----------------------------------------------------------------------------------

    public function test_signer_fige_la_note_avec_l_empreinte_de_tout_son_contenu(): void
    {
        $this->enregistrer()->assertOk();
        $this->travel(5)->minutes();

        $this->signer()->assertOk()->assertJsonPath('signee', true)->assertJsonPath('modifiable', false)->assertJsonPath('message', 'Note signée.');

        $note = NoteConsultation::sole();
        $this->assertTrue($note->signee_le->equalTo($this->lundi('09:10')));
        $attendu = Empreinte::de([
            'rendez_vous_id' => $this->rdv->id, 'medecin_id' => $this->medecinRdv->id, 'patient_id' => $this->patientRdv->id,
            'signee_le' => $this->lundi('09:10')->utc()->format('Y-m-d\TH:i:s\Z'),
            'motif' => self::NOTE['motif'], 'examen' => self::NOTE['examen'], 'hypotheses' => self::NOTE['hypotheses'],
            'diagnostic_code' => 'CA02', 'diagnostic_libelle' => self::NOTE['diagnostic_libelle'], 'conduite' => self::NOTE['conduite'],
        ]);
        $this->assertSame($attendu, $note->empreinte);
        $this->assertTrue(NoteService::estIntegre($note));
    }

    public function test_une_note_signee_ne_change_plus(): void
    {
        $this->enregistrer()->assertOk();
        $this->signer()->assertOk();
        $avant = NoteConsultation::sole()->getAttributes();

        $this->enregistrer(['motif' => 'Réécrit'])->assertForbidden()->assertJsonPath('message', 'Cette note est signée : elle ne se modifie plus. Ajoutez un avenant.');
        $this->signer(['motif' => 'Réécrit', 'conduite' => 'Autre'])->assertForbidden();

        $this->assertSame($avant, NoteConsultation::sole()->getAttributes());
    }

    public function test_un_double_clic_sur_signer_ne_signe_qu_une_fois(): void
    {
        $this->enregistrer()->assertOk();
        $this->signer()->assertOk();
        $premiere = NoteConsultation::sole();

        $this->travel(1)->minutes();
        $this->signer()->assertForbidden()->assertJsonPath('message', 'Cette note est déjà signée. Pour la compléter, ajoutez un avenant.');

        $this->assertSame([$premiere->signee_le->toIso8601String(), $premiere->empreinte], [NoteConsultation::sole()->signee_le->toIso8601String(), NoteConsultation::sole()->empreinte]);
    }

    public function test_signer_avec_la_derniere_saisie_l_enregistre_d_abord(): void
    {
        $this->enregistrer(['motif' => 'Fièvre'])->assertOk();

        $this->signer(self::NOTE)->assertOk();

        $this->assertSame(self::NOTE['conduite'], NoteConsultation::sole()->conduite);
        $this->assertTrue(NoteService::estIntegre(NoteConsultation::sole()));
    }

    public function test_signer_exige_le_motif_et_la_conduite_a_tenir(): void
    {
        $this->signer()->assertForbidden()->assertJsonPath('message', 'Rédigez la note avant de la signer.');
        $this->enregistrer(['motif' => 'Fièvre'])->assertOk();

        $this->signer()->assertForbidden()->assertJsonPath('message', 'Pour signer, renseignez au moins le motif et la conduite à tenir.');
        $this->assertNull(NoteConsultation::sole()->signee_le);
    }

    public function test_l_empreinte_reste_verifiable_quand_la_signature_tombe_entre_deux_secondes(): void
    {
        $this->enregistrer()->assertOk();
        // La base garde la seconde (arrondie) : l'empreinte doit porter sur la même valeur.
        $this->travelTo($this->lundi('09:07')->addMicroseconds(700000));
        $this->signer()->assertOk();
        $this->avenant('Précision.')->assertCreated();

        $this->assertTrue(NoteService::estIntegre(NoteConsultation::sole()));
        $note = NoteConsultation::sole();
        $avenant = AvenantNote::sole();
        $this->assertSame($avenant->empreinte, Empreinte::de(NoteService::contenuAvenant($avenant, $note)));
    }

    public function test_une_alteration_en_base_se_voit_a_l_empreinte(): void
    {
        $this->enregistrer()->assertOk();
        $this->signer()->assertOk();

        // Hors de l'application : déclencheur contourné.
        DB::statement('ALTER TABLE notes_consultation DISABLE TRIGGER notes_signees_non_modifiables');
        DB::table('notes_consultation')->update(['conduite' => 'Conduite réécrite']);
        DB::statement('ALTER TABLE notes_consultation ENABLE TRIGGER notes_signees_non_modifiables');

        $this->assertFalse(NoteService::estIntegre(NoteConsultation::sole()));
    }

    // --- Avenants ------------------------------------------------------------------------------------

    public function test_un_avenant_date_signe_et_empreint_complete_une_note_signee(): void
    {
        $this->enregistrer()->assertOk();
        $this->avenant('Précision sur l\'examen.')->assertForbidden()->assertJsonPath('message', 'La note n\'est pas encore signée : modifiez-la directement.');
        $this->signer()->assertOk();
        $this->travel(1)->hours();

        $this->avenant('  Résultat du test rapide : négatif.  ')->assertCreated()
            ->assertJsonPath('avenants.0.contenu', 'Résultat du test rapide : négatif.')
            ->assertJsonPath('message', 'Avenant ajouté.');

        $note = NoteConsultation::sole();
        $avenant = AvenantNote::sole();
        $this->assertSame([$note->id, $this->medecinRdv->user_id], [$avenant->note_id, $avenant->auteur_id]);
        $this->assertTrue($avenant->signe_le->equalTo($this->lundi('10:05')));
        $this->assertSame(Empreinte::de([
            'note_id' => $note->id, 'note_empreinte' => $note->empreinte, 'auteur_id' => $this->medecinRdv->user_id,
            'contenu' => 'Résultat du test rapide : négatif.', 'signe_le' => $this->lundi('10:05')->utc()->format('Y-m-d\TH:i:s\Z'),
        ]), $avenant->empreinte);
        $this->assertTrue(NoteService::estIntegre($note), 'La note elle-même ne bouge pas.');
    }

    public function test_un_avenant_se_redige_aussi_apres_la_fenetre_quand_la_consultation_est_terminee(): void
    {
        $this->enregistrer()->assertOk();
        $this->signer()->assertOk();
        $this->rdv->forceFill(['statut' => StatutRendezVous::Termine])->save();

        $this->travelTo($this->lundi('09:00')->addMonth());
        $this->avenant('Le patient signale une amélioration.')->assertCreated();
    }

    public function test_un_double_envoi_d_avenant_n_en_cree_qu_un(): void
    {
        $this->enregistrer()->assertOk();
        $this->signer()->assertOk();
        $cle = (string) Str::uuid();

        $this->avenant('Précision.', $cle)->assertCreated();
        $this->avenant('Précision.', $cle)->assertCreated();

        $this->assertSame(1, AvenantNote::count());
    }

    public function test_un_avenant_est_refuse_a_un_autre_medecin_et_sans_texte(): void
    {
        $this->enregistrer()->assertOk();
        $this->signer()->assertOk();

        $this->commePersonnel($this->medecinActive()->user)->postJson($this->adresse('personnel.medecin.consultation.note.avenant'), ['contenu' => 'Intrus', 'cle' => (string) Str::uuid()])->assertForbidden();
        $this->avenant('')->assertStatus(422);
        $this->assertSame(0, AvenantNote::count());

        // Méthode publique (rectification acceptée, DOS-04) : mêmes règles.
        $this->expectException(AccesRefuse::class);
        app(NoteService::class)->avenant($this->medecinActive()->user, NoteConsultation::sole(), 'Intrus');
    }

    public function test_la_methode_publique_d_avenant_sert_a_une_rectification(): void
    {
        $this->enregistrer()->assertOk();
        $this->signer()->assertOk();

        $avenant = app(NoteService::class)->avenant($this->medecinRdv->user, NoteConsultation::sole(), 'Rectification acceptée : la date de début des symptômes est le 4 décembre.');

        $this->assertTrue($avenant->exists);
        $this->assertNull($avenant->cle_idempotence);
    }

    // --- Page « Note » --------------------------------------------------------------------------------

    public function test_la_page_note_s_ouvre_depuis_l_agenda_pour_un_rendez_vous_termine(): void
    {
        $this->rdv->forceFill(['statut' => StatutRendezVous::Termine])->save();
        $this->travelTo($this->lundi('15:00'));

        $this->commeMedecinRdv()->get(route('personnel.medecin.rendez-vous.note', $this->rdv->id))
            ->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('Personnel/Medecin/Note')->where('rendezVous.id', $this->rdv->id));
        $this->commePersonnel($this->medecinActive()->user)->get(route('personnel.medecin.rendez-vous.note', $this->rdv->id))->assertNotFound();

        $this->rdv->forceFill(['statut' => StatutRendezVous::AnnuleParPatient])->save();
        $this->commeMedecinRdv()->get(route('personnel.medecin.rendez-vous.note', $this->rdv->id))->assertNotFound();
    }

    public function test_l_agenda_propose_la_note_des_consultations_terminees_recentes(): void
    {
        $this->enregistrer()->assertOk();
        $this->rdv->forceFill(['statut' => StatutRendezVous::Termine])->save();
        $annule = $this->rendezVousExistant($this->medecinRdv, $this->patientRdv, $this->lundi('07:00'), statut: StatutRendezVous::AnnuleParPatient);
        $this->travelTo($this->lundi('15:00'));

        $this->commeMedecinRdv()->get(route('personnel.medecin.agenda'))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Personnel/Medecin/Agenda')
                ->has('recentes', 1)
                ->where('recentes.0.id', $this->rdv->id)
                ->where('recentes.0.note', 'brouillon'));
        $this->assertNotNull($annule);
    }
}
