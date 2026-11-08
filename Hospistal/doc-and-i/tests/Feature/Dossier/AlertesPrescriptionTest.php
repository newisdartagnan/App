<?php

namespace Tests\Feature\Dossier;

use App\Models\AlertePrescription;
use App\Models\EntreeAccesDossier;
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

    /** Les clés de maintien que le serveur a renvoyées avec la réponse 422. */
    private function clesRenvoyees(TestResponse $reponse): array
    {
        return array_column($reponse->assertStatus(422)->json('alertes'), 'cle');
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
            ->assertJsonPath('alertes.0.cle', AlertesPrescription::cle($this->rdv, 2, 'PENICILLINE V 1 MUI', "allergie:{$allergie}"));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $reponse->json('alertes.0.cle'), 'Clé HMAC émise par le serveur.');
        $this->assertStringContainsString('Allergie connue du patient : « Pénicilline »', $reponse->json('alertes.0.message'));
        $this->rienEmis();

        $this->emettre(['Paracétamol', 'PENICILLINE V 1 MUI'], $this->clesRenvoyees($reponse))->assertCreated();

        $trace = AlertePrescription::sole();
        $this->assertSame([Ordonnance::sole()->id, 2, 'allergie', $reponse->json('alertes.0.cle'), $this->medecinRdv->user_id],
            [$trace->ordonnance_id, $trace->rang, $trace->type, $trace->cle, $trace->maintenue_par]);
        $this->assertTrue($trace->maintenue_le->equalTo($this->lundi('09:05')));
        $this->assertStringContainsString('Pénicilline', $trace->message);
    }

    public function test_une_allergie_redigee_librement_alerte_quand_meme(): void
    {
        // Revue de sécurité : ces trois libellés se taisaient face à « Pénicilline V ».
        foreach (['Allergie à la pénicilline', 'Pénicilline (urticaire)', 'pénicillines'] as $libelle) {
            $this->assertTrue(AlertesPrescription::serecouvrent('Pénicilline V', $libelle), $libelle);
        }

        $this->antecedent('allergie', 'Allergie à la pénicilline');
        $this->emettre(['Pénicilline V'])->assertStatus(422)->assertJsonPath('alertes.0.type', 'allergie');
    }

    public function test_la_comparaison_par_jetons_ignore_accents_casse_ponctuation_et_mots_vides(): void
    {
        foreach ([
            ['Céfalexine', 'CEFALEXINE 500 mg'],                       // accents et casse
            ['Pénicilline', 'Benzylpénicilline'],                        // un jeton en contient un autre
            ['Amoxicilline (urticaire en 2019)', 'amoxicilline'],        // parenthèses et date
            ['acide acétylsalicylique', 'Acide-acétylsalicylique 100'], // ponctuation
            ['Allergique aux sulfamides', 'Sulfamide'],                  // mots vides et pluriel
            ['Anti-inflammatoires', 'Anti-inflammatoire non stéroïdien'],
        ] as [$allergie, $medicament]) {
            $this->assertTrue(AlertesPrescription::serecouvrent($medicament, $allergie), "{$allergie} / {$medicament}");
        }

        // Faux positifs acceptables : un mot commun suffit, le médecin maintient en connaissance de cause.
        $this->assertTrue(AlertesPrescription::serecouvrent('Acide folique', 'Acide acétylsalicylique'));
        $this->assertTrue(AlertesPrescription::serecouvrent('Vitamine B12', 'Vitamine C (gélules)'));

        // Rien de commun, ou seulement des mots vides, des nombres ou des jetons trop courts.
        $this->assertFalse(AlertesPrescription::serecouvrent('Paracétamol', 'Pénicilline'));
        $this->assertFalse(AlertesPrescription::serecouvrent('Paracétamol 500 mg', 'Amoxicilline 500'));
        $this->assertFalse(AlertesPrescription::serecouvrent('Fer', 'Allergie aux fruits de mer'), 'Allergie et mots vides ne comptent pas.');
        $this->assertFalse(AlertesPrescription::serecouvrent('Vitamine C', 'C'), 'Trop court pour signifier quelque chose.');
        $this->assertFalse(AlertesPrescription::serecouvrent('C', 'Vitamine C'), 'Dans les deux sens.');

        $this->assertSame('penicilline g injectable', AlertesPrescription::normaliser('  Pénicilline G, injectable ! '));
        $this->assertSame(['penicilline'], AlertesPrescription::jetons('Allergie à la pénicilline'));
        $this->assertSame(['penicilline', 'urticaire'], AlertesPrescription::jetons('Pénicillines (urticaire)'));
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

    public function test_chaque_alerte_doit_etre_maintenue_avec_la_cle_renvoyee_par_le_serveur(): void
    {
        $this->antecedent('allergie', 'Pénicilline');
        $this->antecedent('allergie', 'Aspirine');
        [$aspirine, $penicilline] = $this->clesRenvoyees($this->emettre(['Aspirine 500', 'Pénicilline V']));

        // Une seule des deux maintenue : toujours bloqué, la maintenue est rendue cochée.
        $this->emettre(['Aspirine 500', 'Pénicilline V'], [$penicilline])->assertStatus(422)
            ->assertJsonCount(2, 'alertes')
            ->assertJsonPath('alertes.0.maintenue', false)
            ->assertJsonPath('alertes.1.maintenue', true);
        $this->rienEmis();

        $this->emettre(['Aspirine 500', 'Pénicilline V'], [$aspirine, $penicilline])->assertCreated();
        $this->assertSame(2, AlertePrescription::count());
    }

    public function test_un_maintien_envoye_d_avance_ou_pour_un_autre_medicament_est_refuse(): void
    {
        $allergie = $this->antecedent('allergie', 'Pénicilline');

        // D'avance : une clé devinée (ancien format lisible, ou HMAC sans la clé du serveur).
        $this->emettre(['Pénicilline V'], ["allergie:1:{$allergie}", hash_hmac('sha256', $this->rdv->id."\n1\npenicilline v\nallergie:{$allergie}", 'devinee')])
            ->assertStatus(422)->assertJsonPath('alertes.0.maintenue', false);

        // Obtenue pour un médicament, puis la ligne change (toujours la même allergie) : elle ne vaut plus.
        $cles = $this->clesRenvoyees($this->emettre(['Pénicilline V']));
        $this->emettre(['Benzylpénicilline 1 MUI'], $cles)->assertStatus(422)->assertJsonPath('alertes.0.maintenue', false);
        // Ni pour un autre rendez-vous.
        $autre = $this->rendezVousExistant($this->medecinRdv, $this->patientRdv, $this->lundi('09:20'));
        $this->assertNotSame($cles[0], AlertesPrescription::cle($autre, 1, 'Pénicilline V', "allergie:{$allergie}"));
        $this->rienEmis();

        $this->emettre(['Pénicilline V'], $cles)->assertCreated();
    }

    public function test_la_reponse_des_alertes_est_notee_au_journal_des_acces(): void
    {
        $this->antecedent('allergie', 'Pénicilline');
        $this->emettre(['Pénicilline V'])->assertStatus(422);

        // Hors de la transaction annulée : la lecture reste au journal.
        $entree = EntreeAccesDossier::where('objet', 'alertes_prescription')->sole();
        $this->assertSame([$this->medecinRdv->user_id, $this->patientRdv->id, $this->rdv->id], [$entree->lecteur_id, $entree->patient_id, $entree->objet_id]);

        $this->emettre(['Paracétamol'])->assertCreated();
        $this->assertSame(1, EntreeAccesDossier::where('objet', 'alertes_prescription')->count(), 'Pas d\'alerte, rien de lu.');
    }

    public function test_la_trace_du_maintien_ne_se_modifie_pas(): void
    {
        $this->antecedent('allergie', 'Pénicilline');
        $this->emettre(['Pénicilline V'], $this->clesRenvoyees($this->emettre(['Pénicilline V'])))->assertCreated();

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
        $this->interactionDeTest();
        $lignes = ['SUBSTANCE TEST B 5 mg', 'Paracétamol', 'Substance-Test-A 10 mg'];

        $reponse = $this->emettre($lignes)->assertStatus(422)
            ->assertJsonCount(1, 'alertes')
            ->assertJsonPath('alertes.0.type', 'interaction')
            ->assertJsonPath('alertes.0.rang', 1);
        $this->rienEmis();

        $this->emettre($lignes, $this->clesRenvoyees($reponse))->assertCreated();
        $this->assertSame('interaction', AlertePrescription::sole()->type);
        $this->assertStringContainsString('Interaction de test (donnée fictive).', AlertePrescription::sole()->message);
    }

    public function test_une_interaction_avec_un_traitement_en_cours_declare_alerte_aussi(): void
    {
        $this->interactionDeTest();
        $this->antecedent('traitement', 'Substance test B, 1 par jour');
        $this->antecedent('traitement', 'Substance test B', retire: true);

        $this->emettre(['Substance-test-A'])->assertStatus(422)
            ->assertJsonCount(1, 'alertes')
            ->assertJsonPath('alertes.0.type', 'interaction');
    }

    public function test_sans_interaction_connue_rien_n_alerte(): void
    {
        $this->interactionDeTest();

        $this->emettre(['Substance-test-A', 'Paracétamol'])->assertCreated();
        $this->assertSame(0, AlertePrescription::count());
    }
}
