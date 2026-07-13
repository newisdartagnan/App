<?php

namespace Tests\Feature;

use App\Livewire\Caisse\FactureShow;
use App\Livewire\Consultations\ConsultationCreate;
use App\Livewire\Patients\PatientCreate;
use App\Livewire\Pharmacie\PrescriptionDispensing;
use App\Livewire\Prescriptions\PrescriptionCreate;
use App\Models\Consultation;
use App\Models\Establishment;
use App\Models\ExamenLaboratoire;
use App\Models\Facture;
use App\Models\Lit;
use App\Models\Medicament;
use App\Models\Patient;
use App\Models\Prescription;
use App\Models\Service;
use App\Models\TypeExamen;
use App\Models\User;
use App\Models\Visit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Parcours patient complet : accueil → consultation → guichet → hospitalisation
 * → laboratoire → pharmacie → facturation séjour → sortie.
 *
 * Chaque étape respecte la règle métier « paiement au guichet avant réalisation ».
 */
class ParcoursPatientTest extends TestCase
{
    use RefreshDatabase;

    protected User $medecin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();

        $this->medecin = User::where('email', 'admin@dpi-rdc.local')->firstOrFail();
        $this->actingAs($this->medecin);
    }

    public function test_parcours_complet_ambulatoire_hospitalisation_labo_pharmacie_sortie(): void
    {
        // ── 1. Enregistrement du patient à l'accueil ────────────────────────
        Livewire::test(PatientCreate::class)
            ->set('nom', 'KASONGO')
            ->set('prenom', 'Didier')
            ->set('sexe', 'M')
            ->set('date_naissance', '1988-04-12')
            ->set('type_prise_en_charge', 'prive')
            ->call('save')
            ->assertHasNoErrors();

        $patient = Patient::where('nom', 'KASONGO')->firstOrFail();
        $this->assertNotNull($patient->dossier_number);

        // ── 2. Consultation (urgences) avec signes vitaux et diagnostic ─────
        Livewire::test(ConsultationCreate::class, ['patient' => $patient])
            ->set('type_visite', 'urgence')
            ->set('motif_consultation', 'Fièvre et céphalées depuis 3 jours')
            ->set('temperature', 39.2)
            ->set('tension_systolique', 120)
            ->set('tension_diastolique', 80)
            ->set('nouveau_diagnostic_libelle', 'Paludisme simple')
            ->set('nouveau_diagnostic_code', 'B50')
            ->call('ajouterDiagnostic')
            ->call('save')
            ->assertHasNoErrors();

        $visit = Visit::where('patient_id', $patient->id)->firstOrFail();
        $consultation = Consultation::where('visit_id', $visit->id)->firstOrFail();
        $this->assertSame('urgence', $visit->type);

        // La page de la consultation s'affiche (régression : relation lignes_facture)
        $this->get(route('consultations.show', $consultation))->assertOk();

        // ── 3. Facturation de la consultation puis paiement au guichet ──────
        $this->post(route('consultations.facturer', $consultation))->assertRedirect();
        $factureConsult = Facture::where('visit_id', $visit->id)->firstOrFail();
        $this->assertSame('emise', $factureConsult->statut);

        Livewire::test(FactureShow::class, ['facture' => $factureConsult])
            ->call('validerPaiement')
            ->assertHasNoErrors();
        $this->assertSame('payee', $factureConsult->fresh()->statut);

        // ── 4. Hospitalisation : service + lit ───────────────────────────────
        $service = Service::where('code', 'MED')->firstOrFail();
        $lit = Lit::where('service_id', $service->id)->where('statut', 'libre')->firstOrFail();

        $this->post(route('visites.hospitaliser', $visit), [
            'service_id' => $service->id,
            'lit_id' => $lit->id,
        ])->assertRedirect();

        $visit->refresh();
        $this->assertSame('hospitalisation', $visit->type);
        $this->assertSame('occupe', $lit->fresh()->statut);

        // Le parcours patient s'affiche
        $this->get(route('visites.show', $visit))->assertOk();

        // ── 5. Laboratoire : prescription, facture, paiement, résultats ─────
        $types = TypeExamen::whereIn('code', ['GE', 'HB'])->pluck('id')->all();
        $this->assertCount(2, $types);

        $this->post(route('labo.store'), [
            'visit_id' => $visit->id,
            'domaine' => 'labo',
            'types' => $types,
            'urgence' => '1',
        ])->assertRedirect();

        $examen = ExamenLaboratoire::where('visit_id', $visit->id)->firstOrFail();
        $factureLabo = $examen->facture;
        $this->assertNotNull($factureLabo);

        // Saisie interdite avant paiement
        $this->post(route('labo.resultats', $examen), ['resultats' => []])
            ->assertSessionHas('error');

        Livewire::test(FactureShow::class, ['facture' => $factureLabo])
            ->call('validerPaiement')
            ->assertHasNoErrors();
        $this->assertSame('payee', $factureLabo->fresh()->statut);

        // Saisie des résultats puis validation du bilan
        $resultats = [];
        foreach ($examen->resultats as $resultat) {
            $resultats[$resultat->id] = [
                'valeur_brute' => $resultat->typeExamen->code === 'GE' ? 'positif' : '10.5',
                'valeur_numerique' => $resultat->typeExamen->code === 'GE' ? '' : '10.5',
            ];
        }
        $this->post(route('labo.resultats', $examen), ['resultats' => $resultats])
            ->assertSessionHas('success');
        $this->post(route('labo.valider', $examen))->assertSessionHas('success');
        $this->assertSame('valide', $examen->fresh()->statut);

        // Hémoglobine 10.5 < 12 → interprétation « bas »
        $hb = $examen->resultats()->whereHas('typeExamen', fn ($q) => $q->where('code', 'HB'))->first();
        $this->assertSame('bas', $hb->interpretation);

        // ── 6. Pharmacie : ordonnance, facture, paiement, dispensation ──────
        $medicament = Medicament::where('denomination_commune', 'Artéméther-Luméfantrine')->firstOrFail();
        $stockAvant = (float) $medicament->stock->quantite_disponible;

        Livewire::test(PrescriptionCreate::class, ['consultation' => $consultation])
            ->set('lignes.0.medicament_id', $medicament->id)
            ->set('lignes.0.dose', '4 comprimés')
            ->set('lignes.0.frequence', '2 fois/jour')
            ->set('lignes.0.duree_jours', 3)
            ->set('lignes.0.quantite_totale', 24)
            ->call('save')
            ->assertHasNoErrors();

        $prescription = Prescription::where('consultation_id', $consultation->id)->firstOrFail();
        $this->assertSame('brouillon', $prescription->statut);

        // Dispensation refusée avant paiement (pas de bon)
        Livewire::test(PrescriptionDispensing::class, ['prescription' => $prescription])
            ->call('dispenser');
        $this->assertSame('brouillon', $prescription->fresh()->statut);

        // Facturation au guichet puis paiement → bon pharmacie
        $this->post(route('caisse.facturer', $prescription))->assertRedirect();
        $prescription->refresh();
        $this->assertSame('en_attente_paiement', $prescription->statut);

        $facturePharma = Facture::where('prescription_id', $prescription->id)->firstOrFail();
        Livewire::test(FactureShow::class, ['facture' => $facturePharma])
            ->call('validerPaiement')
            ->assertHasNoErrors();

        $prescription->refresh();
        $this->assertSame('en_attente', $prescription->statut);
        $this->assertDatabaseHas('bons_sortie', [
            'prescription_id' => $prescription->id,
            'statut' => 'emis',
        ]);

        // Dispensation : le stock est décrémenté, le bon consommé
        Livewire::test(PrescriptionDispensing::class, ['prescription' => $prescription->fresh()])
            ->call('dispenser');

        $this->assertSame('dispensee', $prescription->fresh()->statut);
        $this->assertSame($stockAvant - 24, (float) $medicament->stock->fresh()->quantite_disponible);
        $this->assertDatabaseHas('bons_sortie', [
            'prescription_id' => $prescription->id,
            'statut' => 'utilise',
        ]);

        // ── 7. Sortie bloquée tant que le séjour n'est pas facturé/payé ─────
        $this->post(route('visites.facturer-sejour', $visit))->assertRedirect();
        $factureSejour = Facture::where('visit_id', $visit->id)
            ->where('statut', 'emise')->firstOrFail();

        $this->post(route('visites.sortir', $visit), ['mode_sortie' => 'gueri'])
            ->assertSessionHas('error');
        $this->assertSame('en_cours', $visit->fresh()->statut);

        Livewire::test(FactureShow::class, ['facture' => $factureSejour])
            ->call('validerPaiement')
            ->assertHasNoErrors();

        // ── 8. Sortie : lit libéré, visite terminée ──────────────────────────
        $this->post(route('visites.sortir', $visit), ['mode_sortie' => 'gueri'])
            ->assertRedirect();

        $visit->refresh();
        $this->assertSame('termine', $visit->statut);
        $this->assertNotNull($visit->date_sortie);
        $this->assertNull($visit->lit_id);
        $this->assertSame('libre', $lit->fresh()->statut);
    }

    public function test_pages_metier_accessibles(): void
    {
        foreach ([
            'dashboard', 'patients.index', 'patients.create', 'consultations.index',
            'visites.index', 'labo.index', 'imagerie.index', 'bloc.index',
            'maternite.index', 'pharmacie.dashboard', 'pharmacie.stock',
            'pharmacie.prescriptions', 'pharmacie.medicaments', 'caisse.index',
        ] as $route) {
            $this->get(route($route))->assertOk();
        }
    }

    public function test_recherche_patient(): void
    {
        $etab = Establishment::firstOrFail();
        Patient::create([
            'establishment_id' => $etab->id,
            'dossier_number' => 'TST-2026-000901',
            'nom' => 'MBUYI',
            'prenom' => 'Clarisse',
            'sexe' => 'F',
            'type_prise_en_charge' => 'prive',
        ]);

        $this->get(route('patients.index', ['search' => 'mbuyi']))
            ->assertOk()
            ->assertSee('MBUYI');

        $this->get(route('patients.index', ['search' => 'TST-2026-000901']))
            ->assertOk()
            ->assertSee('Clarisse');
    }
}
