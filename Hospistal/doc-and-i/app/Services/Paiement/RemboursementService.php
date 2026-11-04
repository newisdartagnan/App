<?php

namespace App\Services\Paiement;

use App\Enums\MotifRemboursement;
use App\Enums\StatutPaiement;
use App\Enums\StatutRemboursement;
use App\Models\EvenementPaiement;
use App\Models\Paiement;
use App\Models\Remboursement;
use App\Models\User;
use App\Services\Paiement\Fournisseur\RappelFournisseur;
use App\Support\Montant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/**
 * PAY-07 : remboursements, toujours sur le moyen de paiement d'origine.
 *
 * Socle (ce fichier) : demander() et desengager(), qui tiennent le cumul
 * paiements.montant_rembourse sous verrou du paiement. La base refuse tout
 * cumul supérieur au montant payé.
 *
 * Lot caisse : valider() / refuser() (quatre yeux), executer() auprès du
 * prestataire, traiterRappel() du prestataire, avoir et journal.
 */
class RemboursementService
{
    /**
     * Crée la demande. Remboursement de règle (paiement tardif, en double) :
     * né validé, sans validation humaine. Les autres attendent la caisse.
     * Idempotent par motif (sauf geste commercial) : une annulation rejouée
     * rend la demande existante.
     *
     * @param  string  $montant  chaîne décimale (« 5.00 »), dans la devise du paiement
     *
     * @throws PaiementRefuse
     */
    public function demander(Paiement $paiement, string $montant, MotifRemboursement $motif, ?User $demandeur = null): Remboursement
    {
        $centimes = Montant::enCentimes($montant);
        if ($centimes <= 0) {
            throw new PaiementRefuse('Le montant à rembourser doit être positif.');
        }

        if ($motif === MotifRemboursement::GesteCommercial && $demandeur === null) {
            throw new PaiementRefuse('Un geste commercial est demandé par un agent de caisse identifié.');
        }

        return DB::transaction(function () use ($paiement, $centimes, $motif, $demandeur) {
            $paiement = Paiement::whereKey($paiement->id)->lockForUpdate()->firstOrFail();

            if ($paiement->statut !== StatutPaiement::Reussi) {
                throw new PaiementRefuse('Seul un paiement encaissé peut être remboursé.');
            }

            if ($motif !== MotifRemboursement::GesteCommercial) {
                $existant = Remboursement::where('paiement_id', $paiement->id)
                    ->where('motif', $motif->value)
                    ->whereIn('statut', StatutRemboursement::engages())
                    ->first();
                if ($existant) {
                    return $existant;
                }
            }

            if ($centimes > $paiement->resteRemboursableEnCentimes()) {
                throw new PaiementRefuse('Ce remboursement dépasse ce qui reste remboursable sur ce paiement.');
            }

            $remboursement = new Remboursement;
            $remboursement->forceFill([
                'paiement_id' => $paiement->id,
                'montant' => Montant::depuisCentimes($centimes),
                'devise' => $paiement->devise,
                'motif' => $motif,
                'statut' => $motif->estAutomatique() ? StatutRemboursement::Valide : StatutRemboursement::AValider,
                'automatique' => $motif->estAutomatique(),
                'demande_par' => $demandeur?->id,
                'cle_idempotence' => (string) Str::uuid(),
            ])->save();

            $paiement->montant_rembourse = Montant::depuisCentimes(Montant::enCentimes((string) $paiement->montant_rembourse) + $centimes);
            $paiement->save();

            return $remboursement;
        });
    }

    /** Part remboursable d'un paiement pour un taux (RG-04/05), arrondie au centime inférieur. */
    public function montantPourTaux(Paiement $paiement, int $taux): string
    {
        return Montant::depuisCentimes(intdiv(Montant::enCentimes((string) $paiement->montant) * $taux, 100));
    }

    /**
     * Rend au paiement le montant d'un remboursement refusé ou échoué, pour
     * qu'une nouvelle demande reste possible. À appeler sous verrou du
     * paiement, dans la transaction qui change le statut du remboursement.
     */
    public function desengager(Remboursement $remboursement): void
    {
        if ($remboursement->statut->engageLeMontant()) {
            throw new LogicException("Remboursement {$remboursement->id} encore engagé : rien à libérer.");
        }

        $paiement = Paiement::whereKey($remboursement->paiement_id)->lockForUpdate()->firstOrFail();
        $paiement->montant_rembourse = Montant::depuisCentimes(
            Montant::enCentimes((string) $paiement->montant_rembourse) - Montant::enCentimes((string) $remboursement->montant));
        $paiement->save();
    }

    /** Lot caisse : rappel signé du prestataire sur un remboursement. */
    public function traiterRappel(RappelFournisseur $rappel, EvenementPaiement $evenement): void
    {
        throw new LogicException('À écrire au lot caisse : traitement du rappel de remboursement.');
    }
}
