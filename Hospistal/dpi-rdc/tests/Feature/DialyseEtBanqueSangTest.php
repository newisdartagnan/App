<?php

namespace Tests\Feature;

use App\Models\ActeClinique;
use App\Models\DonneurSang;
use App\Models\Establishment;
use App\Models\GenerateurDialyse;
use App\Models\Patient;
use App\Models\PocheSang;
use App\Models\SeanceDialyse;
use App\Models\Transfusion;
use App\Models\User;
use App\Models\Visit;
use App\Services\BanqueSangService;
use App\Services\DialyseService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Dialyse et banque de sang.
 *
 * Deux ressources rares, deux règles dures : un générateur ne reçoit qu'un
 * patient à la fois, et une poche de sang ne part que dépistée, négative et
 * compatible.
 */
class DialyseEtBanqueSangTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Establishment $etab;

    protected Patient $patient;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->admin = User::where('email', 'admin@dpi-rdc.local')->firstOrFail();
        $this->etab = Establishment::firstOrFail();
        $this->actingAs($this->admin);

        $this->patient = Patient::create([
            'establishment_id' => $this->etab->id,
            'dossier_number' => 'PAT-2026-010200',
            'nom' => 'MUKENDI', 'prenom' => 'Pascal', 'sexe' => 'M',
            'date_naissance' => now()->subYears(55)->toDateString(),
            'type_prise_en_charge' => 'prive',
            'groupe_sanguin' => 'A+',
        ]);
    }

    protected function generateur(string $code = 'GEN-1'): GenerateurDialyse
    {
        return GenerateurDialyse::where('code', $code)->firstOrFail();
    }

    // ═══════════════════════════════════════════════════════════
    // Dialyse : le calendrier
    // ═══════════════════════════════════════════════════════════

    public function test_lunite_ouvre_avec_ses_generateurs(): void
    {
        $this->assertSame(5, GenerateurDialyse::where('est_actif', true)->count());
        $this->assertTrue($this->generateur('GEN-HBS')->reserve_hbs);
    }

    public function test_une_seance_se_programme_sur_un_generateur(): void
    {
        $this->post(route('dialyse.planifier'), [
            'patient_id' => $this->patient->id,
            'generateur_id' => $this->generateur()->id,
            'date_seance' => '2026-08-24T08:00',
            'duree_minutes' => 240,
            'type' => 'hemodialyse',
            'abord' => 'fistule',
            'poids_sec_kg' => 68,
        ])->assertRedirect()->assertSessionHas('success');

        $seance = SeanceDialyse::firstOrFail();

        $this->assertSame('planifiee', $seance->statut);
        $this->assertSame('2026-08-24 12:00', $seance->finPrevue()->format('Y-m-d H:i'));
        $this->assertSame('Fistule artério-veineuse', $seance->libelleAbord());
    }

    public function test_deux_patients_ne_partagent_pas_un_generateur(): void
    {
        $autre = Patient::create([
            'establishment_id' => $this->etab->id,
            'dossier_number' => 'PAT-2026-010201',
            'nom' => 'NGOY', 'prenom' => 'Sylvie', 'sexe' => 'F',
            'date_naissance' => now()->subYears(48)->toDateString(),
            'type_prise_en_charge' => 'prive',
        ]);

        $creneau = fn (Patient $p, string $heure) => [
            'patient_id' => $p->id,
            'generateur_id' => $this->generateur()->id,
            'date_seance' => $heure,
            'duree_minutes' => 240,
            'type' => 'hemodialyse',
        ];

        $this->post(route('dialyse.planifier'), $creneau($this->patient, '2026-08-24T08:00'))
            ->assertSessionHas('success');

        // 10 h tombe en plein milieu de la séance de 8 h à 12 h.
        $this->post(route('dialyse.planifier'), $creneau($autre, '2026-08-24T10:00'))
            ->assertSessionHas('error');

        $this->assertSame(1, SeanceDialyse::count());

        // Le créneau suivant passe.
        $this->post(route('dialyse.planifier'), $creneau($autre, '2026-08-24T12:00'))
            ->assertSessionHas('success');

        $this->assertSame(2, SeanceDialyse::count());
    }

    public function test_un_programme_recurrent_remplit_le_calendrier(): void
    {
        // Lundi, mercredi, vendredi pendant quatre semaines : douze séances.
        $this->post(route('dialyse.recurrence'), [
            'patient_id' => $this->patient->id,
            'generateur_id' => $this->generateur()->id,
            'jours' => [1, 3, 5],
            'heure' => '08:00',
            'date_debut' => '2026-08-31',
            'semaines' => 4,
            'duree_minutes' => 240,
            'type' => 'hemodialyse',
            'abord' => 'fistule',
        ])->assertRedirect()->assertSessionHas('success');

        $seances = SeanceDialyse::orderBy('date_seance')->get();

        $this->assertCount(12, $seances);
        $this->assertSame('2026-08-31 08:00', $seances->first()->date_seance->format('Y-m-d H:i'));
        // Trois séances par semaine, quatre semaines de suite.
        $this->assertSame([1, 3, 5], $seances->map(fn ($s) => $s->date_seance->dayOfWeekIso)->unique()->sort()->values()->all());
    }

    public function test_la_recurrence_ne_remplit_pas_le_passe(): void
    {
        $resultat = app(DialyseService::class)->programmerRecurrence(
            $this->patient,
            [1, 2, 3, 4, 5, 6, 7],
            '08:00',
            // On démarre un jeudi : lundi, mardi et mercredi de cette semaine
            // sont derrière nous, ils ne doivent pas être programmés. Restent
            // jeudi, vendredi, samedi et dimanche.
            Carbon::parse('2026-09-03'),
            1,
            ['generateur_id' => $this->generateur()->id, 'duree_minutes' => 240, 'type' => 'hemodialyse']
        );

        $this->assertSame(4, $resultat['creees']);
        $this->assertSame([], $resultat['conflits']);
    }

    // ═══════════════════════════════════════════════════════════
    // Dialyse : la séance
    // ═══════════════════════════════════════════════════════════

    protected function seancePlanifiee(): SeanceDialyse
    {
        $visite = Visit::create([
            'patient_id' => $this->patient->id,
            'establishment_id' => $this->etab->id,
            'user_id' => $this->admin->id,
            'type' => 'consultation_externe',
            'statut' => 'en_cours',
            'date_entree' => now(),
            'motif_consultation' => 'Séance de dialyse',
        ]);

        $this->post(route('dialyse.planifier'), [
            'patient_id' => $this->patient->id,
            'generateur_id' => $this->generateur()->id,
            'date_seance' => now()->format('Y-m-d\TH:i'),
            'duree_minutes' => 240,
            'type' => 'hemodialyse',
            'abord' => 'fistule',
            'poids_sec_kg' => 68,
        ]);

        return SeanceDialyse::firstOrFail();
    }

    public function test_lultrafiltration_se_deduit_du_poids_perdu(): void
    {
        $seance = $this->seancePlanifiee();

        $this->post(route('dialyse.realiser', $seance), [
            'poids_avant_kg' => 71.5,
            'poids_apres_kg' => 68.4,
            'ta_avant_systolique' => 160, 'ta_avant_diastolique' => 90,
            'ta_apres_systolique' => 85, 'ta_apres_diastolique' => 55,
            'incidents' => 'Crampes en fin de séance',
        ])->assertRedirect();

        $seance->refresh();

        $this->assertSame('realisee', $seance->statut);
        // 3,1 kg perdus : 3 100 ml d'ultrafiltrat.
        $this->assertSame(3100, $seance->ultrafiltration_ml);
        $this->assertSame(0.4, $seance->ecartAuPoidsSecKg());

        $alertes = $seance->alertes();
        $this->assertStringContainsString('Hypotension de fin de séance', $alertes[0]);
        $this->assertStringContainsString('Crampes', $alertes[1]);
    }

    public function test_la_seance_realisee_devient_un_acte_facturable(): void
    {
        $seance = $this->seancePlanifiee();

        $this->post(route('dialyse.realiser', $seance), [
            'poids_avant_kg' => 70, 'poids_apres_kg' => 68,
        ]);

        $acte = $seance->fresh()->acteClinique;

        $this->assertNotNull($acte);
        $this->assertSame('dialyse', $acte->domaine);
        $this->assertSame('realise', $acte->statut);
        $this->assertSame(120000.0, (float) $acte->prix);
        $this->assertStringContainsString('Hémodialyse', $acte->libelle);
    }

    public function test_une_seance_ne_se_facture_pas_deux_fois(): void
    {
        $seance = $this->seancePlanifiee();

        $this->post(route('dialyse.realiser', $seance), ['poids_avant_kg' => 70, 'poids_apres_kg' => 68]);
        $this->post(route('dialyse.realiser', $seance), ['poids_avant_kg' => 71, 'poids_apres_kg' => 68])
            ->assertSessionHas('info');

        $this->assertSame(1, ActeClinique::where('domaine', 'dialyse')->count());
        // La première mesure fait foi.
        $this->assertSame(2000, $seance->fresh()->ultrafiltration_ml);
    }

    public function test_un_poids_de_sortie_superieur_au_poids_dentree_est_refuse(): void
    {
        $seance = $this->seancePlanifiee();

        $this->post(route('dialyse.realiser', $seance), [
            'poids_avant_kg' => 68, 'poids_apres_kg' => 72,
        ])->assertSessionHas('error');

        $this->assertSame('planifiee', $seance->fresh()->statut);
    }

    public function test_une_absence_se_note_sans_facturer(): void
    {
        $seance = $this->seancePlanifiee();

        $this->post(route('dialyse.absence', $seance))->assertSessionHas('success');

        $this->assertSame('absente', $seance->fresh()->statut);
        $this->assertSame(0, ActeClinique::where('domaine', 'dialyse')->count());
    }

    // ═══════════════════════════════════════════════════════════
    // Banque de sang : compatibilité
    // ═══════════════════════════════════════════════════════════

    public function test_la_compatibilite_globulaire_respecte_le_systeme_abo_rhesus(): void
    {
        $this->assertSame(['O-'], PocheSang::groupesCompatiblesPour('O-', 'concentre_globulaire'));
        $this->assertSame(['O-', 'O+', 'A-', 'A+'], PocheSang::groupesCompatiblesPour('A+', 'concentre_globulaire'));
        // Le AB+ est receveur universel.
        $this->assertSame(PocheSang::GROUPES, PocheSang::groupesCompatiblesPour('AB+', 'concentre_globulaire'));
        // Sans groupe connu, seul le donneur universel est envisageable.
        $this->assertSame(['O-'], PocheSang::groupesCompatiblesPour(null));
    }

    public function test_le_plasma_suit_la_regle_inverse(): void
    {
        // En plasma, le AB donne à tous et le O ne reçoit que du O… inversé :
        // un receveur O accepte tous les plasmas, un AB seulement du AB.
        $this->assertSame(PocheSang::GROUPES, PocheSang::groupesCompatiblesPour('O+', 'plasma_frais'));
        $this->assertSame(['AB-', 'AB+'], PocheSang::groupesCompatiblesPour('AB+', 'plasma_frais'));
    }

    // ═══════════════════════════════════════════════════════════
    // Banque de sang : le circuit
    // ═══════════════════════════════════════════════════════════

    protected function donneur(string $groupe = 'O-', string $sexe = 'M'): DonneurSang
    {
        $banque = app(BanqueSangService::class);

        return DonneurSang::create([
            'establishment_id' => $this->etab->id,
            'code' => $banque->genererCodeDonneur($this->etab->id),
            'nom' => 'ILUNGA', 'prenom' => 'Joseph',
            'sexe' => $sexe,
            'groupe_sanguin' => $groupe,
            'telephone' => '+243810000001',
            'type_donneur' => 'benevole',
        ]);
    }

    public function test_une_poche_nait_en_quarantaine_avec_sa_peremption(): void
    {
        $poche = app(BanqueSangService::class)->enregistrerDon($this->donneur(), [
            'type_produit' => 'concentre_globulaire',
        ]);

        $this->assertSame('quarantaine', $poche->statut);
        $this->assertSame('O-', $poche->groupe_sanguin);
        // Un concentré globulaire se garde quarante-deux jours.
        $this->assertSame(
            now()->addDays(42)->toDateString(),
            $poche->date_peremption->toDateString()
        );
        $this->assertFalse($poche->estDelivrable());
        $this->assertStringContainsString('Dépistage incomplet', $poche->motifIndisponibilite());
    }

    public function test_un_depistage_negatif_met_la_poche_en_rayon(): void
    {
        $banque = app(BanqueSangService::class);
        $poche = $banque->enregistrerDon($this->donneur(), []);

        $poche = $banque->enregistrerDepistage($poche, []);

        $this->assertSame('disponible', $poche->statut);
        $this->assertTrue($poche->estDelivrable());
        $this->assertNull($poche->motifIndisponibilite());
    }

    public function test_un_depistage_positif_detruit_la_poche_et_ecarte_le_donneur(): void
    {
        $banque = app(BanqueSangService::class);
        $donneur = $this->donneur();
        $poche = $banque->enregistrerDon($donneur, []);

        $poche = $banque->enregistrerDepistage($poche, ['depistage_vih' => true]);

        $this->assertSame('detruite', $poche->statut);
        $this->assertFalse($poche->estDelivrable());

        $donneur->refresh();
        $this->assertFalse($donneur->est_eligible);
        $this->assertStringContainsString('VIH', $donneur->motif_exclusion);
        $this->assertStringContainsString('Donneur écarté', $donneur->motifIndisponibilite());
    }

    public function test_le_delai_entre_deux_dons_depend_du_sexe(): void
    {
        $banque = app(BanqueSangService::class);

        $homme = $this->donneur('O+', 'M');
        $banque->enregistrerDon($homme, []);
        $this->assertSame(
            now()->addDays(56)->toDateString(),
            $homme->fresh()->prochainDonPossible()->toDateString()
        );

        $femme = DonneurSang::create([
            'establishment_id' => $this->etab->id,
            'code' => $banque->genererCodeDonneur($this->etab->id),
            'nom' => 'KASONGO', 'prenom' => 'Marie', 'sexe' => 'F',
            'groupe_sanguin' => 'B+', 'type_donneur' => 'familial',
        ]);
        $banque->enregistrerDon($femme, []);

        // Douze semaines pour une femme : ses réserves en fer se
        // reconstituent plus lentement.
        $this->assertSame(
            now()->addDays(84)->toDateString(),
            $femme->fresh()->prochainDonPossible()->toDateString()
        );
        $this->assertFalse($femme->fresh()->peutDonnerMaintenant());
    }

    protected function demandeAvecPoche(string $groupePoche = 'O-'): array
    {
        $banque = app(BanqueSangService::class);
        $poche = $banque->enregistrerDepistage(
            $banque->enregistrerDon($this->donneur($groupePoche), ['type_produit' => 'concentre_globulaire']),
            []
        );

        $demande = $banque->creerDemande($this->patient, [
            'type_produit' => 'concentre_globulaire',
            'nombre_poches' => 1,
            'indication' => 'Anémie sévère',
            'hemoglobine' => 5.4,
        ]);

        return [$demande, $poche];
    }

    public function test_une_poche_compatible_est_delivree_et_solde_la_demande(): void
    {
        [$demande, $poche] = $this->demandeAvecPoche('O-');

        $this->assertSame('A+', $demande->groupeReceveur());

        $this->post(route('banque-sang.delivrer', $demande), [
            'poche_id' => $poche->id,
            'controle_ultime' => '1',
            'hemoglobine_avant' => 5.4,
        ])->assertRedirect()->assertSessionHas('success');

        $this->assertSame('transfusee', $poche->fresh()->statut);
        $this->assertSame('servie', $demande->fresh()->statut);

        $transfusion = Transfusion::firstOrFail();
        $this->assertSame('O-', $transfusion->groupe_donneur);
        $this->assertSame('A+', $transfusion->groupe_receveur);
        $this->assertTrue($transfusion->controle_ultime);
    }

    public function test_une_poche_incompatible_est_refusee(): void
    {
        [$demande, $poche] = $this->demandeAvecPoche('B+');

        $this->post(route('banque-sang.delivrer', $demande), [
            'poche_id' => $poche->id,
            'controle_ultime' => '1',
        ])->assertRedirect()->assertSessionHas('error');

        $this->assertSame('disponible', $poche->fresh()->statut);
        $this->assertSame(0, Transfusion::count());
    }

    public function test_sans_controle_ultime_la_poche_ne_part_pas(): void
    {
        [$demande, $poche] = $this->demandeAvecPoche();

        $this->post(route('banque-sang.delivrer', $demande), ['poche_id' => $poche->id])
            ->assertSessionHas('error');

        $this->assertSame('disponible', $poche->fresh()->statut);
        $this->assertSame(0, Transfusion::count());
    }

    public function test_une_poche_perimee_ne_part_pas(): void
    {
        [$demande, $poche] = $this->demandeAvecPoche();
        $poche->update(['date_peremption' => now()->subDay()->toDateString()]);

        $this->post(route('banque-sang.delivrer', $demande), [
            'poche_id' => $poche->id, 'controle_ultime' => '1',
        ])->assertSessionHas('error');

        $this->assertSame(0, Transfusion::count());

        // Le ménage quotidien la sort du stock.
        app(BanqueSangService::class)->retirerPochesPerimees();
        $this->assertSame('perimee', $poche->fresh()->statut);
    }

    public function test_une_poche_en_quarantaine_ne_part_pas(): void
    {
        $banque = app(BanqueSangService::class);
        $poche = $banque->enregistrerDon($this->donneur(), []);
        $demande = $banque->creerDemande($this->patient, ['nombre_poches' => 1]);

        $this->post(route('banque-sang.delivrer', $demande), [
            'poche_id' => $poche->id, 'controle_ultime' => '1',
        ])->assertSessionHas('error');

        $this->assertSame(0, Transfusion::count());
    }

    public function test_la_banque_propose_les_donneurs_a_appeler(): void
    {
        $banque = app(BanqueSangService::class);
        $this->donneur('O-');                 // compatible A+
        $ecarte = $this->donneur('O+');
        $ecarte->update(['est_eligible' => false, 'motif_exclusion' => 'Dépistage positif']);
        $this->donneur('B+');                 // incompatible A+

        $joignables = $banque->donneursAAppeler('A+', $this->etab->id);

        $this->assertCount(1, $joignables);
        $this->assertSame('O-', $joignables->first()->groupe_sanguin);
    }

    public function test_lecran_de_la_demande_annonce_les_groupes_acceptes(): void
    {
        [$demande] = $this->demandeAvecPoche();

        $this->get(route('banque-sang.demande', $demande))
            ->assertOk()
            ->assertSee('O-, O+, A-, A+')
            ->assertSee('Donneurs à appeler')
            ->assertSee('Contrôle ultime au lit du malade');
    }

    public function test_la_banque_est_fermee_aux_agents_non_soignants(): void
    {
        $caissier = User::create([
            'establishment_id' => $this->etab->id,
            'nom' => 'NSIMBA', 'prenom' => 'Julie',
            'matricule' => 'CAI-880',
            'password' => bcrypt('motdepasse123'),
            'is_active' => true,
        ]);
        $caissier->assignRole('caissier');

        $this->actingAs($caissier)->get(route('banque-sang.index'))->assertForbidden();
        $this->actingAs($caissier)->get(route('banque-sang.donneurs'))->assertForbidden();
    }

    public function test_letat_du_stock_se_lit_groupe_par_groupe(): void
    {
        $banque = app(BanqueSangService::class);
        $banque->enregistrerDepistage($banque->enregistrerDon($this->donneur('O-'), []), []);
        $banque->enregistrerDon($this->donneur('A+'), []);   // reste en quarantaine

        $stock = $banque->etatDuStock($this->etab->id);

        $this->assertSame(2, $stock['total']);
        $this->assertSame(1, $stock['delivrables']);
        $this->assertSame(1, $stock['quarantaine']);
        $this->assertSame(1, $stock['par_groupe']['O-']);
        $this->assertSame(0, $stock['par_groupe']['A+']);
    }
}
