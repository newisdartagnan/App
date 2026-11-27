<?php

namespace Tests\Feature;

use App\Models\ActeCatalogue;
use App\Models\ActeClinique;
use App\Models\Assurance;
use App\Models\CategorieTarifaire;
use App\Models\Establishment;
use App\Models\Patient;
use App\Models\PatientAssurance;
use App\Models\User;
use App\Models\Visit;
use App\Services\DeviseService;
use App\Services\FacturationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Conventions classées par catégorie (A, B, C, D, CD, DD) et fonction
 * tarifaire des actes : prix conventionné = prix de base × coefficient
 * [catégorie][fonction]. Fiche acte : prix normal / urgent / externe,
 * disponibilité, catégorie d'acte.
 */
class CategoriesConventionEtFicheActeTest extends TestCase
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

    public function test_la_grille_de_depart_et_sa_modification(): void
    {
        $a = CategorieTarifaire::where('nom', 'A')->firstOrFail();
        $this->assertSame(0.5504, $a->coefficient(1));
        $this->assertSame(0.5, $a->coefficient(4));
        $this->assertSame(0.7844, $a->coefficient(8));
        $this->assertSame(1.0, CategorieTarifaire::where('nom', 'C')->first()->coefficient(3));
        $this->assertSame(['A', 'B', 'C', 'D', 'CD', 'DD'], CategorieTarifaire::orderBy('numero')->pluck('nom')->all());

        $this->get(route('tarification.categories'))->assertOk()->assertSee('Coeff. 8')->assertSee('0.8088');

        $coeffs = [0.6, 0.6, 0.6, 0.55, 1, 0.8, 0.6, 0.8];
        $this->post(route('tarification.categories.update', $a), ['nom' => 'a', 'coefficients' => $coeffs])->assertSessionHas('success');
        $this->assertSame(0.55, $a->fresh()->coefficient(4));
        $this->assertSame('A', $a->fresh()->nom);

        $this->post(route('tarification.categories.store'), ['nom' => 'E', 'coefficients' => array_fill(0, 8, '2')])->assertSessionHas('success');
        $this->assertSame(7, CategorieTarifaire::where('nom', 'E')->value('numero'));
    }

    public function test_fiche_acte_et_prise_en_charge_selon_tarif(): void
    {
        $this->get(route('tarification.actes.create'))->assertOk()->assertSee('Fonction tarifaire')->assertSee('Non (temporaire)')->assertSee('INTERVENTION CHIRURGICALE MAJEURE');

        $this->post(route('tarification.actes.store'), [
            'numero' => 1158, 'libelle' => 'Biopsie simple', 'fonction_tarifaire' => 4,
            'prix_normal' => 65.14, 'prix_urgent' => 53.87, 'prix_externe' => 53.87, 'devise' => 'USD',
            'specialite' => 'Laboratoire - Anatomo-Cytopathologie', 'disponibilite' => 'non_definitif',
            'categorie_acte' => 'Intervention chirurgicale majeure', 'domaine' => 'chirurgie',
        ])->assertRedirect();
        $acte = ActeCatalogue::where('numero', 1158)->firstOrFail();
        $this->assertFalse($acte->estDisponible());

        // Catégorie A, fonction 4 : 65,14 × 0,50 = 32,57 ; B : × 0,737.
        $this->get(route('tarification.actes.edit', $acte))->assertOk()
            ->assertSee('Prise en charge selon tarif')->assertSee('32,57')->assertSee('48,01');
        $this->get(route('tarification.actes'))->assertOk()->assertSee('Biopsie simple')->assertSee('Non (définitif)');
    }

    public function test_un_acte_du_catalogue_facture_au_coefficient_de_la_categorie(): void
    {
        $categorieA = CategorieTarifaire::where('nom', 'A')->first();
        $assurance = Assurance::create(['nom' => 'SNEL', 'code' => 'SNEL-T', 'taux_couverture' => 100, 'est_actif' => true]);
        $this->post(route('tarification.conventions.classer', $assurance), ['categorie_tarifaire_id' => $categorieA->id])->assertSessionHas('success');
        $this->assertSame($categorieA->id, $assurance->fresh()->categorie_tarifaire_id);

        ActeCatalogue::create([
            'numero' => 2001, 'libelle' => 'Appendicectomie', 'fonction_tarifaire' => 4, 'prix_normal' => 100,
            'prix_urgent' => 150, 'devise' => 'USD', 'disponibilite' => 'oui', 'domaine' => 'chirurgie', 'duree_minutes' => 90,
        ]);
        ActeCatalogue::create(['numero' => 2002, 'libelle' => 'Acte suspendu', 'fonction_tarifaire' => 1, 'prix_normal' => 10,
            'devise' => 'USD', 'disponibilite' => 'non_temporaire', 'domaine' => 'chirurgie']);

        $patient = Patient::create([
            'establishment_id' => $this->etab->id, 'dossier_number' => 'PAT-CAT-1', 'nom' => 'CWINYA', 'prenom' => 'Ay',
            'sexe' => 'M', 'type_prise_en_charge' => 'assurance', 'assurance_nom' => 'SNEL',
        ]);
        PatientAssurance::create(['patient_id' => $patient->id, 'assurance_id' => $assurance->id, 'numero_police' => 'SNEL-001', 'est_actif' => true]);
        $visit = Visit::create(['patient_id' => $patient->id, 'establishment_id' => $this->etab->id, 'user_id' => $this->admin->id,
            'type' => 'hospitalisation', 'statut' => 'en_cours', 'date_entree' => now()]);

        // Le formulaire propose l'acte du catalogue ; l'acte suspendu n'y figure pas.
        $this->get(route('bloc.create', ['visit_id' => $visit->id]))->assertOk()->assertSee('Appendicectomie')->assertDontSee('Acte suspendu');

        $this->post(route('bloc.store'), ['visit_id' => $visit->id, 'domaine' => 'chirurgie', 'libelle' => 'Appendicectomie', 'prix' => 1])
            ->assertRedirect();
        $acte = ActeClinique::where('libelle', 'Appendicectomie')->firstOrFail();
        $prixCdf = round(app(DeviseService::class)->versCdf(100, 'USD'));
        $this->assertEquals($prixCdf, (float) $acte->prix);
        $this->assertSame(4, (int) $acte->fonction_tarifaire);

        $facture = app(FacturationService::class)->creerFactureActeClinique($acte);
        $ligne = $facture->lignes()->first();
        $this->assertEqualsWithDelta($prixCdf * 0.5, (float) $ligne->prix_unitaire, 0.01);

        // Un acte suspendu ne se demande pas.
        $this->post(route('bloc.store'), ['visit_id' => $visit->id, 'domaine' => 'chirurgie', 'libelle' => 'Acte suspendu', 'prix' => 1])
            ->assertSessionHas('error');
    }

    public function test_sans_categorie_le_coefficient_du_contrat_reste_applique(): void
    {
        $assurance = Assurance::create(['nom' => 'X', 'code' => 'X-T', 'taux_couverture' => 80, 'est_actif' => true, 'coefficient_tarif' => 1.2]);
        $this->assertSame(120.0, $assurance->prixPour('acte_chirurgical', null, 100, false, 4));

        $assurance->update(['categorie_tarifaire_id' => CategorieTarifaire::where('nom', 'D')->value('id')]);
        $this->assertSame(147.06, $assurance->fresh()->prixPour('examen_labo', null, 100, false, 2));
        // Sans fonction tarifaire : coefficient du contrat.
        $this->assertSame(120.0, $assurance->fresh()->prixPour('examen_labo', null, 100, false, null));
    }
}
