<?php

namespace Tests\Feature;

use App\Livewire\Patients\PatientCreate;
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

        // Triage infirmier : motif + constantes avant le médecin
        $this->get(route('visites.triage', $visit))->assertOk();
        $this->post(route('visites.triage.store', $visit), [
            'motif_consultation' => 'Fièvre et céphalées depuis 3 jours',
            'temperature' => 39.2,
            'tension_systolique' => 120,
            'tension_diastolique' => 80,
        ])->assertRedirect(route('consultations.index'));

        $visit->refresh();
        $this->assertTrue($visit->estTriee());
        $this->assertSame(39.2, (float) $visit->temperature);

        // Consultation du médecin : formulaire classique (POST, sans JavaScript)
        $this->get(route('visites.consulter', $visit))->assertOk();

        $this->post(route('visites.consultation.store', $visit), [
            'histoire_maladie' => 'Fièvre depuis 3 jours, frissons.',
            'examen_general' => 'Patient fébrile, conscient.',
            'diagnostics' => [
                ['libelle' => 'Paludisme simple', 'code_cim10' => 'B50'],
            ],
            'conclusion' => 'Paludisme probable, bilan demandé.',
        ])->assertRedirect();

        $consultation = Consultation::where('visit_id', $visit->id)->firstOrFail();
        $this->assertSame('principal', $consultation->diagnostics[0]['type']);

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

        $this->post(route('prescriptions.store', $consultation), [
            'lignes' => [
                ['medicament_id' => $medicament->id, 'dose' => '4 comprimés', 'frequence' => '2 fois/jour', 'duree_jours' => 3, 'quantite_totale' => 24],
                ['medicament_id' => '', 'dose' => '', 'frequence' => '', 'duree_jours' => '', 'quantite_totale' => ''],
            ],
        ])->assertRedirect(route('consultations.show', $consultation));

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

    public function test_consultation_specialisee_tarif_usd_et_controle_gratuit_7_jours(): void
    {
        config(['dpi.taux_usd_cdf' => 2800]);

        $etab = Establishment::firstOrFail();
        $patient = Patient::create([
            'establishment_id' => $etab->id,
            'dossier_number' => 'TST-2026-000950',
            'nom' => 'TSHIBANGU',
            'prenom' => 'Alain',
            'sexe' => 'M',
            'type_prise_en_charge' => 'prive',
        ]);

        $ophtalmo = \App\Models\TypeConsultation::where('code', 'CONS-OPH')->firstOrFail();
        $this->assertSame(24.0, (float) $ophtalmo->prix_usd);

        // 1. Envoi en caisse : consultation spécialisée 24 $ → 67 200 CDF
        $this->post(route('patients.envoyer-caisse', $patient), [
            'type' => 'consultation_externe',
            'type_consultation_id' => $ophtalmo->id,
            'motif' => 'Baisse de vision',
        ])->assertRedirect();

        $visit = Visit::where('patient_id', $patient->id)->firstOrFail();
        $facture = $visit->factures()->firstOrFail();
        $this->assertSame(67200.0, (float) $facture->total_ttc);
        $this->assertStringContainsString('Ophtalmologie', $facture->lignes->first()->libelle);

        // Sans type de consultation, l'ambulatoire est refusé à l'accueil
        $patient2 = Patient::create([
            'establishment_id' => $etab->id,
            'dossier_number' => 'TST-2026-000951',
            'nom' => 'MUKENDI', 'prenom' => 'Rose', 'sexe' => 'F',
            'type_prise_en_charge' => 'prive',
        ]);
        $this->post(route('patients.envoyer-caisse', $patient2), [
            'type' => 'consultation_externe',
        ])->assertSessionHasErrors('type_consultation_id');

        // 2. Paiement + consultation réalisée
        $this->payer($facture);
        $this->post(route('visites.consultation.store', $visit->fresh()), [
            'diagnostics' => [['libelle' => 'Presbytie', 'code_cim10' => 'H52']],
        ])->assertRedirect();

        // 3. Retour à J+3 pour les résultats : GRATUIT, direct dans la file
        $visit->update(['statut' => 'termine', 'date_sortie' => now()]);

        $this->post(route('patients.envoyer-caisse', $patient), [
            'type' => 'consultation_externe',
            'type_consultation_id' => $ophtalmo->id,
            'motif' => 'Retour résultats',
        ])->assertRedirect(route('patients.show', $patient));

        $controle = Visit::where('patient_id', $patient->id)->orderByDesc('date_entree')->first();
        $this->assertTrue($controle->gratuite);
        $this->assertSame('en_cours', $controle->statut);
        $this->assertCount(0, $controle->factures);
        $this->assertTrue($controle->consultationPayee());

        // Le médecin peut consulter directement (suivi gratuit)
        $this->get(route('visites.consulter', $controle))->assertOk();
    }

    public function test_pages_metier_accessibles(): void
    {
        foreach ([
            'dashboard', 'patients.index', 'patients.create', 'consultations.index',
            'visites.index', 'labo.index', 'imagerie.index', 'bloc.index',
            'maternite.index', 'pharmacie.dashboard', 'pharmacie.stock',
            'pharmacie.prescriptions', 'pharmacie.medicaments', 'caisse.index',
            'equipements.index',
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
