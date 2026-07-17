<?php
namespace App\Services;

use App\Models\ActeClinique;
use App\Models\Assurance;
use App\Models\ExamenLaboratoire;
use App\Models\BonSortie;
use App\Models\Facture;
use App\Models\FactureTiersPayant;
use App\Models\LigneFacture;
use App\Models\Patient;
use App\Models\PatientAssurance;
use App\Models\Prescription;
use App\Models\Visit;
use Illuminate\Support\Facades\DB;

class FacturationService
{
    /**
     * Créer une facture pour un acte ambulatoire
     */
    public function creerFactureAmbulatoire(
        Patient $patient,
        Visit $visit,
        string $type,
        string $libelle,
        float $montant,
        string $devise = 'CDF',
        ?string $referenceId = null,
        string $referenceType = 'consultation'
    ): Facture {
        return DB::transaction(function () use (
            $patient, $visit, $type, $libelle,
            $montant, $devise, $referenceId, $referenceType
        ) {
            $numero = $this->genererNumeroFacture();

            $facture = Facture::create([
                'patient_id' => $patient->id,
                'visit_id' => $visit->id,
                'establishment_id' => $visit->establishment_id,
                'numero_facture' => $numero,
                'date_facture' => now(),
                'statut' => 'emise',
                'type_prise_en_charge' => $patient->type_prise_en_charge,
                'total_ht' => $montant,
                'total_ttc' => $montant,
                'patient_part' => $montant,
                'assurance_part' => 0,
            ]);

            $ligne = LigneFacture::create([
                'facture_id' => $facture->id,
                'type' => $type,
                'libelle' => $libelle,
                'reference_id' => $referenceId,
                'quantite' => 1,
                'prix_unitaire' => $montant,
                'total_ligne' => $montant,
            ]);

            // Calcul tiers payant si assurance — puis report sur les totaux
            // de la facture (part assurance / part patient au guichet)
            if ($patient->type_prise_en_charge === 'assurance') {
                $this->appliquerTiersPayant($facture, $ligne, $patient, $devise);

                $facture->refresh();
                $totalAssurance = (float) $facture->lignesTiersPayant->sum('part_assurance');
                if ($totalAssurance > 0) {
                    $facture->update([
                        'assurance_part' => $totalAssurance,
                        'patient_part' => $montant - $totalAssurance,
                    ]);
                }
            }

            return $facture->fresh();
        });
    }

    /**
     * Créer une facture pour une prescription
     */
    public function creerFacturePrescription(
        Prescription $prescription,
        string $devise = 'CDF'
    ): Facture {
        return DB::transaction(function () use ($prescription, $devise) {
            $prescription->load(['lignes.medicament.stock', 'patient', 'consultation.visit']);
            $patient = $prescription->patient;
            $visit = $prescription->consultation->visit;

            $totalMontant = $prescription->lignes->sum(function ($ligne) {
                return ($ligne->medicament->stock?->prix_unitaire_vente ?? 0) * $ligne->quantite_totale;
            });

            $facture = Facture::create([
                'patient_id' => $patient->id,
                'visit_id' => $visit->id,
                'prescription_id' => $prescription->id,
                'establishment_id' => $visit->establishment_id,
                'numero_facture' => $this->genererNumeroFacture(),
                'date_facture' => now(),
                'statut' => 'emise',
                'type_prise_en_charge' => $patient->type_prise_en_charge,
                'total_ht' => $totalMontant,
                'total_ttc' => $totalMontant,
                'patient_part' => $totalMontant,
                'assurance_part' => 0,
            ]);

            foreach ($prescription->lignes as $ligne) {
                $prixTotal = ($ligne->medicament->stock?->prix_unitaire_vente ?? 0) * $ligne->quantite_totale;
                $ligneFacture = LigneFacture::create([
                    'facture_id' => $facture->id,
                    'type' => 'medicament',
                    'libelle' => $ligne->medicament->denomination_commune . ' ' . $ligne->medicament->dosage,
                    'reference_id' => $ligne->medicament_id,
                    'quantite' => $ligne->quantite_totale,
                    'prix_unitaire' => $ligne->medicament->stock?->prix_unitaire_vente ?? 0,
                    'total_ligne' => $prixTotal,
                ]);

                if ($patient->type_prise_en_charge === 'assurance') {
                    $this->appliquerTiersPayant($facture, $ligneFacture, $patient, $devise, 'medicament', $ligne->medicament_id);
                }
            }

            // Mettre à jour les totaux de la facture
            $facture->refresh();
            $totalAssurance = $facture->lignesTiersPayant->sum('part_assurance');
            $facture->update([
                'assurance_part' => $totalAssurance,
                'patient_part' => $totalMontant - $totalAssurance,
            ]);

            $prescription->update(['statut' => 'en_attente_paiement']);

            return $facture->fresh();
        });
    }

    /**
     * Appliquer le tiers payant sur une ligne de facture
     */
    protected function appliquerTiersPayant(
        Facture $facture,
        LigneFacture $ligne,
        Patient $patient,
        string $devise = 'CDF',
        string $typeActe = 'consultation',
        ?string $referenceId = null
    ): void {
        $patientAssurance = $this->resolvePatientAssurance($patient);

        if (!$patientAssurance || !$patientAssurance->assurance->est_actif) return;

        $assurance = $patientAssurance->assurance;

        // Vérification 1 : acte couvert ?
        $acteCouvert = $assurance->couvreActe($typeActe, $referenceId);

        // Vérification 2 : plafond atteint ?
        $plafondAtteint = $patientAssurance->plafondAtteint($devise);

        // Calcul
        $taux = 0;
        $partAssurance = 0;
        $partPatient = $ligne->total_ligne;

        if ($acteCouvert && !$plafondAtteint) {
            $taux = $assurance->tauxPourActe($typeActe, $referenceId);

            // Vérifier si le plafond permet de couvrir entièrement
            $resteDisponible = $patientAssurance->resteDisponible($devise);
            $montantCouvert = ($ligne->total_ligne * $taux) / 100;

            if ($montantCouvert > $resteDisponible) {
                $montantCouvert = $resteDisponible;
                $plafondAtteint = true;
            }

            $partAssurance = $montantCouvert;
            $partPatient = $ligne->total_ligne - $partAssurance;

            // Mettre à jour la consommation annuelle
            if ($devise === 'USD') {
                $patientAssurance->increment('consomme_annuel_usd', $partAssurance);
            } else {
                $patientAssurance->increment('consomme_annuel_cdf', $partAssurance);
            }
        }

        // Enregistrer le détail
        \App\Models\FactureTiersPayant::create([
            'facture_id' => $facture->id,
            'ligne_facture_id' => $ligne->id,
            'assurance_id' => $assurance->id,
            'acte_couvert' => $acteCouvert,
            'taux_applique' => $taux,
            'montant_acte' => $ligne->total_ligne,
            'part_assurance' => $partAssurance,
            'part_patient' => $partPatient,
            'plafond_atteint' => $plafondAtteint,
            'devise' => $devise,
        ]);
    }

    /**
     * Lien patient ↔ assurance pour le tiers payant.
     *
     * Si aucun lien actif n'existe mais que le patient a été enregistré avec
     * un nom d'assurance, l'assureur est créé (taux par défaut 80 %) et le
     * lien établi automatiquement — sinon la part patient reste à 100 %.
     */
    public function resolvePatientAssurance(Patient $patient): ?PatientAssurance
    {
        $lien = PatientAssurance::where('patient_id', $patient->id)
            ->where('est_actif', true)
            ->with('assurance')
            ->first();

        if ($lien) {
            return $lien;
        }

        if ($patient->type_prise_en_charge !== 'assurance' || blank($patient->assurance_nom)) {
            return null;
        }

        $assurance = Assurance::firstOrCreate(
            ['code' => strtoupper(\Illuminate\Support\Str::slug($patient->assurance_nom, '_')) ?: 'ASSURANCE'],
            ['nom' => $patient->assurance_nom, 'taux_couverture' => 80, 'est_actif' => true]
        );

        return PatientAssurance::create([
            'patient_id' => $patient->id,
            'assurance_id' => $assurance->id,
            'numero_police' => $patient->assurance_numero ?: 'N/A',
            'nom_beneficiaire' => trim($patient->nom . ' ' . $patient->prenom),
            'date_debut' => now()->toDateString(),
            'annee_courante' => (int) now()->format('Y'),
            'est_actif' => true,
        ])->load('assurance');
    }

    /**
     * Facture consultation / urgence au guichet
     */
    public function creerFactureConsultation(Visit $visit): Facture
    {
        $visit->load(['patient', 'typeConsultation']);
        $tarifs = config('dpi.tarifs_cdf', []);

        if ($visit->type !== 'urgence' && $visit->typeConsultation) {
            // Tarif du type choisi à l'accueil : générale 20 $, spécialisée 24 $
            $tc = $visit->typeConsultation;
            $montant = $tc->prixCdf();
            $libelle = 'Consultation ' . $tc->libelle . ' (' . ($tc->prix_usd + 0) . ' $)';
        } else {
            $montant = match ($visit->type) {
                'urgence' => $tarifs['urgence'] ?? 25000,
                default => $tarifs['consultation_externe'] ?? 15000,
            };
            $libelle = $visit->type === 'urgence' ? 'Consultation urgences' : 'Consultation ambulatoire';
        }

        $facture = $this->creerFactureAmbulatoire(
            $visit->patient,
            $visit,
            'consultation',
            $libelle,
            (float) $montant,
            'CDF',
            $visit->id
        );

        $visit->update([
            'tarif_consultation' => $montant,
            'est_payant' => true,
        ]);

        return $facture;
    }

    /**
     * Facture examens labo ou imagerie
     */
    public function creerFactureExamen(ExamenLaboratoire $examen): Facture
    {
        return DB::transaction(function () use ($examen) {
            $examen->load(['patient', 'visit', 'resultats.typeExamen']);
            $visit = $examen->visit;
            $patient = $examen->patient;

            $lignes = [];
            foreach ($examen->resultats as $resultat) {
                $type = $resultat->typeExamen;
                $prix = (float) ($type->prix ?? 0);
                $lignes[] = [
                    'type' => $examen->domaine === 'imagerie' ? 'imagerie' : 'examen_labo',
                    'libelle' => $type->libelle,
                    'reference_id' => $type->id,
                    'prix' => $prix,
                ];
            }

            $total = array_sum(array_column($lignes, 'prix'));
            $numero = $this->genererNumeroFacture();

            $facture = Facture::create([
                'patient_id' => $patient->id,
                'visit_id' => $visit?->id,
                'establishment_id' => $visit?->establishment_id ?? auth()->user()->establishment_id,
                'numero_facture' => $numero,
                'date_facture' => now(),
                'statut' => 'emise',
                'type_prise_en_charge' => $patient->type_prise_en_charge,
                'total_ht' => $total,
                'total_ttc' => $total,
                'patient_part' => $total,
                'assurance_part' => 0,
            ]);

            foreach ($lignes as $ligne) {
                $ligneFacture = LigneFacture::create([
                    'facture_id' => $facture->id,
                    'type' => $ligne['type'],
                    'libelle' => $ligne['libelle'],
                    'reference_id' => $ligne['reference_id'],
                    'quantite' => 1,
                    'prix_unitaire' => $ligne['prix'],
                    'total_ligne' => $ligne['prix'],
                ]);

                if ($patient->type_prise_en_charge === 'assurance') {
                    $this->appliquerTiersPayant($facture, $ligneFacture, $patient, 'CDF', $ligne['type'], $ligne['reference_id']);
                }
            }

            // Report du tiers payant sur les totaux de la facture
            if ($patient->type_prise_en_charge === 'assurance') {
                $facture->refresh();
                $totalAssurance = (float) $facture->lignesTiersPayant->sum('part_assurance');
                if ($totalAssurance > 0) {
                    $facture->update([
                        'assurance_part' => $totalAssurance,
                        'patient_part' => $total - $totalAssurance,
                    ]);
                }
            }

            $examen->update(['facture_id' => $facture->id]);

            return $facture->fresh();
        });
    }

    /**
     * Facture séjour hospitalisation (nuits)
     */
    public function creerFactureHospitalisation(Visit $visit): Facture
    {
        return DB::transaction(function () use ($visit) {
            $visit->load('patient');
            $tarifJour = config('dpi.tarifs_cdf.hospitalisation_jour', 35000);
            $jours = $visit->joursHospitalisation();
            $total = $tarifJour * $jours;

            $facture = $this->creerFactureAmbulatoire(
                $visit->patient,
                $visit,
                'hospitalisation',
                "Hospitalisation ({$jours} jour(s))",
                (float) $total,
                'CDF',
                $visit->id
            );

            ActeClinique::create([
                'visit_id' => $visit->id,
                'patient_id' => $visit->patient_id,
                'prescripteur_id' => auth()->id(),
                'domaine' => 'hospitalisation',
                'libelle' => "Séjour hospitalisation — {$jours} jour(s)",
                'prix' => $tarifJour,
                'quantite' => $jours,
                'statut' => 'facture',
                'facture_id' => $facture->id,
                'date_realisation' => now(),
            ]);

            return $facture;
        });
    }

    /**
     * Facture acte chirurgical ou maternité
     */
    public function creerFactureActeClinique(ActeClinique $acte): Facture
    {
        return DB::transaction(function () use ($acte) {
            $acte->load(['patient', 'visit']);
            $typeLigne = $acte->domaine === 'chirurgie' ? 'acte_chirurgical' : 'autre';

            $facture = $this->creerFactureAmbulatoire(
                $acte->patient,
                $acte->visit,
                $typeLigne,
                $acte->libelle,
                $acte->montantTotal(),
                'CDF',
                $acte->id
            );

            $acte->update(['statut' => 'facture', 'facture_id' => $facture->id]);

            return $facture;
        });
    }

    /**
     * Valider le paiement et émettre le bon de sortie
     */
    public function validerPaiement(
        Facture $facture,
        float $montantRecu,
        string $devise,
        string $modePaiement,
        ?string $reference = null,
        ?Prescription $prescription = null,
        ?ExamenLaboratoire $examen = null
    ): array {
        return DB::transaction(function () use (
            $facture, $montantRecu, $devise,
            $modePaiement, $reference, $prescription, $examen
        ) {
            // Enregistrer le paiement
            \App\Models\Paiement::create([
                'facture_id' => $facture->id,
                'caissier_id' => auth()->id(),
                'date_paiement' => now(),
                'montant' => $montantRecu,
                'mode_paiement' => $modePaiement,
                'reference_paiement' => $reference,
                'recu_numero' => 'REC-' . now()->format('YmdHis'),
            ]);

            $facture->update(['statut' => 'payee']);

            // Générer bon de sortie pharmacie ou labo/imagerie
            $bonSortie = null;
            if ($prescription) {
                $bonSortie = BonSortie::create([
                    'numero' => BonSortie::genererNumero(),
                    'facture_id' => $facture->id,
                    'patient_id' => $facture->patient_id,
                    'emis_par' => auth()->id(),
                    'type' => 'pharmacie',
                    'statut' => 'emis',
                    'prescription_id' => $prescription->id,
                    'expire_at' => now()->addHours(24),
                ]);

                $prescription->update(['statut' => 'en_attente']);
            } elseif ($examen) {
                $bonSortie = BonSortie::create([
                    'numero' => BonSortie::genererNumero(),
                    'facture_id' => $facture->id,
                    'patient_id' => $facture->patient_id,
                    'emis_par' => auth()->id(),
                    'type' => $examen->domaine === 'imagerie' ? 'imagerie' : 'labo',
                    'statut' => 'emis',
                    'examen_id' => $examen->id,
                    'expire_at' => now()->addHours(48),
                ]);
            }

            // Workflow accueil : le patient paie la consultation AVANT de voir
            // le médecin. Le paiement débloque la visite → file d'attente médecin.
            $facture->loadMissing('lignes', 'visit');
            if ($facture->visit
                && $facture->visit->statut === 'en_attente'
                && $facture->lignes->contains(fn ($l) => $l->type === 'consultation')) {
                $facture->visit->update(['statut' => 'en_cours']);
            }

            return [
                'facture' => $facture->fresh(),
                'bon_sortie' => $bonSortie,
            ];
        });
    }

    protected function genererNumeroFacture(): string
    {
        $prefix = 'FAC-' . now()->format('Y') . '-';
        $last = Facture::where('numero_facture', 'like', $prefix . '%')
            ->orderByDesc('numero_facture')
            ->value('numero_facture');
        $seq = $last ? (int) substr($last, -6) + 1 : 1;
        return $prefix . str_pad($seq, 6, '0', STR_PAD_LEFT);
    }
}