<?php

namespace App\Services\Paiement;

use App\Enums\StatutRemboursement;
use App\Models\Paiement;
use App\Models\Remboursement;
use App\Services\Paiement\Fournisseur\FournisseurPaiement;
use App\Services\Paiement\Fournisseur\FournisseurSimulation;
use App\Services\Paiement\Fournisseur\RappelFournisseur;
use App\Services\Paiement\Fournisseur\SignatureRappel;
use Illuminate\Http\Request;
use LogicException;

/**
 * Simulation locale : le développeur déclenche le rappel signé qu'enverrait
 * l'agrégateur. Le rappel passe par LE MÊME traitement que le vrai
 * (PaiementService::recevoirRappel, signature vérifiée comprise). Jamais en
 * production : la route n'est pas déclarée, et le contrôleur revérifie.
 */
final class SimulationPaiement
{
    public static function disponible(): bool
    {
        return config('doc-and-i.paiement.fournisseur') === FournisseurSimulation::NOM
            && FournisseurSimulation::environnementAutorise();
    }

    /** @return int code HTTP du traitement du rappel */
    public function declencher(Paiement $paiement, bool $reussi): int
    {
        if (! self::disponible()) {
            throw new LogicException('Simulation de paiement indisponible dans cet environnement.');
        }

        $pilote = app(FournisseurPaiement::class);
        if (! $pilote instanceof FournisseurSimulation) {
            throw new LogicException('Le pilote de paiement n\'est pas la simulation.');
        }

        $rappel = $pilote->rappelSigne(
            RappelFournisseur::PAIEMENT,
            $paiement->cle_idempotence,
            (string) ($paiement->reference_fournisseur ?? 'SIM-P-'.substr(hash('sha256', $paiement->cle_idempotence), 0, 24)),
            $reussi,
            (string) $paiement->montant,
            $paiement->devise,
            $reussi ? null : 'Paiement refusé (simulation).',
        );

        $requete = Request::create('/paiement/rappel/'.FournisseurSimulation::NOM, 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_'.strtoupper(str_replace('-', '_', SignatureRappel::ENTETE)) => $rappel['entete'],
        ], $rappel['corps']);

        return app(PaiementService::class)->recevoirRappel($requete);
    }

    /**
     * Rappel signé d'un remboursement envoyé à l'opérateur (« en cours ») :
     * même traitement que le vrai rappel (avoir, sortie de caisse, SMS).
     *
     * @return int code HTTP du traitement du rappel
     */
    public function declencherRemboursement(Remboursement $remboursement, bool $reussi): int
    {
        if (! self::disponible()) {
            throw new LogicException('Simulation de paiement indisponible dans cet environnement.');
        }

        if ($remboursement->statut !== StatutRemboursement::EnCours || $remboursement->reference_fournisseur === null) {
            throw new LogicException('Seul un remboursement envoyé à l\'opérateur peut recevoir un rappel.');
        }

        $pilote = app(FournisseurPaiement::class);
        if (! $pilote instanceof FournisseurSimulation) {
            throw new LogicException('Le pilote de paiement n\'est pas la simulation.');
        }

        $rappel = $pilote->rappelSigne(
            RappelFournisseur::REMBOURSEMENT,
            $remboursement->cle_idempotence,
            $remboursement->reference_fournisseur,
            $reussi,
            (string) $remboursement->montant,
            $remboursement->devise,
            $reussi ? null : 'Remboursement refusé (simulation).',
        );

        $requete = Request::create('/paiement/rappel/'.FournisseurSimulation::NOM, 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_'.strtoupper(str_replace('-', '_', SignatureRappel::ENTETE)) => $rappel['entete'],
        ], $rappel['corps']);

        return app(PaiementService::class)->recevoirRappel($requete);
    }
}
