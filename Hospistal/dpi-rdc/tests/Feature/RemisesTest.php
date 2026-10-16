<?php

namespace Tests\Feature;

use App\Models\Assurance;
use App\Models\Avoir;
use App\Models\BonSortie;
use App\Models\Establishment;
use App\Models\ExamenLaboratoire;
use App\Models\Facture;
use App\Models\NotificationInterne;
use App\Models\Patient;
use App\Models\PatientAssurance;
use App\Models\ResultatExamen;
use App\Models\Service;
use App\Models\TypeExamen;
use App\Models\User;
use App\Models\Visit;
use App\Services\AvoirService;
use App\Services\FacturationService;
use App\Services\ParametreService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\TestCase;

/**
 * La remise : une recette à laquelle l'hôpital renonce.
 *
 * Ce qui est vérifié ici : que le caissier ne l'accorde pas mais peut la
 * demander ; que le chef de facturation l'accorde dans la limite fixée par
 * la direction, et la direction sans limite ; qu'elle ne touche que la part
 * du patient ; qu'elle laisse un crédit si le patient avait déjà payé ;
 * qu'une facture soldée par remise débloque l'examen comme un paiement ; et
 * que le registre dit qui a remis combien.
 */
class RemisesTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $chef;

    protected User $caissier;

    protected User $directeur;

    protected Establishment $etab;

    protected Patient $patient;

    protected Visit $sejour;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->admin = User::where('email', 'admin@dpi-rdc.local')->firstOrFail();
        $this->etab = Establishment::firstOrFail();

        foreach (['chef' => 'chef_facturation', 'caissier' => 'caissier', 'directeur' => 'directeur'] as $propriete => $role) {
            $this->{$propriete} = User::factory()->create(['establishment_id' => $this->etab->id]);
            $this->{$propriete}->assignRole($role);
        }

        $this->actingAs($this->admin);

        $this->patient = Patient::create([
            'establishment_id' => $this->etab->id,
            'dossier_number' => 'PAT-2026-008800',
            'nom' => 'MASIKA', 'postnom' => 'KAHINDO', 'prenom' => 'Esther',
            'sexe' => 'F',
            'date_naissance' => now()->subYears(29)->toDateString(),
            'type_prise_en_charge' => 'prive',
        ]);

        $this->sejour = Visit::create([
            'patient_id' => $this->patient->id,
            'establishment_id' => $this->etab->id,
            'user_id' => $this->admin->id,
            'type' => 'hospitalisation',
            'statut' => 'en_cours',
            'date_entree' => now()->subDays(2),
            'service_id' => Service::where('is_active', true)->first()?->id,
            'motif_consultation' => 'Anémie sévère',
        ]);
    }

    protected function factureSejour(): Facture
    {
        return app(FacturationService::class)->creerFactureHospitalisation($this->sejour);
    }

    protected function factureExamen(): Facture
    {
        $examen = ExamenLaboratoire::create([
            'numero_bon' => 'LAB-RM-'.random_int(1000, 9999),
            'patient_id' => $this->patient->id,
            'visit_id' => $this->sejour->id,
            'prescripteur_id' => $this->admin->id,
            'date_prescription' => now(),
            'statut' => 'prescrit',
            'domaine' => 'labo',
        ]);

        TypeExamen::where('domaine', 'labo')->where('prix', '>', 0)->get()
            ->filter(fn ($t) => count($t->valeurs_reference['parametres'] ?? []) <= 1)
            ->take(2)
            ->each(fn ($type) => ResultatExamen::create(['examen_id' => $examen->id, 'type_examen_id' => $type->id]));

        return app(FacturationService::class)->creerFactureExamen($examen);
    }

    protected function remettre(Facture $facture, array $donnees): TestResponse
    {
        return $this->post(route('caisse.remise', $facture), $donnees + [
            'motif_categorie' => 'cas_social',
            'motif' => 'Mère seule, enquête sociale du service social.',
        ]);
    }

    // ═══════════════════════════════════════════════════════════
    // Qui accorde
    // ═══════════════════════════════════════════════════════════

    public function test_le_chef_de_facturation_accorde_une_remise_en_pourcentage(): void
    {
        $facture = $this->factureSejour();
        $this->actingAs($this->chef);

        $this->remettre($facture, ['mode' => 'taux', 'valeur' => 10])
            ->assertRedirect(route('caisse.show', $facture))
            ->assertSessionHas('success');

        $avoir = Avoir::firstOrFail();
        $facture->refresh();

        $this->assertSame('remise', $avoir->type);
        $this->assertEquals(10, (float) $avoir->taux_remise);
        $this->assertEqualsWithDelta((float) $facture->patient_part * 0.10, (float) $avoir->montant_total, 0.01);
        $this->assertEquals(0, (float) $avoir->montant_assurance);
        $this->assertSame($this->chef->id, $avoir->emis_par);
        $this->assertEqualsWithDelta((float) $facture->patient_part * 0.90, $facture->soldeRestant(), 0.01);
        $this->assertSame('emise', $facture->statut);

        $this->get(route('caisse.show', $facture))->assertOk()->assertSee('Remise 10 %');
        $this->get(route('caisse.imprimer', $facture))->assertOk()->assertSee('Remise 10 %');
    }

    public function test_le_caissier_naccorde_pas_de_remise(): void
    {
        $facture = $this->factureSejour();
        $this->actingAs($this->caissier);

        $this->remettre($facture, ['mode' => 'taux', 'valeur' => 10])->assertForbidden();
        $this->assertSame(0, Avoir::count());

        $this->get(route('caisse.show', $facture))->assertOk()
            ->assertDontSee('Accorder une remise')
            ->assertSee('Le patient demande une remise ?');
    }

    public function test_le_caissier_transmet_la_demande_au_chef_de_facturation(): void
    {
        $facture = $this->factureSejour();
        $this->actingAs($this->caissier);

        $this->post(route('caisse.remise.demande', $facture), [
            'demande' => 'La famille ne peut réunir que 60 000 CDF.',
        ])->assertSessionHas('success');

        $notification = NotificationInterne::where('type', 'demande_remise')
            ->where('groupe_destinataire', 'chef_facturation')
            ->firstOrFail();

        $this->assertStringContainsString('60 000 CDF', $notification->message);
        $this->assertSame(route('caisse.show', $facture), $notification->lien());

        // Le chef la voit dans ses notifications ; le caissier, non.
        $this->assertSame(1, NotificationInterne::query()->pourUtilisateur($this->chef)->where('type', 'demande_remise')->count());
        $this->assertSame(0, NotificationInterne::query()->pourUtilisateur($this->caissier)->where('type', 'demande_remise')->count());
    }

    // ═══════════════════════════════════════════════════════════
    // La limite fixée par la direction
    // ═══════════════════════════════════════════════════════════

    public function test_au_dela_du_plafond_la_remise_revient_a_la_direction(): void
    {
        $facture = $this->factureSejour();

        $this->actingAs($this->chef);
        $this->remettre($facture, ['mode' => 'taux', 'valeur' => 30])
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'revient à la direction'));
        $this->assertSame(0, Avoir::count());

        $this->actingAs($this->directeur);
        $this->remettre($facture, ['mode' => 'taux', 'valeur' => 50])->assertSessionHas('success');
        $this->assertSame(1, Avoir::count());
    }

    public function test_le_plafond_se_cumule_sur_la_facture(): void
    {
        $facture = $this->factureSejour();
        $this->actingAs($this->chef);
        $remises = app(AvoirService::class);

        $remises->accorderRemise($facture, 'geste_commercial', 'Fidélité de la famille.', taux: 15);

        $this->expectException(RuntimeException::class);
        $remises->accorderRemise($facture->fresh(), 'geste_commercial', 'Une seconde fois.', taux: 10);
    }

    public function test_la_direction_regle_le_plafond(): void
    {
        $this->actingAs($this->caissier)
            ->post(route('parametres.remise'), ['plafond' => 50])
            ->assertForbidden();

        $this->actingAs($this->directeur)
            ->post(route('parametres.remise'), ['plafond' => 40])
            ->assertSessionHas('success');

        $this->assertEquals(40, (float) app(ParametreService::class)->lire(AvoirService::CLE_PLAFOND_REMISE));

        $facture = $this->factureSejour();
        $this->actingAs($this->chef);
        $this->remettre($facture, ['mode' => 'taux', 'valeur' => 30])->assertSessionHas('success');
    }

    // ═══════════════════════════════════════════════════════════
    // Ce que la remise touche
    // ═══════════════════════════════════════════════════════════

    public function test_la_remise_ne_touche_pas_la_part_de_lassureur(): void
    {
        $assurance = Assurance::firstOrCreate(['code' => 'SONAS'], ['nom' => 'SONAS', 'taux_couverture' => 80, 'est_actif' => true]);
        $this->patient->update(['type_prise_en_charge' => 'assurance']);
        PatientAssurance::create([
            'patient_id' => $this->patient->id, 'assurance_id' => $assurance->id,
            'numero_police' => 'SN-8801', 'date_debut' => now()->subYear()->toDateString(),
            'annee_courante' => (int) now()->format('Y'), 'est_actif' => true,
        ]);

        $facture = $this->factureSejour();
        $contrat = PatientAssurance::where('patient_id', $this->patient->id)->firstOrFail();
        $consomme = (float) $contrat->consomme_annuel_cdf;

        $this->actingAs($this->directeur);
        $avoir = app(AvoirService::class)->accorderRemise($facture, 'cas_social', 'Indigence constatée.', taux: 100);
        $facture->refresh();

        $this->assertEqualsWithDelta((float) $facture->patient_part, (float) $avoir->montant_total, 0.01);
        $this->assertEquals(0, (float) $avoir->montant_assurance);
        $this->assertEqualsWithDelta((float) $facture->assurance_part, $facture->partAssuranceNette(), 0.01);
        $this->assertEqualsWithDelta($consomme, (float) $contrat->fresh()->consomme_annuel_cdf, 0.01);

        // Soldée côté patient : rien à encaisser, mais pas annulée.
        $this->assertSame('payee', $facture->statut);
        $this->assertEquals(0.0, $facture->soldeRestant());
    }

    public function test_une_remise_apres_paiement_laisse_un_credit(): void
    {
        $facture = $this->factureSejour();
        app(FacturationService::class)->validerPaiement($facture->fresh(), (float) $facture->patient_part, 'CDF', 'especes');

        $this->actingAs($this->chef);
        $avoir = app(AvoirService::class)->accorderRemise($facture->fresh(), 'personnel', 'Épouse d\'un infirmier du service.', montant: 10000);

        $this->assertEqualsWithDelta(10000, (float) $avoir->credit_patient, 0.01);
        $this->assertNotNull($avoir->caution_id);
        $this->assertSame('payee', $facture->fresh()->statut);
    }

    public function test_un_montant_se_repartit_sur_les_lignes_choisies(): void
    {
        $facture = $this->factureExamen();
        $lignes = $facture->lignes()->orderBy('libelle')->get();
        $this->assertCount(2, $lignes);

        $this->actingAs($this->directeur);

        // Toutes les lignes : le montant se répartit et se retrouve au centime.
        $avoir = app(AvoirService::class)->accorderRemise($facture, 'arrondi', 'Arrondi accepté au guichet.', montant: 1234.57);
        $this->assertEqualsWithDelta(1234.57, (float) $avoir->montant_total, 0.001);
        $this->assertCount(2, $avoir->lignes);

        // Une seule ligne : seule elle est touchée.
        $avoir = app(AvoirService::class)->accorderRemise($facture->fresh(), 'geste_commercial', 'Contrôle offert.',
            taux: 50, ligneIds: [$lignes->first()->id]);
        $this->assertCount(1, $avoir->lignes);
        $this->assertSame($lignes->first()->id, $avoir->lignes->first()->ligne_facture_id);
    }

    public function test_on_ne_remet_pas_plus_que_ce_que_doit_le_patient(): void
    {
        $facture = $this->factureSejour();
        $this->actingAs($this->directeur);

        $this->expectException(RuntimeException::class);
        app(AvoirService::class)->accorderRemise($facture, 'cas_social', 'Trop.', montant: (float) $facture->patient_part + 1);
    }

    public function test_une_remise_qui_solde_lexamen_lenvoie_au_laboratoire(): void
    {
        $facture = $this->factureExamen();
        $this->assertSame(0, BonSortie::where('facture_id', $facture->id)->count());

        $this->actingAs($this->directeur);
        app(AvoirService::class)->accorderRemise($facture, 'cas_social', 'Enfant de la rue, pris en charge par l\'hôpital.', taux: 100);

        $this->assertSame('payee', $facture->fresh()->statut);
        $this->assertSame(1, BonSortie::where('facture_id', $facture->id)->where('type', 'labo')->where('statut', 'emis')->count());
    }

    // ═══════════════════════════════════════════════════════════
    // Le registre
    // ═══════════════════════════════════════════════════════════

    public function test_le_registre_dit_qui_a_remis_combien(): void
    {
        $facture = $this->factureSejour();
        $this->actingAs($this->chef);
        $this->remettre($facture, ['mode' => 'taux', 'valeur' => 10]);

        $this->get(route('avoirs.index'))
            ->assertOk()
            ->assertSee('Remises accordées ce mois')
            ->assertSee($this->chef->nom_complet)
            ->assertSee('Cas social, indigence constatée');

        $this->assertTrue($this->chef->can('facture.remise'));
        $this->assertTrue($this->directeur->can('facture.remise'));
        $this->assertFalse($this->caissier->can('facture.remise'));
    }
}
