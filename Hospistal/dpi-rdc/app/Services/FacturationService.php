<?php

namespace App\Services;

use App\Models\ActeClinique;
use App\Models\Assurance;
use App\Models\BonPriseEnCharge;
use App\Models\BonSortie;
use App\Models\ExamenLaboratoire;
use App\Models\Facture;
use App\Models\FactureTiersPayant;
use App\Models\LigneFacture;
use App\Models\Paiement;
use App\Models\Patient;
use App\Models\PatientAssurance;
use App\Models\Prescription;
use App\Models\PrescriptionDiete;
use App\Models\Visit;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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
            $montant, $devise, $referenceId
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
                ...$this->empreinteDevise($devise),
                ...$this->identiteAssureur($patient),
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
            $prescription->load(['lignes.medicament.stocks', 'patient', 'consultation.visit', 'officine']);
            $patient = $prescription->patient;
            $visit = $prescription->consultation->visit;

            // Un produit acheté à l'extérieur n'entre pas dans la facturation
            // interne : l'hôpital ne l'a ni en stock ni à délivrer.
            $lignes = $prescription->lignes->where('est_externe', false);

            // Le prix de l'officine, ou celui que la convention du patient a
            // négocié pour ce produit.
            $prixUnitaire = fn ($ligne) => $this->prixConvention(
                $patient, 'medicament', $ligne->medicament_id,
                $this->prixOfficine($ligne, $prescription->officine_id), false
            );

            // On facture ce qui sortira du tiroir : quinze comprimés se
            // servent en deux plaquettes de dix, soit vingt comprimés.
            $totalMontant = $lignes->sum(fn ($ligne) => $prixUnitaire($ligne) * $ligne->quantiteADelivrer());

            $facture = Facture::create([
                'patient_id' => $patient->id,
                'visit_id' => $visit->id,
                'prescription_id' => $prescription->id,
                'establishment_id' => $visit->establishment_id,
                'numero_facture' => $this->genererNumeroFacture(),
                'date_facture' => now(),
                'statut' => 'emise',
                'type_prise_en_charge' => $patient->type_prise_en_charge,
                ...$this->empreinteDevise($devise),
                ...$this->identiteAssureur($patient),
                'total_ht' => $totalMontant,
                'total_ttc' => $totalMontant,
                'patient_part' => $totalMontant,
                'assurance_part' => 0,
            ]);

            foreach ($lignes as $ligne) {
                $prix = $prixUnitaire($ligne);
                $quantite = $ligne->quantiteADelivrer();
                $conditionnement = $ligne->libelleConditionnement();

                $ligneFacture = LigneFacture::create([
                    'facture_id' => $facture->id,
                    'type' => 'medicament',
                    'libelle' => $ligne->medicament->designation()
                        .($conditionnement ? ' — '.$conditionnement : ''),
                    'reference_id' => $ligne->medicament_id,
                    'quantite' => $quantite,
                    'prix_unitaire' => $prix,
                    'total_ligne' => $prix * $quantite,
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
     * Prix unitaire du produit à l'officine qui servira l'ordonnance.
     *
     * Chaque officine tient son propre stock et son propre prix ; à défaut
     * d'y trouver le produit, on retient le prix de délivrance courant.
     */
    protected function prixOfficine($ligne, ?string $officineId): float
    {
        $stocks = $ligne->medicament?->stocks;

        if (! $stocks) {
            return 0.0;
        }

        $stock = $officineId
            ? $stocks->firstWhere('officine_id', $officineId)
            : null;

        return (float) (($stock ?? $ligne->medicament->stock)?->prix_unitaire_vente ?? 0);
    }

    /**
     * Devise de la facture et taux figé à l'émission.
     *
     * Les tarifs de l'établissement sont en francs congolais ; une facture
     * libellée dans une autre devise conserve le taux du jour, pour que sa
     * contre-valeur ne bouge plus après coup.
     *
     * @return array<string, mixed>
     */
    protected function empreinteDevise(string $devise): array
    {
        $devises = app(DeviseService::class);
        $devise = $devises->existe($devise) ? $devise : $devises->pivot();

        return ['devise' => $devise, 'taux_change' => $devises->taux($devise)];
    }

    /**
     * Identité de l'assureur à figer sur la facture.
     *
     * Une facture est une pièce comptable : elle doit porter le nom de la
     * société ou de la mutuelle tel qu'il était à l'émission, et non le mot
     * « assurance ». Un changement d'assureur plus tard ne réécrit pas les
     * factures déjà remises au patient.
     *
     * @return array<string, ?string>
     */
    protected function identiteAssureur(Patient $patient): array
    {
        if ($patient->type_prise_en_charge !== 'assurance') {
            return [];
        }

        $contexte = $this->priseEnCharge($patient);
        $lien = $contexte['lien'];

        return [
            'assurance_nom' => $lien?->assurance?->nom ?: $patient->assurance_nom,
            'assurance_numero' => $lien?->numero_police ?: $patient->assurance_numero,
            'bon_prise_en_charge_id' => $contexte['bon']?->id,
        ];
    }

    /**
     * Le tiers payant s'applique-t-il à ce patient, à cette date ?
     *
     * Il faut un contrat lié au dossier, une convention en cours, un
     * bénéficiaire encore couvert et — si la convention l'exige — un bon de
     * prise en charge valide. Quand il manque quelque chose, le motif le dit
     * en clair : c'est ce que le guichet lit pour savoir quoi demander.
     *
     * @return array{lien: ?PatientAssurance, bon: ?BonPriseEnCharge, motif: ?string}
     */
    public function priseEnCharge(Patient $patient, ?Carbon $date = null): array
    {
        $date ??= now();
        $vide = ['lien' => null, 'bon' => null, 'motif' => null];

        if ($patient->type_prise_en_charge !== 'assurance') {
            return $vide;
        }

        $lien = $this->resolvePatientAssurance($patient);

        if (! $lien || ! $lien->assurance) {
            return [...$vide, 'motif' => 'aucune assurance n\'est liée au dossier du patient'];
        }

        $assurance = $lien->assurance;

        if ($motif = $assurance->horsContrat($date) ?? $lien->horsCouverture($date)) {
            return [...$vide, 'lien' => $lien, 'motif' => $motif];
        }

        if (! $assurance->exige_bon_prise_en_charge) {
            return [...$vide, 'lien' => $lien];
        }

        $bon = BonPriseEnCharge::where('patient_id', $patient->id)
            ->where('assurance_id', $assurance->id)
            ->validesLe($date)
            ->get()
            ->first(fn (BonPriseEnCharge $b) => $b->estUtilisableLe($date));

        if (! $bon) {
            return [...$vide, 'lien' => $lien, 'motif' => 'la convention « '.$assurance->nom
                .' » exige un bon de prise en charge, et aucun bon valide n\'est enregistré pour ce patient'];
        }

        return ['lien' => $lien, 'bon' => $bon, 'motif' => null];
    }

    /**
     * Prix d'une prestation pour ce patient : celui que sa convention a
     * négocié quand le tiers payant s'applique, le prix public sinon.
     *
     * $prixDeCategorie autorise un prix fixé pour toute la catégorie — il a
     * un sens pour la journée d'hospitalisation, pas pour un examen.
     */
    public function prixConvention(
        Patient $patient,
        string $type,
        ?string $referenceId,
        float $prixPublic,
        bool $prixDeCategorie = true
    ): float {
        if ($patient->type_prise_en_charge !== 'assurance') {
            return $prixPublic;
        }

        $contexte = $this->priseEnCharge($patient);

        if ($contexte['motif'] !== null || ! $contexte['lien']) {
            return $prixPublic;
        }

        return $contexte['lien']->assurance->prixPour($type, $referenceId, $prixPublic, $prixDeCategorie);
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
        // Contrat échu, bénéficiaire radié, bon manquant : pas de tiers
        // payant. Le patient paie tout, et la caisse lit pourquoi.
        $contexte = $this->priseEnCharge($patient);

        if ($contexte['motif'] !== null || ! $contexte['lien']) {
            return;
        }

        $patientAssurance = $contexte['lien'];
        $bon = $contexte['bon'];
        $assurance = $patientAssurance->assurance;

        // Vérification 1 : acte couvert ?
        $acteCouvert = $assurance->couvreActe($typeActe, $referenceId);

        // Vérification 2 : plafond atteint ?
        $plafondAtteint = $patientAssurance->plafondAtteint($devise);

        // Calcul
        $taux = 0;
        $partAssurance = 0;
        $partPatient = $ligne->total_ligne;

        if ($acteCouvert && ! $plafondAtteint) {
            $taux = $assurance->tauxPourActe($typeActe, $referenceId);

            // Vérifier si le plafond permet de couvrir entièrement
            $resteDisponible = $patientAssurance->resteDisponible($devise);
            $montantCouvert = ($ligne->total_ligne * $taux) / 100;

            if ($montantCouvert > $resteDisponible) {
                $montantCouvert = $resteDisponible;
                $plafondAtteint = true;
            }

            // Le bon fixe parfois un montant : au-delà, l'employeur ne paie pas.
            $resteBon = $bon?->resteAutorise();

            if ($resteBon !== null) {
                $resteBon = app(DeviseService::class)->convertir($resteBon, $bon->devise ?: 'CDF', $devise);

                if ($montantCouvert > $resteBon) {
                    $montantCouvert = $resteBon;
                    $plafondAtteint = true;
                }
            }

            $partAssurance = $montantCouvert;
            $partPatient = $ligne->total_ligne - $partAssurance;

            // Mettre à jour la consommation annuelle
            if ($devise === 'USD') {
                $patientAssurance->increment('consomme_annuel_usd', $partAssurance);
            } else {
                $patientAssurance->increment('consomme_annuel_cdf', $partAssurance);
            }

            if ($bon && $partAssurance > 0) {
                $bon->increment('montant_consomme',
                    app(DeviseService::class)->convertir($partAssurance, $devise, $bon->devise ?: 'CDF'));
            }
        }

        // Enregistrer le détail
        FactureTiersPayant::create([
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
            ['code' => strtoupper(Str::slug($patient->assurance_nom, '_')) ?: 'ASSURANCE'],
            ['nom' => $patient->assurance_nom, 'taux_couverture' => 80, 'est_actif' => true]
        );

        return PatientAssurance::create([
            'patient_id' => $patient->id,
            'assurance_id' => $assurance->id,
            'numero_police' => $patient->assurance_numero ?: 'N/A',
            'nom_beneficiaire' => trim($patient->nom.' '.$patient->prenom),
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
        // Une consultation ne se facture qu'une fois : réémettre renvoie la
        // facture existante plutôt que d'en créer une seconde.
        $existante = Facture::where('visit_id', $visit->id)
            ->whereHas('lignes', fn ($q) => $q->where('type', 'consultation'))
            ->whereIn('statut', ['emise', 'partiellement_payee', 'payee'])
            ->first();

        if ($existante) {
            return $existante;
        }

        $visit->load(['patient', 'typeConsultation']);
        $tarifs = config('dpi.tarifs_cdf', []);

        if ($visit->type !== 'urgence' && $visit->typeConsultation) {
            // Tarif du type choisi à l'accueil : générale 20 $, spécialisée 24 $
            $tc = $visit->typeConsultation;
            $montant = $tc->prixCdf();
            $libelle = 'Consultation '.$tc->libelle.' ('.($tc->prix_usd + 0).' $)';
        } else {
            $montant = match ($visit->type) {
                'urgence' => $tarifs['urgence'] ?? 25000,
                default => $tarifs['consultation_externe'] ?? 15000,
            };
            $libelle = $visit->type === 'urgence' ? 'Consultation urgences' : 'Consultation ambulatoire';
        }

        // Le tarif de la convention remplace le prix public : le libellé ne
        // doit plus annoncer un prix en dollars qui n'est pas celui facturé.
        $prix = $this->prixConvention(
            $visit->patient, 'consultation',
            $visit->type !== 'urgence' ? $visit->typeConsultation?->id : null,
            (float) $montant
        );

        if (abs($prix - (float) $montant) > 0.009) {
            $montant = $prix;
            $libelle = preg_replace('/\s*\([^)]*\$\)$/u', '', $libelle).' — tarif convention';
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
    public function creerFactureExamen(ExamenLaboratoire $examen, string $devise = 'CDF'): Facture
    {
        // Un bon d'examen déjà facturé le reste : on renvoie sa facture.
        if ($examen->facture_id && ($deja = Facture::find($examen->facture_id))) {
            return $deja;
        }

        return DB::transaction(function () use ($examen, $devise) {
            $examen->load(['patient', 'visit', 'resultats.typeExamen']);
            $visit = $examen->visit;
            $patient = $examen->patient;

            // Une ligne par type d'examen. Un panel prescrit partiellement est
            // facturé au prorata : prix du panel x (sous-examens retenus / total).
            $lignes = [];
            foreach ($examen->resultats->groupBy('type_examen_id') as $resultats) {
                $type = $resultats->first()->typeExamen;
                $totalParametres = count($type->valeurs_reference['parametres'] ?? []);
                $retenus = $resultats->count();
                $partiel = $totalParametres > 1 && $retenus < $totalParametres;

                $typeLigne = $examen->domaine === 'imagerie' ? 'imagerie' : 'examen_labo';
                $prixPanel = $this->prixConvention($patient, $typeLigne, $type->id, (float) ($type->prix ?? 0), false);

                $prix = $partiel
                    ? round($prixPanel * $retenus / $totalParametres, 2)
                    : $prixPanel;

                $lignes[] = [
                    'type' => $typeLigne,
                    'libelle' => $type->libelle.($partiel ? " ({$retenus}/{$totalParametres} sous-examens)" : ''),
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
                ...$this->empreinteDevise($devise),
                ...$this->identiteAssureur($patient),
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
     * Facture du séjour : les journées d'hospitalisation, puis une ligne par
     * diète servie. La nutrition hospitalière est une prestation facturée à
     * part, pas un forfait noyé dans le prix du lit — le patient voit ce
     * qu'il a mangé, la cuisine retrouve ses journées.
     */
    public function creerFactureHospitalisation(Visit $visit): ?Facture
    {
        return DB::transaction(function () use ($visit) {
            $visit->refresh()->load('patient');
            $tarifJour = $this->prixConvention(
                $visit->patient, 'hospitalisation', null,
                (float) config('dpi.tarifs_cdf.hospitalisation_jour', 35000)
            );

            // Seules les journées écoulées depuis la dernière facture sont
            // réclamées : réémettre une facture ne refacture pas le séjour.
            $joursTotal = $visit->joursHospitalisation();
            $joursDus = max(0, $joursTotal - (int) $visit->jours_factures);

            $lignes = [];

            if ($joursDus > 0) {
                $lignes[] = [
                    'type' => 'hospitalisation',
                    'libelle' => $visit->jours_factures > 0
                        ? "Hospitalisation ({$joursDus} jour(s) — du J".((int) $visit->jours_factures + 1)." au J{$joursTotal})"
                        : "Hospitalisation ({$joursDus} jour(s))",
                    'reference_id' => $visit->id,
                    'quantite' => $joursDus,
                    'prix_unitaire' => (float) $tarifJour,
                ];
            }

            $dietes = $this->dietesAFacturer($visit);

            foreach ($dietes as $prescription) {
                $lignes[] = [
                    'type' => 'diete',
                    'libelle' => $prescription->typeDiete->libelle
                        .' ('.$prescription->joursServis().' jour(s))',
                    'reference_id' => $prescription->id,
                    'quantite' => $prescription->joursServis(),
                    'prix_unitaire' => $this->prixConvention(
                        $visit->patient, 'diete', $prescription->type_diete_id,
                        (float) $prescription->typeDiete->prix_journalier
                    ),
                ];
            }

            // Plus rien de neuf : on n'émet pas une facture vide, qui
            // encombrerait le guichet et fausserait les statistiques.
            if ($lignes === []) {
                return null;
            }

            // Ce qu'un forfait couvre déjà ne se facture pas une seconde fois.
            $forfaits = app(ForfaitService::class);
            $tri = $forfaits->filtrerLignes($visit, $lignes);

            $facture = $this->creerFactureLignes(
                $visit->patient,
                $visit,
                $tri['lignes'],
                'CDF',
                $tri['couvertes']
            );

            // Les diètes couvertes par le forfait sont marquées facturées
            // elles aussi : elles ont bien été payées, dans le forfait.
            foreach ($dietes as $prescription) {
                $prescription->update([
                    'facture_id' => $facture->id,
                    'jours_factures' => $prescription->joursServis(),
                ]);
            }

            $visit->update(['jours_factures' => $joursTotal]);

            app(AcompteService::class)->imputer($facture);

            if ($joursDus > 0) {
                ActeClinique::create([
                    'visit_id' => $visit->id,
                    'patient_id' => $visit->patient_id,
                    'prescripteur_id' => auth()->id(),
                    'domaine' => 'hospitalisation',
                    'libelle' => "Séjour hospitalisation — {$joursDus} jour(s)",
                    'prix' => $tarifJour,
                    'quantite' => $joursDus,
                    'statut' => 'facture',
                    'facture_id' => $facture->id,
                    'date_realisation' => now(),
                ]);
            }

            return $facture;
        });
    }

    /**
     * Reste-t-il quelque chose à facturer sur ce séjour ?
     * Sert au guichet pour ne pas proposer une émission qui ne produirait
     * qu'une facture vide.
     */
    public function resteAFacturer(Visit $visit): bool
    {
        $joursDus = $visit->joursHospitalisation() - (int) $visit->jours_factures;

        if ($joursDus > 0) {
            return true;
        }

        return $visit->prescriptionsDiete()
            ->whereNull('facture_id')
            ->whereHas('typeDiete', fn ($q) => $q->where('prix_journalier', '>', 0))
            ->exists();
    }

    /**
     * Diètes du séjour restant à facturer. Celle encore en cours est clôturée
     * au jour de la facturation : on ne facture jamais une journée à venir.
     *
     * @return Collection<int, PrescriptionDiete>
     */
    protected function dietesAFacturer(Visit $visit): Collection
    {
        return $visit->prescriptionsDiete()
            ->with('typeDiete')
            ->whereNull('facture_id')
            ->orderBy('debut')
            ->get()
            ->each(function ($prescription) {
                if ($prescription->fin === null) {
                    $prescription->update(['fin' => now()->toDateString()]);
                    $prescription->refresh();
                }
            })
            // Une mise à jeun ne coûte rien : inutile d'alourdir la facture.
            ->filter(fn ($prescription) => (float) $prescription->typeDiete->prix_journalier > 0)
            ->values();
    }

    /**
     * Facture à plusieurs lignes, chacune passant par le tiers payant selon
     * sa nature (le séjour et la diète ne sont pas couverts au même taux).
     *
     * @param  array<int, array{type: string, libelle: string, reference_id: ?string, quantite: float|int, prix_unitaire: float}>  $lignes
     */
    protected function creerFactureLignes(
        Patient $patient,
        Visit $visit,
        array $lignes,
        string $devise = 'CDF',
        array $couvertesParForfait = []
    ): Facture {
        return DB::transaction(function () use ($patient, $visit, $lignes, $devise, $couvertesParForfait) {
            $total = array_sum(array_map(
                fn ($l) => $l['quantite'] * $l['prix_unitaire'],
                $lignes
            ));

            $facture = Facture::create([
                'patient_id' => $patient->id,
                'visit_id' => $visit->id,
                'establishment_id' => $visit->establishment_id,
                'numero_facture' => $this->genererNumeroFacture(),
                'date_facture' => now(),
                'statut' => 'emise',
                'type_prise_en_charge' => $patient->type_prise_en_charge,
                ...$this->empreinteDevise($devise),
                ...$this->identiteAssureur($patient),
                'total_ht' => $total,
                'total_ttc' => $total,
                'patient_part' => $total,
                'assurance_part' => 0,
            ]);

            foreach ($lignes as $ligne) {
                $totalLigne = $ligne['quantite'] * $ligne['prix_unitaire'];

                $modele = LigneFacture::create([
                    'facture_id' => $facture->id,
                    'type' => $ligne['type'],
                    'libelle' => $ligne['libelle'],
                    'reference_id' => $ligne['reference_id'] ?? null,
                    'quantite' => $ligne['quantite'],
                    'prix_unitaire' => $ligne['prix_unitaire'],
                    'total_ligne' => $totalLigne,
                ]);

                if ($patient->type_prise_en_charge === 'assurance') {
                    $this->appliquerTiersPayant(
                        $facture, $modele, $patient, $devise,
                        $ligne['type'], $ligne['reference_id'] ?? null
                    );
                }
            }

            // Les prestations prises en charge par le forfait figurent sur la
            // facture à montant nul : le patient voit ce qu'il a reçu et ce
            // que son forfait lui a évité de payer.
            foreach ($couvertesParForfait as $ligne) {
                LigneFacture::create([
                    'facture_id' => $facture->id,
                    'type' => $ligne['type'],
                    'libelle' => $ligne['libelle'].' — inclus au forfait',
                    'reference_id' => $ligne['reference_id'] ?? null,
                    'quantite' => $ligne['quantite'],
                    'prix_unitaire' => 0,
                    'total_ligne' => 0,
                ]);
            }

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

            return $facture->fresh();
        });
    }

    /**
     * Facture acte chirurgical ou maternité
     */
    public function creerFactureActeClinique(ActeClinique $acte): Facture
    {
        // Un acte déjà porté sur une facture n'en génère pas une seconde.
        if ($acte->facture_id && ($deja = Facture::find($acte->facture_id))) {
            return $deja;
        }

        return DB::transaction(function () use ($acte) {
            $acte->load(['patient', 'visit']);
            $typeLigne = match ($acte->domaine) {
                'chirurgie' => 'acte_chirurgical',
                'dialyse' => 'dialyse',
                default => 'autre',
            };

            $facture = $this->creerFactureAmbulatoire(
                $acte->patient,
                $acte->visit,
                $typeLigne,
                $acte->libelle,
                $this->prixConvention($acte->patient, $typeLigne, null, (float) $acte->montantTotal(), false),
                'CDF',
                $acte->id
            );

            // Facturer ne rend pas l'acte réalisé. Un geste payé d'avance —
            // le cas courant au guichet — reste à programmer et à clôturer :
            // seul le compte rendu atteste qu'il a eu lieu.
            $acte->update([
                'facture_id' => $facture->id,
                'statut' => $acte->statut === 'realise' ? 'facture' : $acte->statut,
            ]);

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
            // La devise reçue au guichet est enregistrée avec le taux du
            // jour et sa contre-valeur : un encaissement de 100 $ ne vaut
            // pas 100 CDF, et l'écriture ne doit plus jamais le laisser croire.
            $devises = app(DeviseService::class);
            $empreinte = $devises->empreinte($montantRecu, $devise);

            Paiement::create([
                'facture_id' => $facture->id,
                'caissier_id' => auth()->id(),
                'date_paiement' => now(),
                'montant' => $montantRecu,
                'devise' => $empreinte['devise'],
                'taux_change' => $empreinte['taux_change'],
                'montant_cdf' => $empreinte['montant_cdf'],
                'mode_paiement' => $modePaiement,
                'reference_paiement' => $reference,
                'recu_numero' => 'REC-'.now()->format('YmdHis'),
            ]);

            $facture->refresh();

            // Un règlement partiel ne solde pas la facture : le guichet
            // encaisse parfois en plusieurs fois, et parfois dans plusieurs
            // devises. Le statut suit ce qui reste réellement dû.
            $facture->update([
                'statut' => $facture->estSoldee() ? 'payee' : 'partiellement_payee',
            ]);

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

    /**
     * Ce qu'un encaissement débloque, quand la facture est soldée autrement
     * que par un paiement au guichet : un acompte imputé, une remise
     * accordée. L'ordonnance doit alors partir à la pharmacie et l'examen
     * au laboratoire exactement comme si le patient avait payé.
     *
     * Rien n'est émis deux fois : un bon encore valide suffit.
     */
    public function debloquerSiSoldee(Facture $facture): ?BonSortie
    {
        $facture->refresh();

        if ($facture->estAnnulee() || ! $facture->estSoldee() || ! auth()->id()) {
            return null;
        }

        $bon = null;

        $prescription = $facture->prescription_id ? Prescription::find($facture->prescription_id) : null;

        if ($prescription && in_array($prescription->statut, ['brouillon', 'en_attente_paiement'], true)
            && ! BonSortie::where('prescription_id', $prescription->id)->where('statut', 'emis')->exists()) {
            $bon = BonSortie::create([
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
        }

        foreach (ExamenLaboratoire::where('facture_id', $facture->id)->get() as $examen) {
            if (BonSortie::where('examen_id', $examen->id)->where('statut', 'emis')->exists()) {
                continue;
            }

            $bon = BonSortie::create([
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

        $facture->loadMissing('lignes', 'visit');
        if ($facture->visit
            && $facture->visit->statut === 'en_attente'
            && $facture->lignes->contains(fn ($l) => $l->type === 'consultation')) {
            $facture->visit->update(['statut' => 'en_cours']);
        }

        return $bon;
    }

    protected function genererNumeroFacture(): string
    {
        $prefix = 'FAC-'.now()->format('Y').'-';
        $last = Facture::where('numero_facture', 'like', $prefix.'%')
            ->orderByDesc('numero_facture')
            ->value('numero_facture');
        $seq = $last ? (int) substr($last, -6) + 1 : 1;

        return $prefix.str_pad($seq, 6, '0', STR_PAD_LEFT);
    }
}
