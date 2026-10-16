<?php

namespace Tests\Feature;

use App\Jobs\WriteAuditLog;
use App\Models\ActeClinique;
use App\Models\Assurance;
use App\Models\AuditLog;
use App\Models\Avoir;
use App\Models\BonSortie;
use App\Models\Caution;
use App\Models\Establishment;
use App\Models\ExamenLaboratoire;
use App\Models\Facture;
use App\Models\Forfait;
use App\Models\LigneFacture;
use App\Models\Paiement;
use App\Models\Patient;
use App\Models\PatientAssurance;
use App\Models\ResultatExamen;
use App\Models\Service;
use App\Models\TypeExamen;
use App\Models\User;
use App\Models\Visit;
use App\Services\AvoirService;
use App\Services\ConventionService;
use App\Services\FacturationService;
use App\Services\ForfaitService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use LogicException;
use RuntimeException;
use Tests\TestCase;

/**
 * Une facture émise ne se réécrit pas : elle se corrige par un avoir.
 *
 * Ce qui est vérifié ici : que la facture, ses lignes et ses encaissements
 * ne bougent plus ; que l'avoir reprend ce qu'il faut, à qui il faut — le
 * patient, l'assureur, son plafond, le bordereau déjà envoyé ; que le
 * trop-perçu devient un crédit au nom du patient ; que les prestations
 * libérées se refacturent juste ; que seul le chef de facturation et la
 * direction établissent un avoir ; et que le journal garde la trace de
 * chaque geste avec son auteur.
 */
class AvoirsEtJournalTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $chef;

    protected User $caissier;

    protected Establishment $etab;

    protected Patient $patient;

    protected Visit $sejour;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->admin = User::where('email', 'admin@dpi-rdc.local')->firstOrFail();
        $this->etab = Establishment::firstOrFail();

        $this->chef = User::factory()->create(['establishment_id' => $this->etab->id]);
        $this->chef->assignRole('chef_facturation');

        $this->caissier = User::factory()->create(['establishment_id' => $this->etab->id]);
        $this->caissier->assignRole('caissier');

        $this->actingAs($this->admin);

        $this->patient = Patient::create([
            'establishment_id' => $this->etab->id,
            'dossier_number' => 'PAT-2026-007700',
            'nom' => 'KABONGO', 'postnom' => 'MUKENDI', 'prenom' => 'Sylvie',
            'sexe' => 'F',
            'date_naissance' => now()->subYears(38)->toDateString(),
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
            'motif_consultation' => 'Paludisme grave',
        ]);
    }

    // ═══════════════════════════════════════════════════════════
    // Outils
    // ═══════════════════════════════════════════════════════════

    protected function factureSejour(): Facture
    {
        return app(FacturationService::class)->creerFactureHospitalisation($this->sejour);
    }

    protected function payer(Facture $facture, ?float $montant = null): void
    {
        app(FacturationService::class)->validerPaiement(
            $facture->fresh(),
            $montant ?? $facture->fresh()->soldeRestant(),
            'CDF',
            'especes'
        );
    }

    protected function assurer(int $taux = 80): Assurance
    {
        $assurance = Assurance::firstOrCreate(
            ['code' => 'SONAS'],
            ['nom' => 'SONAS', 'taux_couverture' => $taux, 'est_actif' => true]
        );

        $this->patient->update(['type_prise_en_charge' => 'assurance']);

        PatientAssurance::create([
            'patient_id' => $this->patient->id,
            'assurance_id' => $assurance->id,
            'numero_police' => 'SN-7701',
            'date_debut' => now()->subYear()->toDateString(),
            'annee_courante' => (int) now()->format('Y'),
            'est_actif' => true,
        ]);

        return $assurance;
    }

    /** Un examen à deux types, chacun avec son prix : deux lignes de facture. */
    protected function examen(): ExamenLaboratoire
    {
        $examen = ExamenLaboratoire::create([
            'numero_bon' => 'LAB-AV-'.random_int(1000, 9999),
            'patient_id' => $this->patient->id,
            'visit_id' => $this->sejour->id,
            'prescripteur_id' => $this->admin->id,
            'date_prescription' => now(),
            'statut' => 'prescrit',
            'domaine' => 'labo',
        ]);

        $types = TypeExamen::where('domaine', 'labo')->where('prix', '>', 0)->get()
            ->filter(fn ($t) => count($t->valeurs_reference['parametres'] ?? []) <= 1)
            ->take(2);

        $this->assertCount(2, $types, 'Le catalogue doit offrir deux examens simples payants.');

        foreach ($types as $type) {
            ResultatExamen::create([
                'examen_id' => $examen->id,
                'type_examen_id' => $type->id,
            ]);
        }

        return $examen;
    }

    protected function avoirTotal(Facture $facture, bool $liberer = false, string $categorie = 'erreur_prise_en_charge'): Avoir
    {
        return app(AvoirService::class)->emettre(
            $facture,
            $categorie,
            'Patient couvert par la SONAS, facturé en privé par erreur.',
            null,
            $liberer
        );
    }

    // ═══════════════════════════════════════════════════════════
    // La facture émise ne bouge plus
    // ═══════════════════════════════════════════════════════════

    public function test_une_facture_emise_ne_se_reecrit_pas(): void
    {
        $facture = $this->factureSejour()->fresh();

        try {
            $facture->update(['patient_part' => 1]);
            $this->fail('La part patient d\'une facture émise a pu être modifiée.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('Établissez un avoir', $e->getMessage());
        }

        $this->expectException(LogicException::class);
        $facture->fresh()->delete();
    }

    public function test_ses_lignes_et_ses_encaissements_non_plus(): void
    {
        $facture = $this->factureSejour();
        $this->payer($facture, 10000);

        $ligne = LigneFacture::where('facture_id', $facture->id)->firstOrFail();
        $paiement = Paiement::where('facture_id', $facture->id)->firstOrFail();

        foreach ([
            fn () => $ligne->update(['total_ligne' => 1]),
            fn () => $ligne->delete(),
            fn () => $paiement->update(['montant' => 1]),
            fn () => $paiement->delete(),
            // Une facture qui a reçu de l'argent ne s'allonge plus.
            fn () => LigneFacture::create([
                'facture_id' => $facture->id, 'type' => 'autre', 'libelle' => 'Ajout tardif',
                'quantite' => 1, 'prix_unitaire' => 5000, 'total_ligne' => 5000,
            ]),
        ] as $geste) {
            try {
                $geste();
                $this->fail('Une écriture de caisse a pu être modifiée après coup.');
            } catch (LogicException) {
                // attendu
            }
        }

        $this->assertSame(1, Paiement::where('facture_id', $facture->id)->count());
    }

    public function test_le_statut_et_les_acomptes_suivent_toujours_la_vie_de_la_facture(): void
    {
        // Le scellement ne doit pas bloquer l'encaissement lui-même.
        $facture = $this->factureSejour();
        $this->payer($facture);

        $this->assertSame('payee', $facture->fresh()->statut);
    }

    // ═══════════════════════════════════════════════════════════
    // L'annulation
    // ═══════════════════════════════════════════════════════════

    public function test_le_chef_de_facturation_annule_une_facture_impayee(): void
    {
        $facture = $this->factureSejour();
        $this->actingAs($this->chef);

        $this->post(route('avoirs.store', $facture), [
            'portee' => 'totale',
            'motif_categorie' => 'doublon',
            'motif' => 'Facture émise deux fois au guichet du soir.',
        ])->assertRedirect()->assertSessionHas('success');

        $avoir = Avoir::firstOrFail();
        $facture->refresh();

        $this->assertSame('annulee', $facture->statut);
        $this->assertSame('AV-'.now()->format('Y').'-000001', $avoir->numero);
        $this->assertSame('annulation', $avoir->type);
        $this->assertEqualsWithDelta((float) $facture->total_ttc, (float) $avoir->montant_total, 0.01);
        $this->assertSame($this->chef->id, $avoir->emis_par);
        $this->assertEquals(0, (float) $avoir->credit_patient);
        $this->assertEquals(0.0, $facture->soldeRestant());

        // Le guichet le voit, et n'encaisse plus.
        $this->get(route('caisse.show', $facture))->assertOk()
            ->assertSee('Facture annulée par l\'avoir', false)
            ->assertSee($avoir->numero);
    }

    public function test_une_facture_annulee_ne_sencaisse_plus(): void
    {
        $facture = $this->factureSejour();
        $this->avoirTotal($facture);

        $this->actingAs($this->caissier);

        $this->post(route('caisse.encaisser', $facture), [
            'montant' => 5000, 'devise' => 'CDF', 'mode_paiement' => 'especes',
        ])->assertSessionHas('error');

        $this->assertSame(0, Paiement::where('facture_id', $facture->id)->count());
    }

    public function test_une_facture_ne_sannule_quune_fois(): void
    {
        $facture = $this->factureSejour();
        $this->avoirTotal($facture);

        $this->expectException(RuntimeException::class);
        $this->avoirTotal($facture->fresh());
    }

    public function test_un_avoir_se_motive(): void
    {
        $facture = $this->factureSejour();
        $this->actingAs($this->chef);

        $this->post(route('avoirs.store', $facture), [
            'portee' => 'totale',
            'motif_categorie' => 'doublon',
            'motif' => '',
        ])->assertSessionHasErrors('motif');

        $this->assertSame(0, Avoir::count());
        $this->assertSame('emise', $facture->fresh()->statut);
    }

    public function test_les_bons_encore_en_main_tombent_avec_la_facture(): void
    {
        $examen = $this->examen();
        $facture = app(FacturationService::class)->creerFactureExamen($examen);

        app(FacturationService::class)->validerPaiement($facture, $facture->soldeRestant(), 'CDF', 'especes', null, null, $examen);
        $bon = BonSortie::where('facture_id', $facture->id)->firstOrFail();
        $this->assertSame('emis', $bon->statut);

        $this->avoirTotal($facture->fresh(), categorie: 'prestation_non_realisee');

        $this->assertSame('annule', $bon->fresh()->statut);
    }

    // ═══════════════════════════════════════════════════════════
    // Le trop-perçu devient un crédit
    // ═══════════════════════════════════════════════════════════

    public function test_lannulation_dune_facture_payee_laisse_un_credit_au_patient(): void
    {
        $facture = $this->factureSejour();
        $this->payer($facture);

        $avoir = $this->avoirTotal($facture->fresh());

        $this->assertEqualsWithDelta((float) $facture->patient_part, (float) $avoir->credit_patient, 0.01);

        $credit = Caution::findOrFail($avoir->caution_id);
        $this->assertSame('avoir', $credit->type);
        $this->assertSame($avoir->id, $credit->avoir_id);
        $this->assertSame($this->sejour->id, $credit->visit_id);
        $this->assertEqualsWithDelta((float) $facture->patient_part, $credit->resteDisponible(), 0.01);
        $this->assertSame('Crédit sur avoir', $credit->libelleType());

        // Le paiement, lui, reste au journal de caisse : l'argent est entré.
        $this->assertSame(1, Paiement::where('facture_id', $facture->id)->count());
        $this->assertSame('annulee', $facture->fresh()->statut);
    }

    public function test_le_credit_sert_a_regler_la_facture_suivante(): void
    {
        $facture = $this->factureSejour();
        $this->payer($facture);
        $this->avoirTotal($facture->fresh());

        $examen = $this->examen();
        $suivante = app(FacturationService::class)->creerFactureExamen($examen);

        $this->actingAs($this->caissier)
            ->post(route('caisse.acompte', $suivante))
            ->assertSessionHas('success');

        $this->assertGreaterThan(0, (float) $suivante->fresh()->acompte_impute);
    }

    public function test_la_caisse_rend_le_credit_en_especes(): void
    {
        $facture = $this->factureSejour();
        $this->payer($facture);
        $avoir = $this->avoirTotal($facture->fresh());

        $this->actingAs($this->caissier)
            ->get(route('avoirs.show', $avoir))
            ->assertOk()
            ->assertSee('Rendre');

        $this->post(route('avoirs.rembourser', $avoir), ['reference' => 'REC-RENDU-1'])
            ->assertSessionHas('success');

        $credit = Caution::findOrFail($avoir->caution_id);
        $this->assertSame('remboursee_total', $credit->statut);
        $this->assertEquals(0.0, $credit->resteDisponible());
    }

    // ═══════════════════════════════════════════════════════════
    // L'avoir partiel
    // ═══════════════════════════════════════════════════════════

    public function test_un_avoir_partiel_reprend_une_ligne_et_reduit_le_reste_du(): void
    {
        $examen = $this->examen();
        $facture = app(FacturationService::class)->creerFactureExamen($examen);
        $ligne = $facture->lignes()->orderBy('libelle')->firstOrFail();
        $reprise = round((float) $ligne->total_ligne / 2, 2);

        $avoir = app(AvoirService::class)->emettre(
            $facture,
            'prestation_non_realisee',
            'Réactif en rupture : la moitié du bilan n\'a pas été faite.',
            [$ligne->id => $reprise]
        );

        $facture->refresh();

        $this->assertSame('partiel', $avoir->type);
        $this->assertSame('emise', $facture->statut);
        $this->assertEqualsWithDelta((float) $facture->total_ttc - $reprise, $facture->totalNet(), 0.01);
        $this->assertEqualsWithDelta((float) $facture->patient_part - $reprise, $facture->soldeRestant(), 0.01);

        // Le patient règle ce qui reste : la facture est soldée.
        $this->payer($facture);
        $this->assertSame('payee', $facture->fresh()->statut);
    }

    public function test_on_ne_reprend_pas_plus_que_ce_qui_reste_sur_la_ligne(): void
    {
        $examen = $this->examen();
        $facture = app(FacturationService::class)->creerFactureExamen($examen);
        $ligne = $facture->lignes()->firstOrFail();

        app(AvoirService::class)->emettre($facture, 'erreur_tarif', 'Tarif de l\'an dernier appliqué.',
            [$ligne->id => (float) $ligne->total_ligne - 100]);

        $this->expectException(RuntimeException::class);
        app(AvoirService::class)->emettre($facture->fresh(), 'erreur_tarif', 'Seconde reprise trop forte.',
            [$ligne->id => 500]);
    }

    public function test_un_avoir_partiel_sur_une_facture_payee_rend_la_difference(): void
    {
        $examen = $this->examen();
        $facture = app(FacturationService::class)->creerFactureExamen($examen);
        $this->payer($facture);

        $ligne = $facture->lignes()->firstOrFail();
        $avoir = app(AvoirService::class)->emettre($facture->fresh(), 'erreur_tarif', 'Quantité saisie en double.',
            [$ligne->id => 1000]);

        $this->assertEqualsWithDelta(1000, (float) $avoir->credit_patient, 0.01);
        $this->assertSame('payee', $facture->fresh()->statut);
        $this->assertEquals(0.0, $facture->fresh()->soldeRestant());
    }

    // ═══════════════════════════════════════════════════════════
    // L'assureur
    // ═══════════════════════════════════════════════════════════

    public function test_lavoir_rend_au_plafond_ce_que_lassureur_ne_paie_plus(): void
    {
        $assurance = $this->assurer();
        $facture = $this->factureSejour();
        $contrat = PatientAssurance::where('patient_id', $this->patient->id)->firstOrFail();

        $this->assertGreaterThan(0, (float) $facture->assurance_part);
        $this->assertEqualsWithDelta((float) $facture->assurance_part, (float) $contrat->fresh()->consomme_annuel_cdf, 0.01);

        $avoir = $this->avoirTotal($facture);

        $this->assertEqualsWithDelta((float) $facture->assurance_part, (float) $avoir->montant_assurance, 0.01);
        $this->assertEqualsWithDelta((float) $facture->patient_part, (float) $avoir->montant_patient, 0.01);
        $this->assertEquals(0, (float) $contrat->fresh()->consomme_annuel_cdf);

        // Et la facture annulée ne se réclame plus à l'assureur.
        $this->assertTrue(app(ConventionService::class)
            ->facturesARefacturer($assurance, now()->subDay()->toDateString(), now()->toDateString())
            ->isEmpty());
    }

    public function test_lavoir_est_deduit_du_bordereau_deja_envoye(): void
    {
        $assurance = $this->assurer();
        $facture = $this->factureSejour();
        $conventions = app(ConventionService::class);

        $bordereau = $conventions->emettre($assurance, now()->subDay()->toDateString(), now()->toDateString());
        $du = $bordereau->resteDu();
        $this->assertEqualsWithDelta((float) $facture->assurance_part, $du, 0.01);

        $ligne = $facture->lignes()->firstOrFail();
        $avoir = app(AvoirService::class)->emettre($facture->fresh(), 'erreur_tarif', 'Un jour de trop compté.',
            [$ligne->id => 10000]);

        $this->assertSame($bordereau->id, $avoir->facture_convention_id);
        $this->assertGreaterThan(0, (float) $avoir->montant_assurance);
        $this->assertEqualsWithDelta($du - (float) $avoir->montant_assurance, $bordereau->fresh()->resteDu(), 0.01);

        // L'écran du bordereau dit ce qu'il déduit, et pourquoi.
        $this->get(route('conventions.show', $bordereau))
            ->assertOk()
            ->assertSee('d\'avoirs', false)
            ->assertSee($avoir->numero);

        // L'assureur qui paie le net solde le bordereau.
        $conventions->enregistrerReglement($bordereau->fresh(), $bordereau->fresh()->resteDu());
        $this->assertSame('reglee', $bordereau->fresh()->statut);
    }

    // ═══════════════════════════════════════════════════════════
    // Libérer et refacturer
    // ═══════════════════════════════════════════════════════════

    public function test_un_sejour_facture_en_prive_se_refacture_a_lassureur(): void
    {
        $facture = $this->factureSejour();
        $jours = (int) $this->sejour->fresh()->jours_factures;
        $this->assertGreaterThan(0, $jours);
        $this->payer($facture);

        // On découvre que la patiente était couverte : on corrige sa fiche,
        // on annule en libérant, puis on refacture.
        $this->assurer();
        $avoir = $this->avoirTotal($facture->fresh(), liberer: true);

        $this->assertSame(0, (int) $this->sejour->fresh()->jours_factures);
        $this->assertTrue($avoir->peutEtreRefacture());
        $this->assertSame('annule', ActeClinique::where('visit_id', $this->sejour->id)
            ->where('domaine', 'hospitalisation')->value('statut'));

        $nouvelles = app(AvoirService::class)->refacturer($avoir);

        $this->assertCount(1, $nouvelles);
        $nouvelle = $nouvelles->first();
        $this->assertNotSame($facture->id, $nouvelle->id);
        $this->assertSame($avoir->id, $nouvelle->avoir_origine_id);
        $this->assertSame('SONAS', $nouvelle->assurance_nom);
        $this->assertGreaterThan(0, (float) $nouvelle->assurance_part);
        $this->assertSame($jours, (int) $this->sejour->fresh()->jours_factures);

        // Le crédit laissé par l'annulation règle la part du patient.
        $this->assertSame('payee', $nouvelle->statut);
        $this->assertNotNull($avoir->fresh()->refacture_le);

        // On ne refacture pas deux fois.
        $this->expectException(RuntimeException::class);
        app(AvoirService::class)->refacturer($avoir->fresh());
    }

    public function test_un_examen_libere_se_refacture_une_seule_fois(): void
    {
        $examen = $this->examen();
        $facture = app(FacturationService::class)->creerFactureExamen($examen);

        $avoir = $this->avoirTotal($facture, liberer: true);
        $this->assertNull($examen->fresh()->facture_id);

        $this->actingAs($this->chef)
            ->post(route('avoirs.refacturer', $avoir))
            ->assertRedirect();

        $nouvelle = Facture::where('avoir_origine_id', $avoir->id)->firstOrFail();
        $this->assertSame($nouvelle->id, $examen->fresh()->facture_id);
        $this->assertSame('emise', $nouvelle->statut);
    }

    public function test_sans_liberation_la_prestation_reste_attachee_a_la_facture_annulee(): void
    {
        $examen = $this->examen();
        $facture = app(FacturationService::class)->creerFactureExamen($examen);

        $avoir = $this->avoirTotal($facture, liberer: false, categorie: 'geste_commercial');

        $this->assertSame($facture->id, $examen->fresh()->facture_id);
        $this->assertFalse($avoir->peutEtreRefacture());
    }

    public function test_une_consultation_annulee_se_refacture(): void
    {
        $visite = Visit::create([
            'patient_id' => $this->patient->id,
            'establishment_id' => $this->etab->id,
            'user_id' => $this->admin->id,
            'type' => 'consultation_externe',
            'statut' => 'en_attente',
            'date_entree' => now(),
            'motif_consultation' => 'Céphalées',
        ]);
        $facture = app(FacturationService::class)->creerFactureConsultation($visite);

        $avoir = $this->avoirTotal($facture, liberer: true, categorie: 'erreur_tarif');
        $nouvelles = app(AvoirService::class)->refacturer($avoir);

        $this->assertCount(1, $nouvelles);
        $this->assertNotSame($facture->id, $nouvelles->first()->id);
        $this->assertTrue($nouvelles->first()->lignes->contains('type', 'consultation'));
    }

    public function test_un_forfait_annule_se_retire_puis_se_reapplique(): void
    {
        $forfait = Forfait::create([
            'establishment_id' => $this->etab->id,
            'code' => 'GLOBAL-AV',
            'libelle' => 'Forfait accouchement',
            'portee' => 'global',
            'montant' => 250000,
            'devise' => 'CDF',
            'categories_couvertes' => array_keys(Forfait::CATEGORIES),
            'is_active' => true,
        ]);

        $facture = app(ForfaitService::class)->appliquer($this->sejour, $forfait);
        $avoir = $this->avoirTotal($facture, liberer: true);

        $this->assertNull($this->sejour->fresh()->forfait_id);

        $nouvelle = app(AvoirService::class)->refacturer($avoir)->first();

        $this->assertSame($forfait->id, $this->sejour->fresh()->forfait_id);
        $this->assertSame($nouvelle->id, $this->sejour->fresh()->forfait_facture_id);
    }

    // ═══════════════════════════════════════════════════════════
    // Qui peut quoi
    // ═══════════════════════════════════════════════════════════

    public function test_le_caissier_encaisse_mais_nannule_pas(): void
    {
        $facture = $this->factureSejour();
        $this->actingAs($this->caissier);

        $this->get(route('avoirs.create', $facture))->assertForbidden();
        $this->post(route('avoirs.store', $facture), [
            'portee' => 'totale', 'motif_categorie' => 'doublon', 'motif' => 'Je me suis trompé.',
        ])->assertForbidden();
        $this->get(route('caisse.journal', $facture))->assertForbidden();

        // Il ne voit même pas le bouton.
        $this->get(route('caisse.show', $facture))->assertOk()->assertDontSee('Établir un avoir');

        $this->assertSame('emise', $facture->fresh()->statut);
    }

    public function test_la_direction_et_le_chef_de_facturation_annulent(): void
    {
        $directeur = User::factory()->create(['establishment_id' => $this->etab->id]);
        $directeur->assignRole('directeur');

        $factures = [
            $this->chef->id => $this->factureSejour(),
            $directeur->id => app(FacturationService::class)->creerFactureExamen($this->examen()),
        ];

        foreach ([$this->chef, $directeur] as $utilisateur) {
            $this->actingAs($utilisateur);
            $facture = $factures[$utilisateur->id];

            $this->get(route('caisse.show', $facture))->assertOk()->assertSee('Établir un avoir');
            $this->get(route('avoirs.create', $facture))->assertOk()->assertSee('Établir un avoir');
        }

        $this->assertTrue($this->chef->can('facture.annuler'));
        $this->assertFalse($this->caissier->can('facture.annuler'));
        $this->assertSame('Chef de facturation', $this->chef->libelleRoles());
    }

    // ═══════════════════════════════════════════════════════════
    // Le journal
    // ═══════════════════════════════════════════════════════════

    public function test_le_journal_rattache_chaque_geste_a_la_facture_et_a_son_auteur(): void
    {
        $this->actingAs($this->caissier);
        $facture = $this->factureSejour();
        $this->payer($facture, 5000);

        $this->actingAs($this->chef);
        $avoir = $this->avoirTotal($facture->fresh());

        $journal = AuditLog::where('facture_id', $facture->id)->get();

        $this->assertTrue($journal->contains(fn ($l) => $l->action === 'factures.create' && $l->user_id === $this->caissier->id));
        $this->assertTrue($journal->contains(fn ($l) => $l->action === 'paiements.create' && $l->user_id === $this->caissier->id));
        $this->assertTrue($journal->contains(fn ($l) => $l->action === 'avoirs.create' && $l->user_id === $this->chef->id));
        $this->assertTrue($journal->contains(fn ($l) => $l->action === 'cautions.create' && $l->record_id === $avoir->caution_id));

        $annulation = $journal->first(fn ($l) => $l->action === 'factures.update'
            && ($l->new_values['statut'] ?? null) === 'annulee');
        $this->assertNotNull($annulation);
        $this->assertSame('partiellement_payee', $annulation->old_values['statut']);

        $this->get(route('caisse.journal', $facture))
            ->assertOk()
            ->assertSee('Encaissement')
            ->assertSee('Avoir')
            ->assertSee($this->chef->nom_complet)
            ->assertSee('partiellement payée → annulée', false);
    }

    public function test_le_journal_general_garde_lauteur_meme_traite_plus_tard(): void
    {
        // En production la file traite le travail après la requête : le
        // worker n'a plus de session. L'auteur doit avoir été lu avant.
        $job = new WriteAuditLog(action: 'patients.update', tableName: 'patients', recordId: $this->patient->id);

        Auth::logout();
        $job->handle();

        $this->assertSame(
            $this->admin->id,
            AuditLog::where('action', 'patients.update')->latest('created_at')->value('user_id')
        );
    }

    public function test_le_registre_des_avoirs_classe_par_motif(): void
    {
        $this->avoirTotal($this->factureSejour(), categorie: 'doublon');

        $this->actingAs($this->chef)
            ->get(route('avoirs.index'))
            ->assertOk()
            ->assertSee('Facture en double')
            ->assertSee('AV-'.now()->format('Y').'-000001');

        $this->actingAs($this->caissier)->get(route('avoirs.index'))->assertForbidden();
    }

    public function test_limpression_dit_que_la_facture_est_annulee(): void
    {
        $facture = $this->factureSejour();
        $avoir = $this->avoirTotal($facture);

        $this->get(route('caisse.imprimer', $facture))
            ->assertOk()
            ->assertSee('Facture annulée')
            ->assertSee('Annulée par l\'avoir '.$avoir->numero, false);
    }
}
