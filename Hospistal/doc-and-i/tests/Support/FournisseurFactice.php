<?php

namespace Tests\Support;

use App\Models\Paiement;
use App\Models\Remboursement;
use App\Services\Paiement\Fournisseur\ErreurFournisseur;
use App\Services\Paiement\Fournisseur\FournisseurPaiement;
use App\Services\Paiement\Fournisseur\FournisseurSimulation;
use App\Services\Paiement\Fournisseur\InitiationPaiement;
use App\Services\Paiement\Fournisseur\InitiationRemboursement;
use App\Services\Paiement\Fournisseur\LigneReleveFournisseur;
use App\Services\Paiement\Fournisseur\RappelFournisseur;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use LogicException;

/**
 * Prestataire piloté par le test : relevé choisi ligne à ligne, erreur de
 * remboursement à la demande. Porte le nom « simulation » pour retrouver
 * les paiements fictifs des tests.
 */
final class FournisseurFactice implements FournisseurPaiement
{
    /** @var list<LigneReleveFournisseur> */
    public array $releve = [];

    public ?string $erreurRemboursement = null;

    /** @var list<string> clés d'idempotence des remboursements demandés */
    public array $remboursementsDemandes = [];

    public function nom(): string
    {
        return FournisseurSimulation::NOM;
    }

    public function initier(Paiement $paiement): InitiationPaiement
    {
        throw new LogicException('Non utilisé par les tests de la caisse.');
    }

    public function lireRappel(Request $requete): RappelFournisseur
    {
        throw new LogicException('Non utilisé par les tests de la caisse.');
    }

    public function rembourser(Remboursement $remboursement): InitiationRemboursement
    {
        $this->remboursementsDemandes[] = $remboursement->cle_idempotence;

        if ($this->erreurRemboursement !== null) {
            throw new ErreurFournisseur($this->erreurRemboursement);
        }

        return new InitiationRemboursement('FACT-R-'.substr($remboursement->cle_idempotence, 0, 8));
    }

    public function releve(CarbonImmutable $jour): iterable
    {
        return $this->releve;
    }
}
