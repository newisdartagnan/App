<?php

namespace Tests\Feature;

use App\Models\Ambulance;
use App\Models\Establishment;
use App\Models\ExamenLaboratoire;
use App\Models\Facture;
use App\Models\Paiement;
use App\Models\Patient;
use App\Models\ResultatExamen;
use App\Models\Service;
use App\Models\SortieAmbulance;
use App\Models\TypeExamen;
use App\Models\User;
use App\Models\Visit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Les rapports du menu Rapports, leurs totaux, l'export CSV et les droits. */
class RapportsDataClinicTest extends TestCase
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
            'establishment_id' => $this->etab->id, 'dossier_number' => 'PAT-RAP-1',
            'nom' => 'UBEGIU', 'prenom' => 'Oscar', 'sexe' => 'M',
            'date_naissance' => now()->subYears(33)->toDateString(), 'type_prise_en_charge' => 'prive',
        ]);
    }

    public function test_page_des_rapports_et_rapport_patients(): void
    {
        $service = Service::firstOrFail();
        Visit::create([
            'patient_id' => $this->patient->id, 'establishment_id' => $this->etab->id, 'user_id' => $this->admin->id,
            'type' => 'hospitalisation', 'statut' => 'termine', 'service_id' => $service->id,
            'date_entree' => now()->subDays(3), 'date_sortie' => now(), 'mode_sortie' => 'gueri',
        ]);

        $this->get(route('rapports.index'))->assertOk()->assertSee('Patients')->assertSee('Caisse / Facturation')->assertSee('Canevas SNIS');
        $this->get(route('rapports.afficher', 'patients'))->assertOk()
            ->assertSee('UBEGIU')->assertSee('Guéri')->assertSee('Nouveau')->assertSee($service->nom);

        $this->get(route('rapports.afficher', 'services'))->assertOk()->assertSee($service->nom)->assertSee('Guéri');
    }

    public function test_rapport_de_caisse_par_jour_avec_details_et_csv(): void
    {
        $f1 = Facture::create(['patient_id' => $this->patient->id, 'establishment_id' => $this->etab->id, 'numero_facture' => 'FAC-R-1',
            'date_facture' => now(), 'statut' => 'payee', 'devise' => 'CDF', 'total_ht' => 10000, 'total_ttc' => 10000, 'patient_part' => 10000]);
        Facture::create(['patient_id' => $this->patient->id, 'establishment_id' => $this->etab->id, 'numero_facture' => 'FAC-R-2',
            'date_facture' => now(), 'statut' => 'emise', 'devise' => 'CDF', 'total_ht' => 5000, 'total_ttc' => 5000, 'patient_part' => 5000]);
        Paiement::create(['facture_id' => $f1->id, 'caissier_id' => $this->admin->id, 'date_paiement' => now(), 'montant' => 10000,
            'devise' => 'CDF', 'taux_change' => 1, 'montant_cdf' => 10000, 'mode_paiement' => 'especes']);

        $this->get(route('rapports.afficher', 'caisse'))->assertOk()
            ->assertSee('Factures créées')->assertSee('Recette totale')->assertSee('Total général')->assertSee('Détails');
        $this->get(route('rapports.caisse.jour', now()->toDateString()))->assertOk()->assertSee('FAC-R-1')->assertSee('FAC-R-2');

        $csv = $this->get(route('rapports.afficher', ['rapport' => 'caisse', 'format' => 'csv']));
        $csv->assertOk();
        $this->assertStringContainsString('Factures créées', $csv->streamedContent());
    }

    public function test_rapports_labo_imagerie_pharmacie_et_ambulances(): void
    {
        $examen = ExamenLaboratoire::create(['numero_bon' => 'LAB-R-1', 'patient_id' => $this->patient->id, 'prescripteur_id' => $this->admin->id,
            'date_prescription' => now(), 'statut' => 'valide', 'domaine' => 'labo']);
        $type = TypeExamen::where('domaine', 'labo')->first();
        ResultatExamen::create(['examen_id' => $examen->id, 'type_examen_id' => $type->id, 'parametre' => $type->libelle, 'valeur_brute' => '12', 'valeurs_reference' => []]);

        $this->get(route('rapports.afficher', 'laboratoire'))->assertOk()->assertSee($type->libelle)->assertSee('Réalisés');
        $this->get(route('rapports.afficher', 'imagerie'))->assertOk()->assertSee('Technique');
        $this->get(route('rapports.afficher', 'pharmacie'))->assertOk()->assertSee('Stock physique');

        $amb = Ambulance::create(['establishment_id' => $this->etab->id, 'libelle' => 'Ambulance 1', 'is_active' => true]);
        SortieAmbulance::create(['ambulance_id' => $amb->id, 'motif' => 'Évacuation', 'depart_at' => now()->subHour(), 'arrivee_at' => now(), 'km_depart' => 10, 'km_arrivee' => 35]);
        $this->get(route('rapports.afficher', 'ambulances'))->assertOk()->assertSee('Évacuation')->assertSee('25')->assertSee('1 h 00');
    }

    public function test_droits_sur_les_rapports(): void
    {
        $caissier = User::factory()->create(['establishment_id' => $this->etab->id]);
        $caissier->assignRole('caissier');
        $this->actingAs($caissier)->get(route('rapports.afficher', 'caisse'))->assertForbidden();
        $this->actingAs($caissier)->get(route('rapports.afficher', 'ambulances'))->assertOk();

        $labo = User::factory()->create(['establishment_id' => $this->etab->id]);
        $labo->assignRole('laborantin');
        $this->actingAs($labo)->get(route('rapports.afficher', 'laboratoire'))->assertOk();
        $this->actingAs($labo)->get(route('rapports.afficher', 'patients'))->assertForbidden();
        $this->actingAs($labo)->get(route('rapports.afficher', 'inexistant'))->assertNotFound();
    }
}
