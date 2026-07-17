<?php

namespace Tests\Feature;

use App\Livewire\Consultations\ConsultationCreate;
use App\Livewire\Patients\PatientCreate;
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

    /** Encaissement au guichet par le formulaire classique (POST). */
    protected function payer(Facture $facture): void
    {
        $this->post(route('caisse.encaisser', $facture), [
            'montant' => max(1, $facture->fresh()->soldeRestant()),
            'devise' => 'CDF',
            'mode_paiement' => 'especes',
        ])->assertSessionHas('success');
    }

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

        // ── 2. Accueil : le patient est envoyé à la caisse AVANT le médecin ──
        $this->post(route('patients.envoyer-caisse', $patient), [
            'type' => 'urgence',
            'motif' => 'Fièvre et céphalées depuis 3 jours',
        ])->assertRedirect();

        $visit = Visit::where('patient_id', $patient->id)->firstOrFail();
        $this->assertSame('urgence', $visit->type);
        $this->assertSame('en_attente', $visit->statut);

        $factureConsult = Facture::where('visit_id', $visit->id)->firstOrFail();
        $this->assertSame('emise', $factureConsult->statut);

        // Le médecin ne peut PAS consulter tant que la caisse n'a pas validé
        $this->get(route('visites.consulter', $visit))
            ->assertRedirect(route('consultations.index'));

        // ── 3. Caisse : paiement → la visite entre dans la file du médecin ──
        $this->payer($factureConsult);
        $this->assertSame('payee', $factureConsult->fresh()->statut);

        $visit->refresh();
        $this->assertSame('en_cours', $visit->statut);
        $this->assertTrue($visit->consultationPayee());

        // Le wizard est maintenant accessible et la consultation s'enregistre
        $this->get(route('visites.consulter', $visit))->assertOk();

        Livewire::test(ConsultationCreate::class, ['visit' => $visit])
            ->set('temperature', 39.2)
            ->set('tension_systolique', 120)
            ->set('tension_diastolique', 80)
            ->set('nouveau_diagnostic_libelle', 'Paludisme simple')
            ->set('nouveau_diagnostic_code', 'B50')
            ->call('ajouterDiagnostic')
            ->call('save')
            ->assertHasNoErrors();

        $consultation = Consultation::where('visit_id', $visit->id)->firstOrFail();

        // La page de la consultation s'affiche (régression : relation lignes_facture)
        $this->get(route('consultations.show', $consultation))->assertOk();

        // ── 4. Laboratoire (ambulatoire) : paiement exigé avant réalisation ──
        // NFS = panel CSK multi-paramètres (bornes selon sexe / âge), GE = examen simple
        $types = TypeExamen::whereIn('code', ['GE', 'NFS'])->pluck('id')->all();
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
        $this->assertNotNull($examen->numero_bon);
        $this->assertStringStartsWith('LAB-', $examen->numero_bon);
        // NFS (5 paramètres) + GE (1 ligne) = 6 lignes de résultats
        $this->assertCount(6, $examen->resultats);

        // Bon d'examen imprimable
        $this->get(route('labo.bon', $examen))->assertOk()->assertSee($examen->numero_bon);

        // Saisie interdite avant paiement
        $this->post(route('labo.resultats', $examen), ['resultats' => []])
            ->assertSessionHas('error');

        $this->payer($factureLabo);
        $this->assertSame('payee', $factureLabo->fresh()->statut);

        // Saisie des résultats puis validation du bilan (avec conclusion)
        $resultats = [];
        foreach ($examen->resultats as $resultat) {
            if ($resultat->typeExamen->code === 'GE') {
                $resultats[$resultat->id] = ['valeur_brute' => 'positif', 'valeur_numerique' => ''];
            } elseif ($resultat->parametre === 'Hémoglobine (Hb)') {
                $resultats[$resultat->id] = ['valeur_brute' => '10.5', 'valeur_numerique' => '10.5'];
            } else {
                // valeur dans la norme : borne minimale du paramètre
                $resultats[$resultat->id] = [
                    'valeur_brute' => (string) $resultat->valeur_reference_min,
                    'valeur_numerique' => (string) $resultat->valeur_reference_min,
                ];
            }
        }
        $this->post(route('labo.resultats', $examen), ['resultats' => $resultats])
            ->assertSessionHas('success');
        $this->post(route('labo.valider', $examen), ['conclusion' => 'Paludisme confirmé, anémie modérée.'])
            ->assertSessionHas('success');

        $examen->refresh();
        $this->assertSame('valide', $examen->statut);
        $this->assertSame('Paludisme confirmé, anémie modérée.', $examen->conclusion);

        // Hémoglobine 10.5 < 13 (homme adulte, bornes CSK) → « bas »
        $hb = $examen->resultats()->where('parametre', 'Hémoglobine (Hb)')->first();
        $this->assertSame('bas', $hb->interpretation);
        $this->assertSame(13.0, (float) $hb->valeur_reference_min);

        // GE positif
        $ge = $examen->resultats()->whereHas('typeExamen', fn ($q) => $q->where('code', 'GE'))->first();
        $this->assertSame('positif', $ge->interpretation);

        // Bulletin de résultats imprimable
        $this->get(route('labo.bulletin', $examen))->assertOk()->assertSee('Paludisme confirmé');

        // ── 5. Hospitalisation : service + lit ───────────────────────────────
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

        // ── 6. Pharmacie (hospitalisé) : servi à crédit, facturé ensuite ─────
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

        // Patient HOSPITALISÉ : servi à crédit — dispensation sans bon ni paiement
        $quantites = $prescription->lignes->mapWithKeys(fn ($l) => [$l->id => $l->quantite_totale])->all();
        $this->post(route('pharmacie.dispenser', $prescription), ['quantites' => $quantites])
            ->assertSessionHas('success');

        $this->assertSame('dispensee', $prescription->fresh()->statut);
        $this->assertSame($stockAvant - 24, (float) $medicament->stock->fresh()->quantite_disponible);

        // L'ordonnance dispensée reste facturable (règlement avant la sortie)
        $this->post(route('caisse.facturer', $prescription))->assertRedirect();
        $facturePharma = Facture::where('prescription_id', $prescription->id)->firstOrFail();
        $this->assertSame('emise', $facturePharma->statut);

        $this->payer($facturePharma);
        $this->assertSame('payee', $facturePharma->fresh()->statut);

        // ── 7. Sortie bloquée tant que le séjour n'est pas facturé/payé ─────
        $this->post(route('visites.facturer-sejour', $visit))->assertRedirect();
        $factureSejour = Facture::where('visit_id', $visit->id)
            ->where('statut', 'emise')->firstOrFail();

        $this->post(route('visites.sortir', $visit), ['mode_sortie' => 'gueri'])
            ->assertSessionHas('error');
        $this->assertSame('en_cours', $visit->fresh()->statut);

        $this->payer($factureSejour);

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
