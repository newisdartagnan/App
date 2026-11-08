<?php

namespace Tests\Feature\Dossier;

use App\Enums\StatutRendezVous;
use App\Models\Antecedent;
use App\Models\Constante;
use App\Models\DossierPatient;
use App\Models\Medecin;
use App\Models\User;
use App\Services\Dossier\DossierService;
use Database\Seeders\RolesSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\ParcoursRendezVous;
use Tests\Support\PersonnelDeTest;
use Tests\TestCase;

/** DOS-01 : saisie du dossier par le patient et par un médecin traitant. */
class SaisieDossierTest extends TestCase
{
    use ParcoursRendezVous, PersonnelDeTest, RefreshDatabase;

    private User $patientDossier;

    private Medecin $medecinTraitant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesSeeder::class);
        // Salle du rendez-vous de 9 h ouverte : le médecin a vu le patient et peut modifier.
        $this->voyagerAuLundi('08:55');
        $this->patientDossier = $this->patient();
        $this->medecinTraitant = $this->medecinActive();
        $this->rendezVousExistant($this->medecinTraitant, $this->patientDossier, $this->lundi('09:00'));
    }

    private function urlMedecin(string $suffixe = ''): string
    {
        return "/personnel/medecin/patients/{$this->patientDossier->id}/dossier{$suffixe}";
    }

    private function antecedent(User $auteur, string $categorie = 'allergie', string $libelle = 'Pénicilline'): Antecedent
    {
        return app(DossierService::class)->ajouterAntecedent($auteur, $this->patientDossier, $categorie, $libelle);
    }

    public function test_le_patient_declare_une_allergie_saisie_a_son_nom(): void
    {
        $this->actingAs($this->patientDossier)
            ->post('/mon-dossier/antecedents', ['categorie' => 'allergie', 'libelle' => '  Pénicilline ', 'precision' => 'Plaques rouges'])
            ->assertRedirect()->assertSessionHasNoErrors()->assertSessionHas('succes');

        $a = Antecedent::sole();
        $this->assertSame([$this->patientDossier->id, 'allergie', 'Pénicilline', 'Plaques rouges', 'patient', $this->patientDossier->id],
            [$a->patient_id, $a->categorie, $a->libelle, $a->precision, $a->source, $a->saisi_par]);
    }

    public function test_double_envoi_d_un_element_un_seul_enregistrement(): void
    {
        $this->actingAs($this->patientDossier);
        $this->post('/mon-dossier/antecedents', ['categorie' => 'traitement', 'libelle' => 'Metformine 500 mg']);
        $this->post('/mon-dossier/antecedents', ['categorie' => 'traitement', 'libelle' => 'metformine 500 mg']);

        $this->assertSame(1, Antecedent::count());
    }

    public function test_aucun_type_par_defaut_le_type_est_obligatoire(): void
    {
        $this->actingAs($this->patientDossier)
            ->post('/mon-dossier/antecedents', ['libelle' => 'Asthme'])
            ->assertSessionHasErrors('categorie');
        $this->actingAs($this->patientDossier)
            ->post('/mon-dossier/antecedents', ['categorie' => 'autre', 'libelle' => 'Asthme'])
            ->assertSessionHasErrors('categorie');

        $this->assertSame(0, Antecedent::count());
    }

    public function test_le_groupe_sanguin_reste_vide_tant_qu_il_n_est_pas_choisi(): void
    {
        $this->actingAs($this->patientDossier);
        $this->get('/mon-dossier')->assertInertia(fn ($page) => $page->component('Dossier/MonDossier')->where('groupeSanguin', null));

        $this->post('/mon-dossier/groupe-sanguin', [])->assertSessionHasErrors('groupe_sanguin');
        $this->post('/mon-dossier/groupe-sanguin', ['groupe_sanguin' => 'Z+'])->assertSessionHasErrors('groupe_sanguin');
        $this->assertNull(DossierPatient::where('patient_id', $this->patientDossier->id)->value('groupe_sanguin'));

        $this->post('/mon-dossier/groupe-sanguin', ['groupe_sanguin' => 'O+'])->assertSessionHasNoErrors();
        $dossier = DossierPatient::where('patient_id', $this->patientDossier->id)->sole();
        $this->assertSame(['O+', $this->patientDossier->id], [$dossier->groupe_sanguin, $dossier->groupe_sanguin_par]);

        // « Je ne sais pas » vide le champ, sans rien supposer.
        $this->post('/mon-dossier/groupe-sanguin', ['groupe_sanguin' => 'inconnu'])->assertSessionHasNoErrors();
        $dossier->refresh();
        $this->assertNull($dossier->groupe_sanguin);
        $this->assertNull($dossier->groupe_sanguin_par);
    }

    public function test_le_patient_ne_remplace_pas_un_groupe_saisi_par_un_medecin(): void
    {
        $this->commePersonnel($this->medecinTraitant->user)->post($this->urlMedecin('/groupe-sanguin'), ['groupe_sanguin' => 'A-'])
            ->assertSessionHasNoErrors();

        $this->actingAs($this->patientDossier)->post('/mon-dossier/groupe-sanguin', ['groupe_sanguin' => 'B+'])
            ->assertSessionHasErrors(['groupe_sanguin' => 'Votre groupe sanguin a été saisi par un médecin : demandez-en la rectification.']);
        $this->assertSame('A-', DossierPatient::where('patient_id', $this->patientDossier->id)->value('groupe_sanguin'));
    }

    public function test_les_constantes_sont_bornees_et_la_virgule_acceptee(): void
    {
        $this->actingAs($this->patientDossier);

        $this->post('/mon-dossier/constantes', ['type' => 'poids', 'valeur' => '725'])
            ->assertSessionHasErrors(['constante' => 'Poids : la valeur doit être entre 0,5 et 400 kg. Vérifiez la mesure.']);
        $this->post('/mon-dossier/constantes', ['type' => 'temperature', 'valeur' => '29'])->assertSessionHasErrors('constante');
        $this->post('/mon-dossier/constantes', ['type' => 'glycemie', 'valeur' => 'beaucoup'])->assertSessionHasErrors('constante');
        $this->post('/mon-dossier/constantes', ['type' => 'tension', 'valeur' => '80', 'valeur2' => '120'])->assertSessionHasErrors('constante');
        $this->post('/mon-dossier/constantes', ['type' => 'tension', 'valeur' => '120'])->assertSessionHasErrors('constante');
        $this->post('/mon-dossier/constantes', ['type' => 'poids', 'valeur' => '70', 'valeur2' => '3'])->assertSessionHasErrors('constante');
        $this->post('/mon-dossier/constantes', ['type' => 'poids', 'valeur' => '70', 'mesure_le' => now()->addDay()->format('Y-m-d\TH:i')])
            ->assertSessionHasErrors('constante');
        $this->post('/mon-dossier/constantes', ['valeur' => '70'])->assertSessionHasErrors('type');
        $this->assertSame(0, Constante::count());

        $this->post('/mon-dossier/constantes', ['type' => 'poids', 'valeur' => '72,5'])->assertSessionHasNoErrors();
        $this->post('/mon-dossier/constantes', ['type' => 'tension', 'valeur' => '120', 'valeur2' => '80'])->assertSessionHasNoErrors();

        $this->get('/mon-dossier')->assertInertia(fn ($page) => $page
            ->where('constantes.0.type', 'poids')->where('constantes.0.valeur', '72,5 kg')
            ->where('constantes.1.type', 'tension')->where('constantes.1.valeur', '120/80 mmHg'));
    }

    public function test_double_envoi_d_une_mesure_une_seule_ligne(): void
    {
        $this->actingAs($this->patientDossier);
        $this->post('/mon-dossier/constantes', ['type' => 'temperature', 'valeur' => '38,2']);
        $this->post('/mon-dossier/constantes', ['type' => 'temperature', 'valeur' => '38.2']);

        $this->assertSame(1, Constante::count());
    }

    public function test_retirer_n_efface_pas_l_element_reste_dans_l_historique(): void
    {
        $a = $this->antecedent($this->patientDossier, 'traitement', 'Ibuprofène');

        $this->actingAs($this->patientDossier)->post("/mon-dossier/antecedents/{$a->id}/retrait")->assertSessionHasNoErrors();

        $a->refresh();
        $this->assertNotNull($a->retire_le);
        $this->assertSame($this->patientDossier->id, $a->retire_par);
        $this->get('/mon-dossier')->assertInertia(fn ($page) => $page
            ->where('elements.1.categorie', 'traitement')->where('elements.1.elements', [])
            ->where('historique.0.type', 'antecedent_retire')->where('historique.0.titre', 'Traitement en cours retiré(e) : Ibuprofène'));

        $this->post("/mon-dossier/antecedents/{$a->id}/retrait")
            ->assertSessionHasErrors(['retrait' => 'Cet élément est déjà retiré du dossier.']);
    }

    public function test_la_base_refuse_de_supprimer_un_element_ou_une_mesure(): void
    {
        $a = $this->antecedent($this->patientDossier);
        app(DossierService::class)->ajouterConstante($this->patientDossier, $this->patientDossier, 'poids', '70');

        foreach (['DELETE FROM antecedents', 'DELETE FROM constantes', 'TRUNCATE antecedents CASCADE'] as $sql) {
            try {
                DB::transaction(fn () => DB::statement($sql));
                $this->fail("La base a accepté : {$sql}");
            } catch (QueryException) {
            }
        }
        $this->assertTrue($a->exists && Antecedent::whereKey($a->id)->exists());
    }

    public function test_le_patient_ne_retire_pas_un_element_saisi_par_un_medecin(): void
    {
        $a = $this->antecedent($this->medecinTraitant->user, 'medical', 'Hypertension');
        $this->assertSame('medecin', $a->source);

        $this->actingAs($this->patientDossier)->post("/mon-dossier/antecedents/{$a->id}/retrait")
            ->assertSessionHasErrors(['retrait' => 'Cet élément a été saisi par un médecin : demandez-en la rectification.']);
        $this->assertNull($a->fresh()->retire_le);
    }

    public function test_le_patient_ne_touche_pas_au_dossier_d_un_autre_patient(): void
    {
        $a = $this->antecedent($this->patientDossier);

        $this->actingAs($this->patient())->post("/mon-dossier/antecedents/{$a->id}/retrait")->assertNotFound();
        $this->assertNull($a->fresh()->retire_le);
    }

    public function test_le_medecin_traitant_ajoute_et_retire(): void
    {
        $duPatient = $this->antecedent($this->patientDossier, 'allergie', 'Arachide');
        $this->commePersonnel($this->medecinTraitant->user);

        $this->post($this->urlMedecin('/antecedents'), ['categorie' => 'chirurgical', 'libelle' => 'Césarienne'])->assertSessionHasNoErrors();
        $this->post($this->urlMedecin('/constantes'), ['type' => 'frequence_cardiaque', 'valeur' => '72'])->assertSessionHasNoErrors();
        $this->post($this->urlMedecin("/antecedents/{$duPatient->id}/retrait"))->assertSessionHasNoErrors();

        $ajoute = Antecedent::where('libelle', 'Césarienne')->sole();
        $this->assertSame(['medecin', $this->medecinTraitant->user_id], [$ajoute->source, $ajoute->saisi_par]);
        $this->assertSame($this->medecinTraitant->user_id, $duPatient->fresh()->retire_par);
        $this->assertSame($this->medecinTraitant->user_id, Constante::sole()->saisi_par);
    }

    public function test_un_element_d_un_autre_patient_n_est_pas_retire_par_l_adresse_de_ce_patient(): void
    {
        $autre = $this->patient();
        $this->rendezVousExistant($this->medecinTraitant, $autre, $this->lundi('10:00'));
        $a = app(DossierService::class)->ajouterAntecedent($autre, $autre, 'allergie', 'Latex');

        $this->commePersonnel($this->medecinTraitant->user)->post($this->urlMedecin("/antecedents/{$a->id}/retrait"))->assertNotFound();
        $this->assertNull($a->fresh()->retire_le);
    }

    public function test_un_medecin_sans_relation_de_soin_n_ecrit_rien(): void
    {
        foreach ([StatutRendezVous::AnnuleParPatient, StatutRendezVous::Expire, StatutRendezVous::EnAttentePaiement, null] as $statut) {
            $medecin = $this->medecinActive();
            if ($statut) {
                $this->rendezVousExistant($medecin, $this->patientDossier, $this->lundi('11:00'), statut: $statut);
            }
            $this->commePersonnel($medecin->user);
            $this->post($this->urlMedecin('/antecedents'), ['categorie' => 'allergie', 'libelle' => 'Aspirine'])->assertNotFound();
            $this->post($this->urlMedecin('/constantes'), ['type' => 'poids', 'valeur' => '70'])->assertNotFound();
            $this->post($this->urlMedecin('/groupe-sanguin'), ['groupe_sanguin' => 'AB+'])->assertNotFound();
        }

        $this->assertSame(0, Antecedent::count() + Constante::count());
        $this->assertNull(DossierPatient::where('patient_id', $this->patientDossier->id)->value('groupe_sanguin'));
    }

    public function test_caisse_et_administration_n_ecrivent_rien(): void
    {
        foreach (['caisse', 'admin_fonctionnel'] as $role) {
            $this->commePersonnel($this->personnelActive($role))
                ->post($this->urlMedecin('/antecedents'), ['categorie' => 'allergie', 'libelle' => 'Aspirine'])->assertForbidden();
        }
        $this->assertSame(0, Antecedent::count());
    }

    public function test_le_service_refuse_un_tiers_meme_sans_passer_par_l_ecran(): void
    {
        $this->expectExceptionMessage('Ce dossier ne vous est pas ouvert.');
        app(DossierService::class)->ajouterAntecedent($this->patient(), $this->patientDossier, 'allergie', 'Aspirine');
    }

    /** Revue de sécurité : une saisie refusée par la validation ne laisse pas de donnée de santé en session (old input). */
    public function test_une_validation_ratee_ne_garde_pas_la_saisie_en_session(): void
    {
        $envois = [
            fn () => $this->actingAs($this->patientDossier)->post('/mon-dossier/antecedents', ['libelle' => 'Allergie-SECRETE', 'precision' => 'Précision-SECRETE']),
            fn () => $this->actingAs($this->patientDossier)->post('/mon-dossier/constantes', ['valeur' => '73-SECRET']),
            fn () => $this->actingAs($this->patientDossier)->post('/mon-dossier/groupe-sanguin', ['groupe_sanguin' => 'SECRET']),
            fn () => $this->actingAs($this->patientDossier)->post('/mon-dossier/rectifications', ['demande' => 'SECRET '.str_repeat('x', 2001)]),
            fn () => $this->commePersonnel($this->medecinTraitant->user)->post($this->urlMedecin('/antecedents'), ['libelle' => 'Allergie-SECRETE']),
        ];
        foreach ($envois as $envoyer) {
            $reponse = $envoyer()->assertRedirect()->assertSessionHas('errors');
            $reponse->assertSessionMissing('_old_input');
            $this->assertStringNotContainsString('SECRET', serialize(session()->all()), 'Donnée de santé gardée en session.');
            session()->flush();
        }

        $demande = app(DossierService::class)->demanderRectification($this->patientDossier, 'Je ne suis pas allergique.');
        $this->commePersonnel($this->medecinTraitant->user)
            ->post($this->urlMedecin("/rectifications/{$demande->id}/reponse"), ['motif' => 'Motif-SECRET'])
            ->assertSessionHasErrors('decision')->assertSessionMissing('_old_input');
        $this->assertStringNotContainsString('SECRET', serialize(session()->all()));
    }
}
