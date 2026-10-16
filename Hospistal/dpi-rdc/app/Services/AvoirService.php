<?php

namespace App\Services;

use App\Models\ActeClinique;
use App\Models\Avoir;
use App\Models\BonSortie;
use App\Models\Caution;
use App\Models\ExamenLaboratoire;
use App\Models\Facture;
use App\Models\FactureTiersPayant;
use App\Models\Forfait;
use App\Models\LigneAvoir;
use App\Models\LigneFacture;
use App\Models\LigneFactureConvention;
use App\Models\PatientAssurance;
use App\Models\Prescription;
use App\Models\PrescriptionDiete;
use App\Models\Transfusion;
use App\Models\Visit;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Avoirs : la seule façon de corriger une facture émise.
 *
 * Un avoir reprend tout ou partie d'une facture. Il se répartit, ligne par
 * ligne, entre la part du patient et celle de l'assureur, dans la même
 * proportion que la facture. Ce que le patient avait déjà versé au-delà de
 * ce qu'il doit encore lui revient en crédit ; ce que l'assureur avait
 * consommé sur son plafond lui est rendu.
 *
 * Une annulation totale peut libérer les prestations — l'examen, l'acte,
 * les journées, l'ordonnance — pour qu'une facture juste les reprenne.
 * Sans libération, la prestation reste attachée à la facture annulée : elle
 * a été offerte, ou elle n'était pas due, et ne se refacture pas.
 */
class AvoirService
{
    public function __construct(
        private readonly DeviseService $devises,
        private readonly AcompteService $acomptes,
    ) {}

    /**
     * Ce qui reste à reprendre sur chaque ligne de la facture, et sa
     * répartition entre patient et assureur.
     *
     * @return Collection<int, array{ligne: LigneFacture, total: float, reste: float, reste_patient: float, reste_assurance: float}>
     */
    public function lignesCreditables(Facture $facture): Collection
    {
        $facture->loadMissing('lignes');

        $assurance = FactureTiersPayant::where('facture_id', $facture->id)
            ->selectRaw('ligne_facture_id, SUM(part_assurance) AS part')
            ->groupBy('ligne_facture_id')
            ->pluck('part', 'ligne_facture_id');

        $deja = LigneAvoir::whereIn('ligne_facture_id', $facture->lignes->pluck('id'))
            ->selectRaw('ligne_facture_id, SUM(montant) AS montant, SUM(montant_patient) AS patient, SUM(montant_assurance) AS assurance')
            ->groupBy('ligne_facture_id')
            ->get()
            ->keyBy('ligne_facture_id');

        return $facture->lignes->map(function (LigneFacture $ligne) use ($assurance, $deja) {
            $total = (float) $ligne->total_ligne;
            $partAssurance = min($total, (float) ($assurance[$ligne->id] ?? 0));
            $repris = $deja->get($ligne->id);

            $resteAssurance = max(0, round($partAssurance - (float) ($repris->assurance ?? 0), 2));
            $restePatient = max(0, round($total - $partAssurance - (float) ($repris->patient ?? 0), 2));

            return [
                'ligne' => $ligne,
                'total' => $total,
                'reste' => round($resteAssurance + $restePatient, 2),
                'reste_patient' => $restePatient,
                'reste_assurance' => $resteAssurance,
            ];
        })->values();
    }

    /**
     * Émet un avoir.
     *
     * @param  array<string, float>|null  $montants  ligne de facture → montant repris ;
     *                                               null pour annuler toute la facture.
     */
    public function emettre(
        Facture $facture,
        string $categorie,
        string $motif,
        ?array $montants = null,
        bool $libererPrestations = false,
        string $type = 'partiel',
    ): Avoir {
        if (trim($motif) === '') {
            throw new RuntimeException('Un avoir se motive : indiquez pourquoi la facture est reprise.');
        }

        if (! array_key_exists($categorie, Avoir::MOTIFS)) {
            throw new RuntimeException('Motif d\'avoir inconnu.');
        }

        return DB::transaction(function () use ($facture, $categorie, $motif, $montants, $libererPrestations, $type) {
            // Deux guichets qui annulent la même facture au même instant :
            // le second attend le premier, puis trouve la facture annulée.
            $facture = Facture::whereKey($facture->id)->lockForUpdate()->firstOrFail();

            if ($facture->estAnnulee()) {
                throw new RuntimeException("La facture {$facture->numero_facture} est déjà annulée.");
            }

            if ($facture->statut === 'brouillon') {
                throw new RuntimeException('Un brouillon se corrige directement : il n\'a encore été remis à personne.');
            }

            $creditables = $this->lignesCreditables($facture);
            $totale = $montants === null;

            $reprises = $totale
                ? $creditables->filter(fn ($c) => $c['reste'] > 0)
                    ->map(fn ($c) => [...$c, 'montant' => $c['reste']])
                : $this->reprisesDemandees($creditables, $montants);

            $total = round($reprises->sum('montant'), 2);

            if ($total <= 0 && ! ($totale && $facture->totalNet() <= 0)) {
                throw new RuntimeException('Indiquez au moins une ligne et un montant à reprendre.');
            }

            // Répartition patient / assureur, ligne par ligne, au prorata de
            // ce qui reste à chacun sur la ligne.
            $reprises = $reprises->map(function ($r) {
                $assurance = $r['reste'] > 0
                    ? round($r['montant'] * $r['reste_assurance'] / $r['reste'], 2)
                    : 0.0;

                return [...$r, 'assurance' => $assurance, 'patient' => round($r['montant'] - $assurance, 2)];
            });

            $partPatient = round($reprises->sum('patient'), 2);
            $partAssurance = round($reprises->sum('assurance'), 2);

            // Une annulation reprend exactement ce que la facture réclame
            // encore — y compris sur une pièce ancienne dont les lignes ne
            // portent pas le détail du tiers payant.
            if ($totale) {
                $partPatient = $facture->partPatientNette();
                $partAssurance = $facture->partAssuranceNette();
                $total = round($partPatient + $partAssurance, 2);
            }

            // Ce que le patient a déjà versé, face à ce qu'il devra encore :
            // la différence, s'il y en a une, lui appartient.
            $retenuAvant = $facture->montantRetenu();
            $netApres = max(0, round($facture->partPatientNette() - $partPatient, 2));
            $trop = round($retenuAvant - $netApres, 2);
            $credit = $trop > 0.009 ? $trop : 0.0;

            $liberees = $totale && $libererPrestations ? $this->liberer($facture) : null;

            $avoir = Avoir::create([
                'numero' => Avoir::genererNumero(),
                'facture_id' => $facture->id,
                'patient_id' => $facture->patient_id,
                'establishment_id' => $facture->establishment_id,
                'type' => $totale ? 'annulation' : $type,
                'motif_categorie' => $categorie,
                'motif' => trim($motif),
                'devise' => $facture->deviseFacture(),
                'taux_change' => $facture->tauxApplique(),
                'montant_total' => $total,
                'montant_patient' => $partPatient,
                'montant_assurance' => $partAssurance,
                'credit_patient' => $credit,
                'liberer_prestations' => $liberees !== null,
                'prestations_liberees' => $liberees,
                'facture_convention_id' => $this->bordereau($facture),
                'emis_par' => auth()->id(),
                'date_avoir' => now(),
            ]);

            foreach ($reprises as $r) {
                LigneAvoir::create([
                    'avoir_id' => $avoir->id,
                    'ligne_facture_id' => $r['ligne']->id,
                    'libelle' => $r['ligne']->libelle,
                    'montant' => $r['montant'],
                    'montant_patient' => $r['patient'],
                    'montant_assurance' => $r['assurance'],
                ]);
            }

            $this->rendreAuPlafond($facture, $partAssurance);

            if ($credit > 0) {
                $avoir->update(['caution_id' => $this->crediter($facture, $avoir, $credit)->id]);
            }

            $this->mettreAJourStatut($facture, $retenuAvant > 0.009);

            if ($totale) {
                // Un bon encore en main ne doit plus ouvrir la pharmacie ni le
                // laboratoire sur une facture qui n'existe plus.
                BonSortie::where('facture_id', $facture->id)
                    ->where('statut', 'emis')
                    ->get()
                    ->each(fn (BonSortie $bon) => $bon->update(['statut' => 'annule']));
            }

            return $avoir->fresh(['lignes', 'facture', 'credit']);
        });
    }

    /**
     * Refacture les prestations libérées par une annulation, sur de
     * nouvelles factures qui gardent le lien avec celle qu'elles remplacent.
     * Le crédit né de l'annulation s'y impute en premier.
     *
     * @return Collection<int, Facture>
     */
    public function refacturer(Avoir $avoir): Collection
    {
        if (! $avoir->peutEtreRefacture()) {
            throw new RuntimeException('Cet avoir n\'a rien libéré à refacturer, ou c\'est déjà fait.');
        }

        return DB::transaction(function () use ($avoir) {
            $facturation = app(FacturationService::class);
            $nouvelles = collect();
            $sejourFait = false;

            foreach ($avoir->prestations_liberees as $p) {
                // Les journées et les diètes d'un séjour se refacturent
                // ensemble, en une seule facture de séjour.
                if (in_array($p['type'], ['sejour', 'diete'], true)) {
                    if ($sejourFait) {
                        continue;
                    }
                    $sejourFait = true;
                }

                $facture = $this->refacturerPrestation($facturation, $p);

                if ($facture && $facture->id !== $avoir->facture_id && ! $nouvelles->contains('id', $facture->id)) {
                    $nouvelles->push($facture);
                }
            }

            foreach ($nouvelles as $facture) {
                if (! $facture->avoir_origine_id && $facture->created_at?->gte($avoir->date_avoir)) {
                    $facture->update(['avoir_origine_id' => $avoir->id]);
                }

                if ($avoir->caution_id && ($credit = Caution::find($avoir->caution_id))) {
                    $this->acomptes->imputer($facture, source: $credit);
                }
            }

            $avoir->update(['refacture_le' => now()]);

            return $nouvelles->map(fn (Facture $f) => $f->fresh());
        });
    }

    /**
     * Rend au patient, en espèces, le crédit qu'un avoir lui a laissé.
     *
     * @return float Montant rendu, dans la devise du crédit.
     */
    public function rembourserCredit(Avoir $avoir, ?string $reference = null): float
    {
        $credit = $avoir->credit;

        if (! $credit || $credit->resteDisponible() <= 0) {
            throw new RuntimeException('Il ne reste rien de ce crédit à rendre.');
        }

        return DB::transaction(function () use ($credit, $reference) {
            $reste = $credit->resteDisponible();

            $credit->increment('montant_rembourse', $reste);
            $credit->increment('montant_rembourse_cdf', $credit->resteDisponibleCdf());
            $credit->update(['reference_paiement' => $reference ?: $credit->reference_paiement]);
            $credit->refresh()->rafraichirStatut();

            return $reste;
        });
    }

    // ══════════════════════════════════════════════════════════════
    // Rouages
    // ══════════════════════════════════════════════════════════════

    /**
     * Émet la facture qui reprend une prestation libérée — si elle est
     * toujours libre : entre l'annulation et la refacturation, le guichet a
     * pu la refacturer à la main.
     *
     * @param  array<string, mixed>  $p
     */
    private function refacturerPrestation(FacturationService $facturation, array $p): ?Facture
    {
        switch ($p['type']) {
            case 'consultation':
                $visite = Visit::find($p['visit_id']);

                return $visite ? $facturation->creerFactureConsultation($visite) : null;

            case 'examen':
                $examen = ExamenLaboratoire::find($p['id']);

                return $examen && ! $examen->facture_id ? $facturation->creerFactureExamen($examen) : null;

            case 'acte':
                $acte = ActeClinique::find($p['id']);

                return $acte && ! $acte->facture_id ? $facturation->creerFactureActeClinique($acte) : null;

            case 'prescription':
                $ordonnance = Prescription::find($p['id']);
                $libre = $ordonnance
                    && in_array($ordonnance->statut, ['brouillon', 'en_attente_paiement', 'dispensee'], true)
                    && ! Facture::where('prescription_id', $ordonnance->id)->where('statut', '!=', 'annulee')->exists();

                return $libre ? $facturation->creerFacturePrescription($ordonnance) : null;

            case 'sejour':
            case 'diete':
                $visite = Visit::find($p['visit_id']);

                return $visite ? $facturation->creerFactureHospitalisation($visite) : null;

            case 'forfait':
                $visite = Visit::find($p['visit_id']);
                $forfait = Forfait::find($p['forfait_id']);

                return $visite && $forfait && ! $visite->forfait_id
                    ? app(ForfaitService::class)->appliquer($visite, $forfait)
                    : null;

            case 'transfusion':
                $transfusion = Transfusion::find($p['id']);

                return $transfusion && ! $transfusion->facture_id
                    ? app(BanqueSangService::class)->facturerTransfusion($transfusion)
                    : null;
        }

        return null;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $creditables
     * @param  array<string, mixed>  $montants
     * @return Collection<int, array<string, mixed>>
     */
    private function reprisesDemandees(Collection $creditables, array $montants): Collection
    {
        $parLigne = $creditables->keyBy(fn ($c) => $c['ligne']->id);
        $reprises = collect();

        foreach ($montants as $ligneId => $montant) {
            $montant = round((float) str_replace([' ', ','], ['', '.'], (string) $montant), 2);

            if ($montant <= 0) {
                continue;
            }

            $c = $parLigne->get($ligneId);

            if (! $c) {
                throw new RuntimeException('Cette ligne n\'appartient pas à la facture.');
            }

            if ($montant > $c['reste'] + 0.009) {
                throw new RuntimeException(
                    "« {$c['ligne']->libelle} » : on ne peut reprendre que ce qui reste sur la ligne."
                );
            }

            $reprises->push([...$c, 'montant' => min($montant, $c['reste'])]);
        }

        return $reprises;
    }

    /** Le bordereau d'assureur qui réclame déjà cette facture, s'il y en a un. */
    private function bordereau(Facture $facture): ?string
    {
        return LigneFactureConvention::query()
            ->join('factures_convention as fc', 'fc.id', '=', 'lignes_facture_convention.facture_convention_id')
            ->where('lignes_facture_convention.facture_id', $facture->id)
            ->where('fc.statut', '!=', 'annulee')
            ->value('fc.id');
    }

    /**
     * Ce que l'assureur ne paie plus ne compte plus dans le plafond annuel
     * du patient : une erreur de facture ne doit pas lui coûter sa
     * couverture de fin d'année.
     */
    private function rendreAuPlafond(Facture $facture, float $partAssurance): void
    {
        if ($partAssurance <= 0) {
            return;
        }

        $tiers = FactureTiersPayant::where('facture_id', $facture->id)
            ->where('part_assurance', '>', 0)
            ->get();

        if ($tiers->isEmpty()) {
            return;
        }

        $assuranceId = $tiers->first()->assurance_id;
        $devise = $tiers->first()->devise === 'USD' ? 'USD' : 'CDF';

        $contrat = PatientAssurance::where('patient_id', $facture->patient_id)
            ->where('assurance_id', $assuranceId)
            ->orderByDesc('est_actif')
            ->first();

        if (! $contrat) {
            return;
        }

        $colonne = $devise === 'USD' ? 'consomme_annuel_usd' : 'consomme_annuel_cdf';

        $contrat->update([$colonne => max(0, round((float) $contrat->{$colonne} - $partAssurance, 2))]);
    }

    /**
     * Le trop-perçu devient un crédit au nom du patient, rangé avec ses
     * acomptes : il s'impute sur sa prochaine facture ou se rend en espèces.
     */
    private function crediter(Facture $facture, Avoir $avoir, float $montant): Caution
    {
        $devise = in_array($facture->deviseFacture(), ['CDF', 'USD'], true)
            ? $facture->deviseFacture()
            : 'CDF';
        $taux = $devise === $facture->deviseFacture() ? $facture->tauxApplique() : 1.0;

        if ($devise !== $facture->deviseFacture()) {
            $montant = $this->devises->versCdf($montant, $facture->deviseFacture(), $facture->tauxApplique());
        }

        return Caution::create([
            'visit_id' => $facture->visit_id,
            'patient_id' => $facture->patient_id,
            'avoir_id' => $avoir->id,
            'type' => 'avoir',
            'caissier_id' => auth()->id(),
            'montant' => $montant,
            'devise' => $devise,
            'taux_change' => $taux,
            'montant_cdf' => $this->devises->versCdf($montant, $devise, $taux),
            'mode_paiement' => 'avoir',
            'motif' => "Crédit sur l'avoir {$avoir->numero} (facture {$facture->numero_facture})",
            'statut' => 'versee',
        ]);
    }

    private function mettreAJourStatut(Facture $facture, bool $aDejaRecu): void
    {
        $facture->refresh();

        $statut = match (true) {
            $facture->totalNet() <= 0.009 => 'annulee',
            $aDejaRecu && $facture->estSoldee() => 'payee',
            $aDejaRecu => 'partiellement_payee',
            default => $facture->statut,
        };

        if ($statut !== $facture->statut) {
            $facture->update(['statut' => $statut]);
        }
    }

    /**
     * Détache de la facture annulée tout ce qu'elle portait, pour qu'une
     * facture juste puisse le reprendre. Chaque détachement est noté : la
     * refacturation suit exactement cette liste.
     *
     * @return array<int, array<string, mixed>>
     */
    private function liberer(Facture $facture): array
    {
        $liberees = [];
        $facture->loadMissing('lignes', 'visit');

        if ($facture->visit_id && $facture->lignes->contains('type', 'consultation')) {
            $liberees[] = ['type' => 'consultation', 'visit_id' => $facture->visit_id];
        }

        foreach (ExamenLaboratoire::where('facture_id', $facture->id)->get() as $examen) {
            $examen->update(['facture_id' => null]);
            $liberees[] = ['type' => 'examen', 'id' => $examen->id];
        }

        $sejour = $facture->lignes->where('type', 'hospitalisation');

        foreach (ActeClinique::where('facture_id', $facture->id)->get() as $acte) {
            // L'acte « Séjour hospitalisation » n'est que l'ombre de la ligne
            // de journées : il disparaît avec elle et renaîtra avec la
            // nouvelle facture.
            if ($acte->domaine === 'hospitalisation' && $sejour->isNotEmpty()) {
                $acte->update(['statut' => 'annule', 'facture_id' => null]);

                continue;
            }

            $acte->update([
                'facture_id' => null,
                'statut' => $acte->statut === 'facture' ? 'realise' : $acte->statut,
            ]);
            $liberees[] = ['type' => 'acte', 'id' => $acte->id];
        }

        if ($facture->visit && $sejour->isNotEmpty()) {
            $jours = (int) $sejour->sum(fn ($l) => (float) $l->quantite);
            $facture->visit->update([
                'jours_factures' => max(0, (int) $facture->visit->jours_factures - $jours),
            ]);
            $liberees[] = ['type' => 'sejour', 'visit_id' => $facture->visit_id, 'jours' => $jours];
        }

        foreach (PrescriptionDiete::where('facture_id', $facture->id)->get() as $diete) {
            $diete->update(['facture_id' => null, 'jours_factures' => 0]);
            $liberees[] = ['type' => 'diete', 'id' => $diete->id, 'visit_id' => $diete->visit_id];
        }

        if ($facture->prescription_id && ($ordonnance = Prescription::find($facture->prescription_id))) {
            // Une ordonnance déjà servie reste servie : on ne reprend pas des
            // comprimés avalés. Elle se refacture telle quelle.
            if (in_array($ordonnance->statut, ['en_attente', 'en_attente_paiement'], true)) {
                $ordonnance->update(['statut' => 'en_attente_paiement']);
            }
            $liberees[] = ['type' => 'prescription', 'id' => $ordonnance->id];
        }

        if ($facture->visit && $facture->visit->forfait_facture_id === $facture->id) {
            $liberees[] = [
                'type' => 'forfait',
                'visit_id' => $facture->visit_id,
                'forfait_id' => $facture->visit->forfait_id,
            ];
            app(ForfaitService::class)->retirer($facture->visit);
        }

        foreach (Transfusion::where('facture_id', $facture->id)->get() as $transfusion) {
            $transfusion->update(['facture_id' => null]);
            $liberees[] = ['type' => 'transfusion', 'id' => $transfusion->id];
        }

        return $liberees;
    }
}
