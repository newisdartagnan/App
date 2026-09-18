<?php

namespace Tests\Feature;

use App\Models\Consultation;
use App\Models\Establishment;
use App\Models\Patient;
use App\Models\Service;
use App\Models\TypeConsultation;
use App\Models\User;
use App\Models\Visit;
use App\Services\RapportSnisService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Ce que les canevas du SNIS réclament du dossier patient.
 *
 * Trois manques relevés en lisant les quatre formulaires officiels : d'où
 * vient le patient, qui est le nouveau cas, et comment le séjour s'est
 * terminé. Chacun se remplissait de mémoire à la fin du mois, faute d'être
 * posé au moment où on le sait.
 *
 * Ce qui est vérifié ici : que la question est posée à l'accueil, que la
 * réponse se garde, et qu'elle ressort dans le rapport mensuel.
 */
class DossierSelonLeCanevasTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Establishment $etab;

    protected Carbon $mois;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->admin = User::where('email', 'admin@dpi-rdc.local')->firstOrFail();
        $this->etab = Establishment::firstOrFail();
        $this->actingAs($this->admin);

        $this->mois = Carbon::create(2026, 7, 1)->startOfMonth();
    }

    protected function patient(string $nom, array $extra = []): Patient
    {
        return Patient::create(array_merge([
            'establishment_id' => $this->etab->id,
            'dossier_number' => 'CAN-'.$nom.'-'.random_int(1000, 9999),
            'nom' => $nom, 'prenom' => 'Test', 'sexe' => 'F',
            'date_naissance' => $this->mois->copy()->subYears(30)->toDateString(),
            'type_prise_en_charge' => 'prive',
        ], $extra));
    }

    protected function passage(Patient $patient, array $extra = []): Visit
    {
        return Visit::create(array_merge([
            'patient_id' => $patient->id,
            'establishment_id' => $this->etab->id,
            'user_id' => $this->admin->id,
            'type' => 'consultation_externe',
            'statut' => 'termine',
            'date_entree' => $this->mois->copy()->addDays(4)->setTime(9, 0),
            'motif_consultation' => 'Fièvre',
        ], $extra));
    }

    protected function rapport(): array
    {
        return app(RapportSnisService::class)
            ->rapport($this->mois->year, $this->mois->month, $this->etab->id);
    }

    // ═══════════════════════════════════════════════════════════
    // D'où vient le patient
    // ═══════════════════════════════════════════════════════════

    public function test_laccueil_demande_la_provenance_du_patient(): void
    {
        $patient = $this->patient('PROVENANCE');

        $this->get(route('patients.show', $patient))
            ->assertOk()
            ->assertSee('Provenance')
            ->assertSee('Référé par une structure de santé')
            ->assertSee('Orienté par un relais communautaire (RECO)')
            ->assertSee('Structure qui réfère');
    }

    public function test_la_provenance_saisie_a_laccueil_se_garde(): void
    {
        $patient = $this->patient('REFERE');
        $type = TypeConsultation::where('est_actif', true)->firstOrFail();

        $this->post(route('patients.envoyer-caisse', $patient), [
            'type' => 'consultation_externe',
            'type_consultation_id' => $type->id,
            'mode_entree' => 'reference',
            'provenance' => 'CS Kimbanseke',
        ]);

        $visite = Visit::where('patient_id', $patient->id)->firstOrFail();

        $this->assertSame('reference', $visite->mode_entree);
        $this->assertSame('CS Kimbanseke', $visite->provenance);
        $this->assertTrue($visite->estRefere());
    }

    public function test_sans_reponse_le_patient_est_venu_de_lui_meme(): void
    {
        // On n'invente pas une référence qui n'a pas été déclarée.
        $patient = $this->patient('SPONTANE');
        $type = TypeConsultation::where('est_actif', true)->firstOrFail();

        $this->post(route('patients.envoyer-caisse', $patient), [
            'type' => 'consultation_externe',
            'type_consultation_id' => $type->id,
        ]);

        $visite = Visit::where('patient_id', $patient->id)->firstOrFail();

        $this->assertSame('spontane', $visite->mode_entree);
        $this->assertFalse($visite->estRefere());
    }

    public function test_une_provenance_inventee_est_refusee(): void
    {
        $patient = $this->patient('INVENTE');
        $type = TypeConsultation::where('est_actif', true)->firstOrFail();

        $this->post(route('patients.envoyer-caisse', $patient), [
            'type' => 'consultation_externe',
            'type_consultation_id' => $type->id,
            'mode_entree' => 'tombe_du_ciel',
        ])->assertSessionHasErrors('mode_entree');
    }

    public function test_le_parcours_montre_do_vient_le_patient(): void
    {
        $visite = $this->passage($this->patient('PARCOURS'), [
            'mode_entree' => 'contre_reference',
            'provenance' => 'HGR Kinshasa',
        ]);

        $this->get(route('visites.show', $visite))
            ->assertOk()
            ->assertSee('Contre-référé')
            ->assertSee('HGR Kinshasa');
    }

    public function test_le_rapport_ventile_les_consultants_par_provenance(): void
    {
        $this->passage($this->patient('A'), ['mode_entree' => 'reference', 'provenance' => 'CS Masina']);
        $this->passage($this->patient('B'), ['mode_entree' => 'contre_reference']);
        $this->passage($this->patient('C'), ['mode_entree' => 'reco']);
        $this->passage($this->patient('D'), ['mode_entree' => 'spontane']);

        $consultations = $this->rapport()['consultations'];

        // Référé, contre-référé et transféré comptent comme adressés ;
        // le relais communautaire est une ligne à part du canevas.
        $this->assertSame(2, $consultations['referes_recus']);
        $this->assertSame(1, $consultations['contre_references']);
        $this->assertSame(1, $consultations['orientes_par_reco']);
        $this->assertSame(1, $consultations['par_provenance']['Venu de lui-même']);
    }

    // ═══════════════════════════════════════════════════════════
    // Qui est le nouveau cas
    // ═══════════════════════════════════════════════════════════

    public function test_le_dossier_retient_les_caracteristiques_du_canevas(): void
    {
        $patient = $this->patient('SALARIE', [
            'travailleur_secteur_formel' => true,
            'mutualiste' => true,
            'mutuelle_nom' => 'Mutuelle des Enseignants',
        ]);

        $this->assertSame([
            'Travailleur du secteur formel',
            'Mutualiste — Mutuelle des Enseignants',
        ], $patient->caracteristiquesSnis());
    }

    public function test_les_caracteristiques_se_cumulent_avec_lindigence(): void
    {
        // Un mutualiste peut être déclaré indigent : ce ne sont pas des
        // valeurs d'un même champ, sinon on en perdrait une.
        $patient = $this->patient('CUMUL', [
            'mutualiste' => true,
            'type_prise_en_charge' => 'indigent',
        ]);

        $this->assertSame(['Mutualiste', 'Indigent'], $patient->caracteristiquesSnis());
    }

    public function test_le_formulaire_daccueil_propose_les_caracteristiques(): void
    {
        $this->get(route('patients.create'))
            ->assertOk()
            ->assertSee('Travailleur du secteur formel')
            ->assertSee('Mutualiste')
            ->assertSee('Nom de la mutuelle');
    }

    public function test_les_caracteristiques_senregistrent_a_la_creation(): void
    {
        $this->post(route('patients.store'), [
            'nom' => 'KABUYA', 'prenom' => 'Jeanne', 'sexe' => 'F',
            'type_prise_en_charge' => 'prive',
            'travailleur_secteur_formel' => '1',
            'mutualiste' => '1',
            'mutuelle_nom' => 'MUSA',
            'confirm_no_duplicate' => '1',
        ]);

        $patient = Patient::where('nom', 'KABUYA')->firstOrFail();

        $this->assertTrue($patient->travailleur_secteur_formel);
        $this->assertTrue($patient->mutualiste);
        $this->assertSame('MUSA', $patient->mutuelle_nom);
    }

    public function test_la_mutuelle_ne_se_garde_pas_sans_la_case_cochee(): void
    {
        // Sinon un nom resterait accroché à un patient qui n'est plus
        // mutualiste, et le rapport compterait mal.
        $this->post(route('patients.store'), [
            'nom' => 'MBALA', 'prenom' => 'Paul', 'sexe' => 'M',
            'type_prise_en_charge' => 'prive',
            'mutuelle_nom' => 'MUSA',
            'confirm_no_duplicate' => '1',
        ]);

        $patient = Patient::where('nom', 'MBALA')->firstOrFail();

        $this->assertFalse($patient->mutualiste);
        $this->assertNull($patient->mutuelle_nom);
    }

    public function test_le_rapport_compte_les_caracteristiques_des_nouveaux_cas(): void
    {
        $this->passage($this->patient('FORMEL', ['travailleur_secteur_formel' => true]));
        $this->passage($this->patient('MUTUEL', ['mutualiste' => true]));
        $this->passage($this->patient('PAUVRE', ['type_prise_en_charge' => 'indigent']));
        $this->passage($this->patient('LES_DEUX', [
            'travailleur_secteur_formel' => true,
            'mutualiste' => true,
        ]));

        $consultations = $this->rapport()['consultations'];

        $this->assertSame(2, $consultations['nouveaux_travailleurs_formels']);
        $this->assertSame(2, $consultations['nouveaux_mutualistes']);
        $this->assertSame(1, $consultations['nouveaux_indigents']);
    }

    public function test_un_ancien_cas_ne_compte_pas_dans_les_caracteristiques(): void
    {
        // Le canevas parle des nouveaux cas : un retour du même patient ne
        // doit pas le recompter.
        $patient = $this->patient('RETOUR', ['mutualiste' => true]);

        $this->passage($patient, ['date_entree' => $this->mois->copy()->addDays(2)->setTime(9, 0)]);
        $this->passage($patient, ['date_entree' => $this->mois->copy()->addDays(9)->setTime(9, 0)]);

        $consultations = $this->rapport()['consultations'];

        $this->assertSame(2, $consultations['total']);
        $this->assertSame(1, $consultations['nouveaux_mutualistes']);
    }

    // ═══════════════════════════════════════════════════════════
    // Les suites de la consultation
    // ═══════════════════════════════════════════════════════════

    public function test_le_rapport_compte_les_references_et_les_mises_en_observation(): void
    {
        foreach (['reference', 'surveillance', 'hospitalisation', 'domicile'] as $i => $orientation) {
            $visite = $this->passage($this->patient('ORI'.$i));

            Consultation::create([
                'visit_id' => $visite->id,
                'user_id' => $this->admin->id,
                'date_consultation' => $visite->date_entree,
                'orientation' => $orientation,
                'statut' => 'finalise',
            ]);
        }

        $consultations = $this->rapport()['consultations'];

        $this->assertSame(1, $consultations['referes_sortants']);
        $this->assertSame(1, $consultations['mis_en_observation']);
        $this->assertSame(1, $consultations['orientes_hospitalisation']);
    }

    // ═══════════════════════════════════════════════════════════
    // Comment le séjour s'est terminé
    // ═══════════════════════════════════════════════════════════

    public function test_un_patient_parti_sans_prevenir_a_son_mode_de_sortie(): void
    {
        // Il sortait « guéri », faute de mieux : le canevas veut la ligne
        // « évadés / abandons », et la direction aussi.
        $this->assertArrayHasKey('evade', Visit::MODES_SORTIE);
        $this->assertArrayHasKey('stationnaire', Visit::MODES_SORTIE);
        $this->assertStringContainsString('Statu quo', Visit::MODES_SORTIE['stationnaire']);
    }

    public function test_les_deces_se_separent_avant_et_apres_48_heures(): void
    {
        $service = Service::firstOrFail();

        $precoce = $this->passage($this->patient('TOT'), [
            'type' => 'hospitalisation',
            'service_id' => $service->id,
            'date_entree' => $this->mois->copy()->addDays(5)->setTime(8, 0),
            'date_sortie' => $this->mois->copy()->addDays(6)->setTime(8, 0),
            'mode_sortie' => 'deces',
        ]);

        $tardif = $this->passage($this->patient('TARD'), [
            'type' => 'hospitalisation',
            'service_id' => $service->id,
            'date_entree' => $this->mois->copy()->addDays(5)->setTime(8, 0),
            'date_sortie' => $this->mois->copy()->addDays(12)->setTime(8, 0),
            'mode_sortie' => 'deces',
        ]);

        $this->assertTrue($precoce->decedeAvant48h());
        $this->assertFalse($tardif->decedeAvant48h());

        $rapport = $this->rapport();

        $this->assertSame(1, $rapport['hospitalisation']['deces_moins_48h']);
        $this->assertSame(1, $rapport['hospitalisation']['deces_plus_48h']);
        $this->assertSame(1, $rapport['deces']['moins_48h']);
        $this->assertSame(1, $rapport['deces']['plus_48h']);
    }

    public function test_les_admissions_distinguent_les_referes_et_les_petits(): void
    {
        $service = Service::firstOrFail();

        $this->passage($this->patient('ADULTE_REF'), [
            'type' => 'hospitalisation',
            'service_id' => $service->id,
            'mode_entree' => 'reference',
        ]);

        $this->passage($this->patient('BEBE', [
            'date_naissance' => $this->mois->copy()->subMonths(8)->toDateString(),
        ]), [
            'type' => 'hospitalisation',
            'service_id' => $service->id,
        ]);

        $hospitalisation = $this->rapport()['hospitalisation'];

        $this->assertSame(2, $hospitalisation['admissions']);
        $this->assertSame(1, $hospitalisation['admissions_referees']);
        $this->assertSame(1, $hospitalisation['admissions_moins_5ans']);
    }

    // ═══════════════════════════════════════════════════════════
    // Le rapport le montre
    // ═══════════════════════════════════════════════════════════

    public function test_lecran_du_rapport_montre_les_nouvelles_lignes(): void
    {
        $this->passage($this->patient('ECRAN', ['mutualiste' => true]), ['mode_entree' => 'reco']);

        $this->get(route('snis.index', ['annee' => 2026, 'mois' => 7]))
            ->assertOk()
            ->assertSee('Caractéristiques des nouveaux cas')
            ->assertSee('Travailleurs du secteur formel')
            ->assertSee('Provenance')
            ->assertSee('Suites données à la consultation')
            ->assertSee('dont référés')
            ->assertSee('Décès avant 48 h');
    }

    public function test_le_tableur_remonte_les_nouvelles_lignes(): void
    {
        $this->passage($this->patient('CSV', ['travailleur_secteur_formel' => true]));

        $contenu = $this->get(route('snis.csv', ['annee' => 2026, 'mois' => 7]))
            ->assertOk()
            ->streamedContent();

        // fputcsv entoure de guillemets tout champ qui porte une espace.
        $this->assertStringContainsString('"Travailleurs du secteur formel";1', $contenu);
        $this->assertStringContainsString('Provenance des consultants', $contenu);
        $this->assertStringContainsString('Suites données à la consultation', $contenu);
        $this->assertStringContainsString('"dont référés"', $contenu);
        $this->assertStringContainsString('"Décès après 48 h"', $contenu);
    }
}
