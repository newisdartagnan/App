<?php

namespace Tests\Feature;

use App\Models\Assurance;
use App\Models\AssuranceCouverture;
use App\Models\Caution;
use App\Models\Establishment;
use App\Models\Forfait;
use App\Models\Patient;
use App\Models\TauxChange;
use App\Models\User;
use App\Models\Visit;
use App\Services\DeviseService;
use App\Services\ParametreService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Paramétrage de l'établissement : révision des taux de change, comptes du
 * personnel et profils, contrats des sociétés conventionnées.
 *
 * Le point sensible est la révision d'un taux : elle ne doit jamais réécrire
 * l'histoire. Un acompte versé à 2 300 CDF le dollar vaut toujours autant
 * après une hausse à 2 800.
 */
class ParametrageTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Establishment $etab;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->admin = User::where('email', 'admin@dpi-rdc.local')->firstOrFail();
        $this->etab = Establishment::firstOrFail();
        $this->actingAs($this->admin);
    }

    protected function patient(): Patient
    {
        return Patient::create([
            'establishment_id' => $this->etab->id,
            'dossier_number' => 'PAT-2026-007700',
            'nom' => 'LUMBALA', 'postnom' => 'TSHIBOLA', 'prenom' => 'Espérance',
            'sexe' => 'F',
            'date_naissance' => now()->subYears(31)->toDateString(),
            'type_prise_en_charge' => 'prive',
        ]);
    }

    // ═══════════════════════════════════════════════════════════
    // Taux de change
    // ═══════════════════════════════════════════════════════════

    public function test_lecran_de_parametrage_affiche_les_taux_en_vigueur(): void
    {
        $this->get(route('parametres.index'))
            ->assertOk()
            ->assertSee('Paramétrage de l')
            ->assertSee('Réviser un taux')
            ->assertSee('Franc congolais');
    }

    public function test_une_hausse_du_dollar_se_saisit_depuis_linterface(): void
    {
        $this->post(route('parametres.taux'), [
            'devise' => 'USD',
            'taux_cdf' => 2800,
            'motif' => 'Hausse au marché parallèle',
        ])->assertRedirect()->assertSessionHas('success');

        $this->assertSame(2800.0, app(DeviseService::class)->taux('USD'));

        $revision = TauxChange::where('devise', 'USD')->orderByDesc('applique_a')->first();
        $this->assertSame(2800.0, (float) $revision->taux_cdf);
        $this->assertSame(2300.0, (float) $revision->taux_precedent);
        $this->assertSame('hausse', $revision->sens());
        $this->assertSame('Hausse au marché parallèle', $revision->motif);
        $this->assertSame($this->admin->id, $revision->user_id);
    }

    public function test_une_baisse_est_reconnue_comme_telle(): void
    {
        app(ParametreService::class)->reviserTaux('USD', 2100.0, 'Détente du taux');

        $revision = TauxChange::where('devise', 'USD')->orderByDesc('applique_a')->first();
        $this->assertSame('baisse', $revision->sens());
        $this->assertLessThan(0, $revision->variation());
    }

    public function test_le_franc_congolais_ne_se_revise_pas(): void
    {
        $this->post(route('parametres.taux'), ['devise' => 'CDF', 'taux_cdf' => 2])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame(1.0, app(DeviseService::class)->taux('CDF'));
        $this->assertSame(0, TauxChange::where('devise', 'CDF')->count());
    }

    public function test_resaisir_le_meme_taux_ne_cree_pas_de_revision(): void
    {
        $this->post(route('parametres.taux'), ['devise' => 'USD', 'taux_cdf' => 2300])
            ->assertRedirect()
            ->assertSessionHas('info');

        $this->assertSame(0, TauxChange::where('devise', 'USD')->count());
    }

    /**
     * Le cœur du sujet : une révision ne réécrit pas les opérations passées.
     */
    public function test_une_revision_ne_touche_pas_aux_operations_deja_enregistrees(): void
    {
        $patient = $this->patient();

        $visite = Visit::create([
            'patient_id' => $patient->id,
            'establishment_id' => $this->etab->id,
            'user_id' => $this->admin->id,
            'type' => 'hospitalisation',
            'statut' => 'en_cours',
            'date_entree' => now()->subDay(),
            'motif_consultation' => 'Surveillance',
        ]);

        $acompte = Caution::create([
            'visit_id' => $visite->id,
            'patient_id' => $patient->id,
            'caissier_id' => $this->admin->id,
            'type' => 'hospitalisation',
            'montant' => 100,
            'devise' => 'USD',
            'taux_change' => app(DeviseService::class)->taux('USD'),
            'montant_cdf' => 100 * app(DeviseService::class)->taux('USD'),
            'mode_paiement' => 'especes',
            'statut' => 'versee',
        ]);

        $this->assertSame(230000.0, (float) $acompte->montant_cdf);

        app(ParametreService::class)->reviserTaux('USD', 2800.0, 'Hausse');

        $acompte->refresh();
        $this->assertSame(2300.0, (float) $acompte->taux_change);
        $this->assertSame(230000.0, (float) $acompte->montant_cdf);

        // En revanche, un nouvel acompte prend le taux révisé.
        $this->assertSame(2800.0, app(DeviseService::class)->taux('USD'));
        $this->assertSame(280000.0, app(DeviseService::class)->versCdf(100, 'USD'));
    }

    public function test_le_parametrage_est_ferme_aux_agents_sans_mandat(): void
    {
        $caissier = User::create([
            'establishment_id' => $this->etab->id,
            'nom' => 'KABEYA', 'prenom' => 'Alice',
            'matricule' => 'CAI-900',
            'password' => bcrypt('motdepasse123'),
            'is_active' => true,
        ]);
        $caissier->assignRole('caissier');

        $this->actingAs($caissier)->get(route('parametres.index'))->assertForbidden();
        $this->actingAs($caissier)
            ->post(route('parametres.taux'), ['devise' => 'USD', 'taux_cdf' => 9999])
            ->assertForbidden();

        $this->assertSame(2300.0, app(DeviseService::class)->taux('USD'));
    }

    // ═══════════════════════════════════════════════════════════
    // Comptes du personnel
    // ═══════════════════════════════════════════════════════════

    public function test_la_direction_cree_un_compte_avec_son_profil(): void
    {
        $this->post(route('utilisateurs.store'), [
            'nom' => 'ILUNGA',
            'prenom' => 'Joseph',
            'role' => 'medecin',
            'matricule' => 'MED-014',
            'telephone' => '+243810000000',
            'specialite' => 'Cardiologie',
            'password' => 'provisoire2026',
        ])->assertRedirect()->assertSessionHas('success');

        $cree = User::where('matricule', 'MED-014')->firstOrFail();
        $this->assertTrue($cree->hasRole('medecin'));
        $this->assertSame('Cardiologie', $cree->specialite);
        $this->assertSame($this->etab->id, $cree->establishment_id);
        $this->assertTrue($cree->is_active);

        // Le compte créé se connecte réellement avec son matricule.
        auth()->logout();
        $this->post(route('login'), ['login' => 'MED-014', 'password' => 'provisoire2026'])
            ->assertRedirect(route('dashboard'));
    }

    public function test_un_compte_sans_identifiant_est_refuse(): void
    {
        $this->post(route('utilisateurs.store'), [
            'nom' => 'SANS', 'prenom' => 'Identifiant',
            'role' => 'infirmier',
            'password' => 'provisoire2026',
        ])->assertSessionHasErrors();

        $this->assertSame(0, User::where('nom', 'SANS')->count());
    }

    public function test_le_profil_dun_agent_se_change_depuis_la_liste(): void
    {
        $agent = User::create([
            'establishment_id' => $this->etab->id,
            'nom' => 'MUKENDI', 'prenom' => 'Paul',
            'matricule' => 'AGT-201',
            'password' => bcrypt('motdepasse123'),
            'is_active' => true,
        ]);
        $agent->assignRole('agent_admin');

        $this->post(route('utilisateurs.update', $agent), [
            'role' => 'caissier',
            'specialite' => '',
            'telephone' => '+243999000111',
        ])->assertRedirect()->assertSessionHas('success');

        $agent->refresh();
        $this->assertTrue($agent->hasRole('caissier'));
        $this->assertFalse($agent->hasRole('agent_admin'));
        $this->assertSame('+243999000111', $agent->telephone);
    }

    public function test_le_dernier_administrateur_ne_peut_pas_etre_destitue(): void
    {
        $this->post(route('utilisateurs.update', $this->admin), ['role' => 'caissier'])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->admin->refresh();
        $this->assertTrue($this->admin->hasRole('super_admin'));
    }

    public function test_un_compte_se_desactive_et_se_reactive(): void
    {
        $agent = User::create([
            'establishment_id' => $this->etab->id,
            'nom' => 'NGOY', 'prenom' => 'Sylvie',
            'matricule' => 'AGT-330',
            'password' => bcrypt('motdepasse123'),
            'is_active' => true,
        ]);
        $agent->assignRole('infirmier');

        $this->post(route('utilisateurs.basculer', $agent))->assertRedirect();
        $this->assertFalse($agent->fresh()->is_active);

        $this->post(route('utilisateurs.basculer', $agent))->assertRedirect();
        $this->assertTrue($agent->fresh()->is_active);
    }

    public function test_un_mot_de_passe_se_reinitialise(): void
    {
        $agent = User::create([
            'establishment_id' => $this->etab->id,
            'nom' => 'BOFANDE', 'prenom' => 'Roger',
            'matricule' => 'AGT-440',
            'password' => bcrypt('ancien-mot-de-passe'),
            'is_active' => true,
        ]);
        $agent->assignRole('laborantin');

        $this->post(route('utilisateurs.mot-de-passe', $agent), ['password' => 'nouveau-secret-2026'])
            ->assertRedirect()
            ->assertSessionHas('success');

        auth()->logout();
        $this->post(route('login'), ['login' => 'AGT-440', 'password' => 'nouveau-secret-2026'])
            ->assertRedirect(route('dashboard'));
    }

    // ═══════════════════════════════════════════════════════════
    // Conventions : contrat, modalités, règles de couverture
    // ═══════════════════════════════════════════════════════════

    protected function convention(): Assurance
    {
        $this->post(route('assurances.store'), [
            'nom' => 'Mutuelle des Enseignants',
            'code' => 'mut-ens',
            'taux_couverture' => 80,
            'ticket_moderateur' => 10,
            'delai_reglement_jours' => 45,
            'mode_reglement' => 'virement',
            'periodicite_facturation' => 'mensuelle',
            'plafond_annuel_cdf' => 5000000,
        ])->assertRedirect();

        return Assurance::where('code', 'MUT-ENS')->firstOrFail();
    }

    public function test_une_convention_se_cree_avec_ses_modalites_de_paiement(): void
    {
        $convention = $this->convention();

        $this->assertSame('MUT-ENS', $convention->code);
        $this->assertSame(45, $convention->delai_reglement_jours);
        $this->assertSame('virement', $convention->mode_reglement);
        $this->assertSame('mensuelle', $convention->periodicite_facturation);
        $this->assertTrue($convention->est_actif);
        $this->assertStringContainsString('45 jours', $convention->modalites());

        $this->assertSame(
            now()->addDays(45)->toDateString(),
            $convention->echeancePour(now())->toDateString()
        );
    }

    public function test_un_code_de_convention_ne_se_duplique_pas(): void
    {
        $this->convention();

        $this->post(route('assurances.store'), [
            'nom' => 'Autre mutuelle',
            'code' => 'MUT-ENS',
            'taux_couverture' => 50,
            'delai_reglement_jours' => 30,
            'mode_reglement' => 'especes',
            'periodicite_facturation' => 'mensuelle',
        ])->assertSessionHasErrors('code');

        $this->assertSame(1, Assurance::where('code', 'MUT-ENS')->count());
    }

    public function test_le_ticket_moderateur_reduit_la_part_de_lassureur(): void
    {
        $convention = $this->convention();

        // 80 % au contrat, moins 10 points de ticket modérateur.
        $this->assertSame(70.0, $convention->tauxPourActe('consultation'));
    }

    public function test_une_regle_de_couverture_se_saisit_et_sapplique(): void
    {
        $convention = $this->convention();

        // Taux négocié sur les examens de laboratoire.
        $this->post(route('assurances.couvertures', $convention), [
            'type' => 'examen_labo',
            'couvert' => 1,
            'taux_specifique' => 100,
            'reference_libelle' => 'Avenant nº 3',
        ])->assertRedirect()->assertSessionHas('success');

        // Exclusion des médicaments.
        $this->post(route('assurances.couvertures', $convention), [
            'type' => 'medicament',
            'couvert' => 0,
        ])->assertRedirect()->assertSessionHas('success');

        $convention->refresh()->load('couvertures');

        $this->assertSame(90.0, $convention->tauxPourActe('examen_labo'));
        $this->assertSame(0.0, $convention->tauxPourActe('medicament'));
        $this->assertSame(70.0, $convention->tauxPourActe('consultation'));
    }

    public function test_une_regle_se_remplace_au_lieu_de_sempiler(): void
    {
        $convention = $this->convention();

        foreach ([100, 60] as $taux) {
            $this->post(route('assurances.couvertures', $convention), [
                'type' => 'imagerie', 'couvert' => 1, 'taux_specifique' => $taux,
            ])->assertRedirect();
        }

        $this->assertSame(1, AssuranceCouverture::where('assurance_id', $convention->id)
            ->where('type', 'imagerie')->count());
        $this->assertSame(50.0, $convention->refresh()->tauxPourActe('imagerie'));
    }

    public function test_un_acte_exclu_ne_porte_pas_de_taux(): void
    {
        $convention = $this->convention();

        $this->post(route('assurances.couvertures', $convention), [
            'type' => 'dialyse', 'couvert' => 0, 'taux_specifique' => 50,
        ])->assertRedirect()->assertSessionHas('error');

        $this->assertSame(0, AssuranceCouverture::where('assurance_id', $convention->id)->count());
    }

    public function test_une_regle_retiree_rend_lacte_au_taux_du_contrat(): void
    {
        $convention = $this->convention();

        $this->post(route('assurances.couvertures', $convention), [
            'type' => 'hospitalisation', 'couvert' => 0,
        ])->assertRedirect();

        $regle = AssuranceCouverture::where('assurance_id', $convention->id)->firstOrFail();
        $this->assertSame(0.0, $convention->refresh()->tauxPourActe('hospitalisation'));

        $this->delete(route('assurances.couvertures.destroy', $regle))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(70.0, $convention->refresh()->load('couvertures')->tauxPourActe('hospitalisation'));
    }

    public function test_la_fiche_dune_convention_affiche_contrat_regles_et_forfaits(): void
    {
        $convention = $this->convention();

        Forfait::create([
            'establishment_id' => $this->etab->id,
            'assurance_id' => $convention->id,
            'code' => 'FORF-ACC-MUT',
            'libelle' => 'Accouchement tout compris',
            'portee' => 'global',
            'montant' => 250000,
            'devise' => 'CDF',
            'is_active' => true,
        ]);

        $this->post(route('assurances.couvertures', $convention), [
            'type' => 'medicament', 'couvert' => 0,
        ])->assertRedirect();

        $this->get(route('assurances.show', $convention))
            ->assertOk()
            ->assertSee('Mutuelle des Enseignants')
            ->assertSee('Règles de couverture')
            ->assertSee('Médicaments')
            ->assertSee('Accouchement tout compris')
            ->assertSee('Contrat et modalités de paiement');
    }

    public function test_une_convention_suspendue_ne_couvre_plus(): void
    {
        $convention = $this->convention();

        $this->post(route('assurances.basculer', $convention))->assertRedirect();

        $this->assertFalse($convention->fresh()->est_actif);

        $this->get(route('assurances.index'))->assertOk()->assertSee('Suspendue');
    }
}
