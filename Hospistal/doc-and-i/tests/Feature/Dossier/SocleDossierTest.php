<?php

namespace Tests\Feature\Dossier;

use App\Enums\StatutRendezVous;
use App\Models\AvenantNote;
use App\Models\DossierPatient;
use App\Models\LigneOrdonnance;
use App\Models\NoteConsultation;
use App\Models\Ordonnance;
use App\Models\RendezVous;
use App\Services\Dossier\AccesDossier;
use App\Support\Empreinte;
use Database\Seeders\RolesSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Tests\Support\ParcoursRendezVous;
use Tests\Support\PersonnelDeTest;
use Tests\TestCase;

/** Socle du dossier médical et de l'ordonnance : accès, signature, immuabilité. */
class SocleDossierTest extends TestCase
{
    use ParcoursRendezVous, PersonnelDeTest, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesSeeder::class);
        $this->voyagerAuLundi();
    }

    private function note(RendezVous $rdv, bool $signee = false): NoteConsultation
    {
        $note = new NoteConsultation;
        $note->forceFill([
            'rendez_vous_id' => $rdv->id, 'medecin_id' => $rdv->medecin_id, 'patient_id' => $rdv->patient_id,
            'motif' => 'Toux', 'conduite' => 'Repos',
            'signee_le' => $signee ? now() : null,
            'empreinte' => $signee ? Empreinte::de(['motif' => 'Toux', 'conduite' => 'Repos']) : null,
        ])->save();

        return $note;
    }

    private function ordonnance(RendezVous $rdv): Ordonnance
    {
        $o = new Ordonnance;
        $o->forceFill([
            'numero' => 'OR-2026-000001', 'rendez_vous_id' => $rdv->id, 'medecin_id' => $rdv->medecin_id, 'patient_id' => $rdv->patient_id,
            'signee_le' => now(), 'empreinte' => str_repeat('a', 64), 'code_verification' => Str::random(32),
        ])->save();
        $l = new LigneOrdonnance;
        $l->forceFill(['ordonnance_id' => $o->id, 'rang' => 1, 'medicament' => 'Paracétamol', 'dosage' => '500 mg', 'voie' => 'orale', 'posologie' => '1 comprimé 3 fois par jour', 'duree' => '3 jours'])->save();

        return $o;
    }

    public function test_le_patient_et_ses_medecins_traitants_lisent_le_dossier_les_autres_non(): void
    {
        $patient = $this->patient();
        $medecin = $this->medecinActive();
        $this->rendezVousExistant($medecin, $patient, $this->lundi('09:00'));
        $acces = app(AccesDossier::class);

        $this->assertTrue($acces->peutLire($patient, $patient));
        $this->assertTrue($acces->peutLire($medecin->user, $patient));

        $autreMedecin = $this->medecinActive();
        $this->rendezVousExistant($autreMedecin, $patient, $this->lundi('10:00'), statut: StatutRendezVous::AnnuleParPatient);
        foreach ([$this->patient(), $autreMedecin->user, $this->personnelActive('caisse'), $this->personnelActive('admin_fonctionnel')] as $tiers) {
            $this->assertFalse($acces->peutLire($tiers, $patient), 'Accès accordé à un tiers.');
        }

        $medecin->user->forceFill(['desactive_le' => now()])->save();
        $this->assertFalse($acces->peutLire($medecin->user->fresh(), $patient), 'Médecin désactivé.');
    }

    public function test_seul_le_medecin_du_rendez_vous_ecrit_dans_ce_rendez_vous(): void
    {
        $medecin = $this->medecinActive();
        $rdv = $this->rendezVousExistant($medecin, $this->patient(), $this->lundi('09:00'));
        $acces = app(AccesDossier::class);

        $this->assertTrue($acces->medecinDuRendezVous($medecin->user, $rdv));
        $this->assertFalse($acces->medecinDuRendezVous($this->medecinActive()->user, $rdv));

        $rdv->forceFill(['statut' => StatutRendezVous::Expire])->save();
        $this->assertFalse($acces->medecinDuRendezVous($medecin->user, $rdv->fresh()));
    }

    public function test_le_groupe_sanguin_n_a_aucune_valeur_par_defaut(): void
    {
        $dossier = new DossierPatient;
        $dossier->forceFill(['patient_id' => $this->patient()->id])->save();

        $this->assertNull($dossier->fresh()->groupe_sanguin, 'Vide = inconnu, jamais une valeur supposée.');

        $this->expectException(QueryException::class);
        DB::table('dossiers_patients')->where('id', $dossier->id)->update(['groupe_sanguin' => 'Z+']);
    }

    public function test_une_note_brouillon_se_modifie_une_note_signee_non(): void
    {
        $rdv = $this->rendezVousExistant($this->medecinActive(), $this->patient(), $this->lundi('09:00'));
        $note = $this->note($rdv);

        $note->forceFill(['examen' => 'Auscultation normale'])->save();
        $note->forceFill(['signee_le' => now(), 'empreinte' => str_repeat('b', 64)])->save();

        try {
            $note->forceFill(['conduite' => 'Autre'])->save();
            $this->fail('Note signée modifiée par le modèle.');
        } catch (LogicException) {
        }

        foreach (["UPDATE notes_consultation SET conduite = 'x'", 'DELETE FROM notes_consultation'] as $sql) {
            try {
                DB::transaction(fn () => DB::statement($sql));
                $this->fail("La base a accepté : {$sql}");
            } catch (QueryException $e) {
                $this->assertMatchesRegularExpression('/non modifiable|ne se supprime pas/', $e->getMessage());
            }
        }
    }

    public function test_ordonnances_lignes_et_avenants_ne_se_modifient_ni_ne_se_suppriment(): void
    {
        $rdv = $this->rendezVousExistant($this->medecinActive(), $this->patient(), $this->lundi('09:00'));
        $this->ordonnance($rdv);
        $note = $this->note($rdv, true);
        $avenant = new AvenantNote;
        $avenant->forceFill(['note_id' => $note->id, 'auteur_id' => $rdv->medecin->user_id, 'contenu' => 'Précision', 'signe_le' => now(), 'empreinte' => str_repeat('c', 64)])->save();

        foreach (['ordonnances', 'lignes_ordonnance', 'avenants_note'] as $table) {
            foreach (["UPDATE {$table} SET updated_at = now()", "DELETE FROM {$table}", "TRUNCATE {$table} CASCADE"] as $sql) {
                try {
                    DB::transaction(fn () => DB::statement($sql));
                    $this->fail("La base a accepté : {$sql}");
                } catch (QueryException $e) {
                    $this->assertStringContainsString('non modifiable', $e->getMessage());
                }
            }
        }
    }

    public function test_le_code_de_verification_n_est_pas_serialise(): void
    {
        $o = $this->ordonnance($this->rendezVousExistant($this->medecinActive(), $this->patient(), $this->lundi('09:00')));

        $this->assertArrayNotHasKey('code_verification', $o->toArray());
    }

    public function test_l_empreinte_ne_depend_pas_de_l_ordre_des_cles_et_revele_une_alteration(): void
    {
        $a = Empreinte::de(['motif' => 'Toux', 'lignes' => [['dosage' => '500 mg', 'medicament' => 'X']]]);

        $this->assertSame($a, Empreinte::de(['lignes' => [['medicament' => 'X', 'dosage' => '500 mg']], 'motif' => 'Toux']));
        $this->assertTrue(Empreinte::verifier(['motif' => 'Toux', 'lignes' => [['medicament' => 'X', 'dosage' => '500 mg']]], $a));
        $this->assertFalse(Empreinte::verifier(['motif' => 'Toux', 'lignes' => [['medicament' => 'X', 'dosage' => '1 g']]], $a));
    }

    public function test_un_antecedent_ne_se_supprime_pas_il_se_retire(): void
    {
        $patient = $this->patient();
        DB::table('antecedents')->insert([
            'id' => (string) Str::uuid(), 'patient_id' => $patient->id, 'categorie' => 'allergie', 'libelle' => 'Pénicilline',
            'saisi_par' => $patient->id, 'source' => 'patient', 'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('antecedents')->update(['retire_le' => now(), 'retire_par' => $patient->id]);
        $this->assertSame(1, DB::table('antecedents')->whereNotNull('retire_le')->count());

        $this->expectException(QueryException::class);
        DB::statement('DELETE FROM antecedents');
    }
}
