<?php

namespace App\Services\Paiement;

use App\Enums\StatutPaiement;
use App\Enums\StatutRemboursement;
use App\Models\Facture;
use App\Models\Paiement;
use App\Models\Remboursement;
use App\Services\RendezVous\Affichage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * PAY-05, PAY-08 : factures et avoirs, numérotés sans trou par année
 * (FA-2026-000001, AV-2026-000001). Le numéro est pris dans la même
 * transaction que la pièce : une transaction annulée rend son numéro.
 * Une pièce émise ne change plus ; une erreur se corrige par un avoir.
 */
final class FacturationService
{
    /** Idempotent : la facture d'un paiement est émise une fois. À appeler dans la transaction qui verrouille le paiement. */
    public function emettreFacture(Paiement $paiement): Facture
    {
        return DB::transaction(function () use ($paiement) {
            if ($paiement->statut !== StatutPaiement::Reussi) {
                throw new LogicException("Paiement {$paiement->id} non réussi : pas de facture.");
            }

            if ($existante = Facture::where('paiement_id', $paiement->id)->where('type', 'facture')->first()) {
                return $existante;
            }

            $rdv = $paiement->rendezVous()->with('typeConsultation')->first();
            $libelle = $rdv
                ? 'Téléconsultation du '.Affichage::dateHeure($rdv->debut).' — '.$rdv->typeConsultation->nom
                : 'Téléconsultation';

            $facture = new Facture;
            $facture->forceFill([
                'numero' => $this->numeroSuivant('FA'),
                'type' => 'facture',
                'patient_id' => $paiement->patient_id,
                'medecin_id' => $rdv?->medecin_id,
                'paiement_id' => $paiement->id,
                'libelle' => mb_substr($libelle, 0, 255),
                'montant' => $paiement->montant,
                'devise' => $paiement->devise,
                'moyen' => $paiement->moyen->value,
                'emise_le' => CarbonImmutable::now(),
            ])->save();

            return $facture;
        });
    }

    /** PAY-08 : avoir d'un remboursement réussi, rattaché à la facture d'origine. Idempotent. */
    public function emettreAvoir(Remboursement $remboursement): Facture
    {
        return DB::transaction(function () use ($remboursement) {
            if ($remboursement->statut !== StatutRemboursement::Reussi) {
                throw new LogicException("Remboursement {$remboursement->id} non réussi : pas d'avoir.");
            }

            if ($existant = Facture::where('remboursement_id', $remboursement->id)->where('type', 'avoir')->first()) {
                return $existant;
            }

            $paiement = $remboursement->paiement;
            $origine = $this->emettreFacture($paiement);

            $avoir = new Facture;
            $avoir->forceFill([
                'numero' => $this->numeroSuivant('AV'),
                'type' => 'avoir',
                'patient_id' => $paiement->patient_id,
                'medecin_id' => $origine->medecin_id,
                'paiement_id' => $paiement->id,
                'remboursement_id' => $remboursement->id,
                'facture_origine_id' => $origine->id,
                'libelle' => mb_substr('Avoir sur la facture '.$origine->numero.' — '.$remboursement->motif->libelle(), 0, 255),
                'montant' => $remboursement->montant,
                'devise' => $remboursement->devise,
                'moyen' => $paiement->moyen->value,
                'emise_le' => CarbonImmutable::now(),
            ])->save();

            return $avoir;
        });
    }

    /** Prochain numéro de la série : le compteur reste verrouillé jusqu'à la fin de la transaction. */
    public function numeroSuivant(string $prefixe): string
    {
        $annee = (int) CarbonImmutable::now('Africa/Kinshasa')->format('Y');

        $dernier = DB::selectOne(
            'INSERT INTO compteurs_numerotation (prefixe, annee, dernier) VALUES (?, ?, 1)
             ON CONFLICT (prefixe, annee) DO UPDATE SET dernier = compteurs_numerotation.dernier + 1
             RETURNING dernier',
            [$prefixe, $annee],
        )->dernier;

        return sprintf('%s-%d-%06d', $prefixe, $annee, $dernier);
    }
}
