<?php

namespace Tests\Feature;

use App\Models\Accouchement;
use App\Models\Consultation;
use App\Models\ConsultationPrenatale;
use App\Models\Establishment;
use App\Models\ExamenLaboratoire;
use App\Models\Grossesse;
use App\Models\NouveauNe;
use App\Models\Patient;
use App\Models\ResultatExamen;
use App\Models\StockMedicament;
use App\Models\TypeExamen;
use App\Models\User;
use App\Models\Visit;
use App\Services\RapportSnisService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Le rapport mensuel du SNIS.
 *
 * Il se remplit à la main, registre après registre, une journée entière par
 * mois — alors que chaque chiffre est déjà en base. Ce qui compte ici, c'est
 * qu'on ne compte que ce qui est enregistré : un rapport qui invente est pire
 * qu'un rapport incomplet, parce que personne ne sait plus lesquels de ses
 * chiffres croire.
 */
class RapportSnisTest extends TestCase
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

    protected function patient(string $nom, int $ageMois, string $sexe = 'F'): Patient
    {
        return Patient::create([
            'establishment_id' => $this->etab->id,
            'dossier_number' => 'SNIS-'.$nom.'-'.random_int(1000, 9999),
            'nom' => $nom, 'prenom' => 'Test', 'sexe' => $sexe,
            'date_naissance' => $this->mois->copy()->subMonths($ageMois)->toDateString(),
            'type_prise_en_charge' => 'prive',
        ]);
    }

    protected function passage(Patient $patient, int $jour, string $type = 'consultation_externe'): Visit
    {
        return Visit::create([
            'patient_id' => $patient->id,
            'establishment_id' => $this->etab->id,
            'user_id' => $this->admin->id,
            'type' => $type,
            'statut' => 'termine',
            'date_entree' => $this->mois->copy()->addDays($jour - 1)->setTime(9, 0),
            'motif_consultation' => 'Fièvre',
        ]);
    }

    protected function diagnostic(Visit $visite, string $libelle, ?string $cim = null): Consultation
    {
        return Consultation::create([
            'visit_id' => $visite->id,
            'user_id' => $this->admin->id,
            'date_consultation' => $visite->date_entree,
            'diagnostics' => [array_filter([
                'libelle' => $libelle,
                'code_cim10' => $cim,
                'type' => 'principal',
            ])],
            'statut' => 'finalise',
        ]);
    }

    protected function rapport(): array
    {
        return app(RapportSnisService::class)
            ->rapport($this->mois->year, $this->mois->month, $this->etab->id);
    }

    // ═══════════════════════════════════════════════════════════
    // Consultations : tranches d'âge et nouveaux cas
    // ═══════════════════════════════════════════════════════════

    public function test_les_consultations_se_ventilent_par_tranche_dage_et_par_sexe(): void
    {
        $this->passage($this->patient('NOURRISSON', 6, 'M'), 3);
        $this->passage($this->patient('ENFANT', 30, 'F'), 4);
        $this->passage($this->patient('ECOLIER', 96, 'M'), 5);
        $this->passage($this->patient('ADULTE', 300, 'F'), 6);
        $this->passage($this->patient('AINE', 700, 'M'), 7);

        $lignes = $this->rapport()['consultations']['lignes'];

        // Les bornes du canevas ne sont pas celles du bon sens : nourrisson
        // jusqu'à onze mois, enfant jusqu'à cinquante-neuf.
        $this->assertSame(1, $lignes['moins_1an']['nouveaux_m']);
        $this->assertSame(1, $lignes['moins_5ans']['nouveaux_f']);
        $this->assertSame(1, $lignes['scolaire']['nouveaux_m']);
        $this->assertSame(1, $lignes['adulte']['nouveaux_f']);
        $this->assertSame(1, $lignes['age_mur']['nouveaux_m']);
        $this->assertSame(5, $this->rapport()['consultations']['total']);
    }

    public function test_le_retour_dun_patient_est_un_ancien_cas(): void
    {
        $patient = $this->patient('REVENANT', 300, 'F');
        $this->passage($patient, 3);
        $this->passage($patient, 20);

        $rapport = $this->rapport()['consultations'];

        // Est nouveau le premier passage, ancien tout retour.
        $this->assertSame(1, $rapport['nouveaux']);
        $this->assertSame(1, $rapport['anciens']);
        $this->assertSame(2, $rapport['total']);
    }

    public function test_un_patient_sans_date_de_naissance_va_dans_lage_inconnu(): void
    {
        $patient = Patient::create([
            'establishment_id' => $this->etab->id,
            'dossier_number' => 'SNIS-SANSAGE',
            'nom' => 'SANSAGE', 'prenom' => 'Test', 'sexe' => 'M',
            'type_prise_en_charge' => 'prive',
        ]);
        $this->passage($patient, 10);

        // On ne devine pas un âge : la ligne existe pour ça.
        $this->assertSame(1, $this->rapport()['consultations']['lignes']['inconnu']['total']);
    }

    public function test_le_mois_voisin_nentre_pas_dans_le_compte(): void
    {
        $patient = $this->patient('VOISIN', 300);
        $visite = $this->passage($patient, 1);
        $visite->update(['date_entree' => $this->mois->copy()->subDay()]);

        $this->assertSame(0, $this->rapport()['consultations']['total']);
    }

    public function test_les_urgences_sont_comptees_a_part(): void
    {
        $this->passage($this->patient('URGENT', 300), 5, 'urgence');
        $this->passage($this->patient('AMBU', 300), 6);

        $rapport = $this->rapport()['consultations'];

        $this->assertSame(2, $rapport['total']);
        $this->assertSame(1, $rapport['urgences']);
    }

    // ═══════════════════════════════════════════════════════════
    // Morbidité
    // ═══════════════════════════════════════════════════════════

    public function test_les_pathologies_du_canevas_se_reconnaissent(): void
    {
        $this->diagnostic($this->passage($this->patient('A', 6), 3), 'Paludisme simple');
        $this->diagnostic($this->passage($this->patient('B', 300), 4), 'Pneumonie franche');
        $this->diagnostic($this->passage($this->patient('C', 30), 5), 'Diarrhée aiguë');

        $lignes = $this->rapport()['morbidite']['lignes'];

        $this->assertSame(1, $lignes['paludisme']['total']);
        $this->assertSame(1, $lignes['paludisme']['moins_5ans']);
        $this->assertSame(1, $lignes['ira']['plus_5ans']);
        $this->assertSame(1, $lignes['diarrhee']['moins_5ans']);
    }

    public function test_le_code_cim10_rattrape_un_libelle_inhabituel(): void
    {
        $this->diagnostic($this->passage($this->patient('D', 300), 6), 'Accès fébrile palustre', 'B50.9');

        $this->assertSame(1, $this->rapport()['morbidite']['lignes']['paludisme']['total']);
    }

    public function test_rien_ne_se_perd_dans_le_compte_des_diagnostics(): void
    {
        $this->diagnostic($this->passage($this->patient('E', 300), 7), 'Paludisme simple');
        $this->diagnostic($this->passage($this->patient('F', 300), 8), 'Lombalgie commune');

        $morbidite = $this->rapport()['morbidite'];

        // Le total des lignes égale le total des diagnostics : l'écart se voit.
        $this->assertSame(2, $morbidite['total_diagnostics']);
        $this->assertSame(1, $morbidite['lignes']['autres']['total']);
        $this->assertSame(
            $morbidite['total_diagnostics'],
            collect($morbidite['toutes_lignes'])->sum('total')
        );
    }

    // ═══════════════════════════════════════════════════════════
    // Hospitalisation et décès
    // ═══════════════════════════════════════════════════════════

    public function test_les_journees_dhospitalisation_se_comptent_sur_le_mois(): void
    {
        $visite = $this->passage($this->patient('HOSP', 300), 10, 'hospitalisation');
        $visite->update([
            'date_entree' => $this->mois->copy()->addDays(9),
            'date_sortie' => $this->mois->copy()->addDays(14),
            'duree_sejour_jours' => 5,
            'mode_sortie' => 'gueri',
        ]);

        $hosp = $this->rapport()['hospitalisation'];

        $this->assertSame(1, $hosp['admissions']);
        $this->assertSame(1, $hosp['sorties']);
        $this->assertSame(5, $hosp['journees']);
        $this->assertSame(1, $hosp['par_issue']['Guéri']);
    }

    public function test_un_sejour_a_cheval_ne_compte_que_ses_journees_du_mois(): void
    {
        $visite = $this->passage($this->patient('CHEVAL', 300), 1, 'hospitalisation');
        $visite->update([
            'date_entree' => $this->mois->copy()->subDays(5),
            'date_sortie' => $this->mois->copy()->addDays(3),
        ]);

        // Entré le mois d'avant : il n'est pas une admission du mois, mais
        // ses journées de ce mois-ci comptent.
        $hosp = $this->rapport()['hospitalisation'];

        $this->assertSame(0, $hosp['admissions']);
        $this->assertSame(3, $hosp['journees']);
    }

    public function test_les_deces_se_comptent_et_se_ventilent(): void
    {
        $visite = $this->passage($this->patient('DECEDE', 6, 'M'), 12, 'hospitalisation');
        $visite->update([
            'date_entree' => $this->mois->copy()->addDays(11)->setTime(8, 0),
            'date_sortie' => $this->mois->copy()->addDays(11)->setTime(20, 0),
            'mode_sortie' => 'deces',
        ]);

        $deces = $this->rapport()['deces'];

        $this->assertSame(1, $deces['total']);
        $this->assertSame(1, $deces['moins_48h']);
        $this->assertSame(1, $deces['par_tranche']['0 – 11 mois']);
    }

    // ═══════════════════════════════════════════════════════════
    // Santé de la mère
    // ═══════════════════════════════════════════════════════════

    public function test_la_maternite_remonte_ses_indicateurs(): void
    {
        $patiente = $this->patient('MERE', 300, 'F');

        $grossesse = Grossesse::create([
            'patient_id' => $patiente->id,
            'establishment_id' => $this->etab->id,
            'user_id' => $this->admin->id,
            'gestite' => 2, 'parite' => 1, 'avortements' => 0,
            'date_dernieres_regles' => $this->mois->copy()->subWeeks(38)->toDateString(),
            'date_prevue_accouchement' => $this->mois->copy()->addWeeks(2)->toDateString(),
            'statut' => 'en_cours',
        ]);

        ConsultationPrenatale::create([
            'grossesse_id' => $grossesse->id,
            'user_id' => $this->admin->id,
            'date_consultation' => $this->mois->copy()->addDays(4),
            'numero' => 1,
            'vat_dose' => 2,
            'fer_folates' => true,
            'sulfadoxine_pyrimethamine' => true,
            'moustiquaire_remise' => true,
        ]);

        $accouchement = Accouchement::create([
            'grossesse_id' => $grossesse->id,
            'patient_id' => $patiente->id,
            'date_accouchement' => $this->mois->copy()->addDays(15),
            'terme_semaines' => 39,
            'mode' => 'cesarienne',
            'saignement_ml' => 700,
            'accoucheur_id' => $this->admin->id,
        ]);

        NouveauNe::create([
            'accouchement_id' => $accouchement->id,
            'rang' => 1, 'sexe' => 'F', 'poids_g' => 2100,
            'apgar_1' => 8, 'apgar_5' => 9, 'statut' => 'vivant',
        ]);
        NouveauNe::create([
            'accouchement_id' => $accouchement->id,
            'rang' => 2, 'sexe' => 'M', 'poids_g' => 2600,
            'statut' => 'mort_ne',
        ]);

        $mat = $this->rapport()['maternite'];

        $this->assertSame(1, $mat['cpn_total']);
        $this->assertSame(1, $mat['cpn_par_rang']['CPN 1']);
        $this->assertSame(1, $mat['vat_administres']);
        $this->assertSame(1, $mat['sp_administres']);
        $this->assertSame(1, $mat['moustiquaires']);
        $this->assertSame(1, $mat['accouchements']);
        $this->assertSame(1, $mat['cesariennes']);
        // 700 ml après une césarienne : sous le seuil de 1000 ml, pas d'hémorragie.
        $this->assertSame(0, $mat['hemorragies']);
        $this->assertSame(1, $mat['naissances_vivantes']);
        $this->assertSame(1, $mat['mort_nes']);
        $this->assertSame(1, $mat['petits_poids']);
    }

    // ═══════════════════════════════════════════════════════════
    // Plateaux techniques et pharmacie
    // ═══════════════════════════════════════════════════════════

    public function test_le_laboratoire_et_limagerie_se_distinguent(): void
    {
        foreach (['labo', 'imagerie'] as $domaine) {
            $patient = $this->patient('EX'.$domaine, 300);

            $examen = ExamenLaboratoire::create([
                'numero_bon' => strtoupper(substr($domaine, 0, 3)).'-'.random_int(1000, 9999),
                'patient_id' => $patient->id,
                'prescripteur_id' => $this->admin->id,
                'date_prescription' => $this->mois->copy()->addDays(8),
                'date_resultat' => $this->mois->copy()->addDays(8),
                'statut' => 'valide',
                'domaine' => $domaine,
            ]);

            $type = TypeExamen::where('domaine', $domaine)->firstOrFail();

            ResultatExamen::create([
                'examen_id' => $examen->id,
                'type_examen_id' => $type->id,
                'parametre' => $type->libelle,
                'valeur_brute' => 'x',
                'valeurs_reference' => [],
            ]);
        }

        $labo = $this->rapport()['laboratoire'];

        $this->assertSame(1, $labo['demandes_labo']);
        $this->assertSame(1, $labo['demandes_imagerie']);
        $this->assertSame(2, $labo['validees']);
    }

    public function test_la_pharmacie_remonte_ses_ruptures(): void
    {
        $stock = StockMedicament::whereNotNull('medicament_id')->firstOrFail();
        $stock->update(['quantite_disponible' => 0]);

        $pharmacie = $this->rapport()['pharmacie'];

        $this->assertGreaterThanOrEqual(1, $pharmacie['ruptures']);
        $this->assertGreaterThan(0, $pharmacie['references']);
    }

    // ═══════════════════════════════════════════════════════════
    // Honnêteté du rapport
    // ═══════════════════════════════════════════════════════════

    public function test_les_rubriques_non_suivies_sont_declarees(): void
    {
        $rapport = $this->rapport();

        // Un rapport qui invente est pire qu'un rapport incomplet.
        $this->assertNotEmpty($rapport['non_suivi']);
        $this->assertContains(
            'Vaccination — Programme élargi de vaccination',
            $rapport['non_suivi']
        );
    }

    public function test_un_mois_vide_rend_des_zeros_et_non_une_erreur(): void
    {
        $rapport = app(RapportSnisService::class)->rapport(2020, 1, $this->etab->id);

        $this->assertSame(0, $rapport['consultations']['total']);
        $this->assertSame(0, $rapport['hospitalisation']['admissions']);
        $this->assertSame(0, $rapport['deces']['total']);
    }

    // ═══════════════════════════════════════════════════════════
    // Les écrans et l'export
    // ═══════════════════════════════════════════════════════════

    public function test_lecran_du_rapport_repond(): void
    {
        $this->diagnostic($this->passage($this->patient('G', 300), 9), 'Paludisme simple');

        $this->get(route('snis.index', ['annee' => 2026, 'mois' => 7]))
            ->assertOk()
            ->assertSee('Rapport mensuel SNIS')
            ->assertSee('Consultations curatives')
            ->assertSee('Morbidité')
            ->assertSee('Paludisme')
            ->assertSee('registre papier');
    }

    public function test_le_rapport_simprime(): void
    {
        $this->get(route('snis.imprimer', ['annee' => 2026, 'mois' => 7]))
            ->assertOk()
            ->assertSee('RAPPORT MENSUEL')
            ->assertSee('Système National d')
            ->assertSee('Le Médecin Directeur');
    }

    public function test_lexport_est_un_tableur_lisible_par_excel(): void
    {
        $this->diagnostic($this->passage($this->patient('H', 300), 11), 'Paludisme simple');

        $reponse = $this->get(route('snis.csv', ['annee' => 2026, 'mois' => 7]))->assertOk();

        $contenu = $reponse->streamedContent();

        // BOM : sans lui, Excel francophone mange les accents.
        $this->assertStringStartsWith("\u{FEFF}", $contenu);
        // Point-virgule : sinon Excel francophone met tout dans une colonne.
        $this->assertStringContainsString('Paludisme;', $contenu);
        $this->assertStringContainsString('RAPPORT MENSUEL SNIS', $contenu);
        $this->assertStringContainsString('1. CONSULTATIONS CURATIVES', $contenu);
        $this->assertStringContainsString('8. DÉCÈS', $contenu);
    }

    public function test_le_fichier_porte_letablissement_et_le_mois(): void
    {
        $reponse = $this->get(route('snis.csv', ['annee' => 2026, 'mois' => 7]))->assertOk();

        $this->assertStringContainsString('2026-07.csv',
            $reponse->headers->get('content-disposition'));
    }

    public function test_le_mois_propose_par_defaut_est_le_mois_ecoule(): void
    {
        // On remonte le mois terminé, pas celui qui court.
        $attendu = now()->subMonthNoOverflow();

        $this->get(route('snis.index'))
            ->assertOk()
            ->assertSee(ucfirst($attendu->translatedFormat('F Y')));
    }

    public function test_le_rapport_reste_a_la_direction_et_a_ladministration(): void
    {
        $laborantin = User::factory()->create(['establishment_id' => $this->etab->id]);
        $laborantin->assignRole('laborantin');

        $this->actingAs($laborantin)->get(route('snis.index'))->assertForbidden();
        $this->actingAs($laborantin)->get(route('snis.csv'))->assertForbidden();
    }

    public function test_le_lien_est_offert_depuis_la_navigation(): void
    {
        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSee(route('snis.index'), false);
    }
}
