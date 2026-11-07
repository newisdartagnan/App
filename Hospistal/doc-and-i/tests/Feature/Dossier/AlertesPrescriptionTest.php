<?php

namespace Tests\Feature\Dossier;

use App\Models\AlertePrescription;
use App\Models\InteractionMedicamenteuse;
use App\Models\Ordonnance;
use App\Models\User;
use App\Services\Dossier\AlertesPrescription;
use App\Sms\EnvoyeurSms;
use Database\Seeders\RolesSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\EnvoyeurSmsFactice;
use Tests\Support\ParcoursConsultation;
use Tests\Support\ParcoursRendezVous;
use Tests\Support\PersonnelDeTest;
use Tests\TestCase;

/**
 * PRE-02 : alerte bloquante à la prescription (allergie connue, interaction
 * majeure), maintenue explicitement et tracée. Les interactions utilisées
 * ici sont des données de TEST (substances fictives) : la table est livrée
 * vide, son contenu viendra du pharmacien du client.
 */
class AlertesPrescriptionTest extends TestCase
{
    use ParcoursConsultation, ParcoursRendezVous, PersonnelDeTest, RefreshDatabase;

    private EnvoyeurSmsFactice $sms;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesSeeder::class);
        $this->preparerConsultation('09:05');
        $this->sms = new EnvoyeurSmsFactice;
        $this->app->instance(EnvoyeurSms::class, $this->sms);
    }

    private function antecedent(string $categorie, string $libelle, ?User $patient = null, bool $retire = false): string
    {
        $patient ??= $this->patientRdv;
        $id = (string) Str::uuid();
        DB::table('antecedents')->insert([
            'id' => $id, 'patient_id' => $patient->id, 'categorie' => $categorie, 'libelle' => $libelle,
            'saisi_par' => $patient->id, 'source' => 'patient', 'retire_le' => $retire ? now() : null, 'retire_par' => $retire ? $patient->id : null,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    private function interactionDeTest(string $a = 'Substance-test-A', string $b = 'Substance-test-B'): InteractionMedicamenteuse
    {
        $i = new InteractionMedicamenteuse;
        $i->forceFill(['dci_a' => $a, 'dci_b' => $b, 'message' => 'Interaction de test (donnée fictive).'])->save();

        return $i;
    }

    private function emettre(array $medicaments, array $maintiens = [], ?string $cle = null): TestResponse
    {
        $lignes = array_map(fn ($m) => OrdonnanceTest::ligne(['medicament' => $m]), $medicaments);

        return $this->commeMedecinRdv()->postJson(route('personnel.medecin.consultation.ordonnances.emettre', $this->rdv->id), [
            'cle' => $cle ?? (string) Str::uuid(), 'lignes' => $lignes, 'maintiens' => $maintiens,
        ]);
    }

    private function rienEmis(): void
    {
        $this->assertSame(0, Ordonnance::count());
        $this->assertSame(0, (int) DB::table('compteurs_numerotation')->where('prefixe', 'OR')->value('dernier'), 'Aucun numéro consommé.');
        $this->assertSame([], $this->sms->envoyes);
    }

    // --- Allergies --------------------------------------------------------------------------------

    public function test_une_allergie_connue_bloque_puis_le_maintien_est_trace(): void
    {
        $allergie = $this->antecedent('allergie', 'Pénicilline');

        $reponse = $this->emettre(['Paracétamol', 'PENICILLINE V 1 MUI'])->assertStatus(422)
            ->assertJsonPath('message', 'Alertes à la prescription : confirmez chacune pour émettre l\'ordonnance.')
            ->assertJsonCount(1, 'alertes')
            ->assertJsonPath('alertes.0.type', 'allergie')
            ->assertJsonPath('alertes.0.rang', 2)
            ->assertJsonPath('alertes.0.maintenue', false)
            ->assertJsonPath('alertes.0.cle', "allergie:2:{$allergie}");
        $this->assertStringContainsString('Allergie connue du patient : « Pénicilline »', $reponse->json('alertes.0.message'));
        $this->rienEmis();

        $this->emettre(['Paracétamol', 'PENICILLINE V 1 MUI'], ["allergie:2:{$allergie}"])->assertCreated();

        $trace = AlertePrescription::sole();
        $this->assertSame([Ordonnance::sole()->id, 2, 'allergie', "allergie:2:{$allergie}", $this->medecinRdv->user_id],
            [$trace->ordonnance_id, $trace->rang, $trace->type, $trace->cle, $trace->maintenue_par]);
        $this->assertTrue($trace->maintenue_le->equalTo($this->lundi('09:05')));
        $this->assertStringContainsString('Pénicilline', $trace->message);
    }

    public function test_la_comparaison_ignore_accents_casse_et_ponctuation_et_marche_par_inclusion(): void
    {
        foreach ([
            ['Céfalexine', 'CEFALEXINE 500 mg'],        // accents et casse
            ['Pénicilline', 'Benzylpénicilline'],         // l'allergie dans le médicament
            ['Amoxicilline (urticaire en 2019)', 'amoxicilline'], // le médicament dans l'allergie
            ['acide acétylsalicylique', 'Acide-acétylsalicylique 100'], // ponctuation
        ] as [$allergie, $medicament]) {
            $this->assertTrue(AlertesPrescription::serecouvrent($medicament, $allergie), "{$allergie} / {$medicament}");
        }
        $this->assertFalse(AlertesPrescription::serecouvrent('Paracétamol', 'Pénicilline'));
        $this->assertFalse(AlertesPrescription::serecouvrent('Vitamine C', 'C'), 'Trop court pour signifier quelque chose.');
        $this->assertFalse(AlertesPrescription::serecouvrent('C', 'Vitamine C'), 'Dans les deux sens.');
        $this->assertSame('penicilline g injectable', AlertesPrescription::normaliser('  Pénicilline G, injectable ! '));
    }

    public function test_seules_les_allergies_actives_de_ce_patient_alertent(): void
    {
        $this->antecedent('allergie', 'Pénicilline', retire: true);
        $this->antecedent('allergie', 'Paracétamol', $this->patient());
        $this->antecedent('medical', 'Paracétamol');
        $this->antecedent('traitement', 'Paracétamol');

        $this->emettre(['Paracétamol', 'Pénicilline V'])->assertCreated();
        $this->assertSame(0, AlertePrescription::count());
    }

    public function test_chaque_alerte_doit_etre_maintenue_et_pour_ces_lignes_la(): void
    {
        $penicilline = $this->antecedent('allergie', 'Pénicilline');
        $aspirine = $this->antecedent('allergie', 'Aspirine');

        // Une seule des deux maintenue : toujours bloqué, la maintenue est rendue cochée.
        $this->emettre(['Aspirine 500', 'Pénicilline V'], ["allergie:2:{$penicilline}"])->assertStatus(422)
            ->assertJsonCount(2, 'alertes')
            ->assertJsonPath('alertes.0.maintenue', false)
            ->assertJsonPath('alertes.1.maintenue', true);
        // Une clé inventée ou celle d'une autre ligne ne vaut pas maintien.
        $this->emettre(['Pénicilline V'], ["allergie:2:{$penicilline}", 'allergie:1:'.Str::uuid()])->assertStatus(422);
        $this->rienEmis();

        $this->emettre(['Aspirine 500', 'Pénicilline V'], ["allergie:1:{$aspirine}", "allergie:2:{$penicilline}"])->assertCreated();
        $this->assertSame(2, AlertePrescription::count());
    }

    public function test_la_trace_du_maintien_ne_se_modifie_pas(): void
    {
        $allergie = $this->antecedent('allergie', 'Pénicilline');
        $this->emettre(['Pénicilline V'], ["allergie:1:{$allergie}"])->assertCreated();

        foreach (['UPDATE alertes_prescription SET message = \'x\'', 'DELETE FROM alertes_prescription'] as $sql) {
            try {
                DB::transaction(fn () => DB::statement($sql));
                $this->fail("La base a accepté : {$sql}");
            } catch (QueryException $e) {
                $this->assertStringContainsString('non modifiable', $e->getMessage());
            }
        }
    }

    // --- Interactions ------------------------------------------------------------------------------

    public function test_la_table_des_interactions_est_livree_vide(): void
    {
        $this->assertSame(0, InteractionMedicamenteuse::count(), 'Contenu médical à fournir par le pharmacien du client.');
        $this->emettre(['Paracétamol', 'Amoxicilline'])->assertCreated();
    }

    public function test_une_interaction_majeure_entre_deux_lignes_bloque_puis_se_maintient(): void
    {
        $interaction = $this->interactionDeTest();

        $this->emettre(['SUBSTANCE TEST B 5 mg', 'Paracétamol', 'Substance-Test-A 10 mg'])->assertStatus(422)
            ->assertJsonCount(1, 'alertes')
            ->assertJsonPath('alertes.0.type', 'interaction')
            ->assertJsonPath('alertes.0.rang', 1)
            ->assertJsonPath('alertes.0.cle', "interaction:1:ligne3:{$interaction->id}");
        $this->rienEmis();

        $this->emettre(['SUBSTANCE TEST B 5 mg', 'Paracétamol', 'Substance-Test-A 10 mg'], ["interaction:1:ligne3:{$interaction->id}"])->assertCreated();
        $this->assertSame('interaction', AlertePrescription::sole()->type);
        $this->assertStringContainsString('Interaction de test (donnée fictive).', AlertePrescription::sole()->message);
    }

    public function test_une_interaction_avec_un_traitement_en_cours_declare_alerte_aussi(): void
    {
        $interaction = $this->interactionDeTest();
        $traitement = $this->antecedent('traitement', 'Substance test B, 1 par jour');
        $this->antecedent('traitement', 'Substance test B', retire: true);

        $this->emettre(['Substance-test-A'])->assertStatus(422)
            ->assertJsonCount(1, 'alertes')
            ->assertJsonPath('alertes.0.cle', "interaction:1:traitement:{$traitement}:{$interaction->id}");
    }

    public function test_sans_interaction_connue_rien_n_alerte(): void
    {
        $this->interactionDeTest();

        $this->emettre(['Substance-test-A', 'Paracétamol'])->assertCreated();
        $this->assertSame(0, AlertePrescription::count());
    }
}
