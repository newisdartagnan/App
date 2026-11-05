<?php

namespace Tests\Support;

use App\Models\Paiement;
use App\Models\Remboursement;
use App\Services\Paiement\Fournisseur\ErreurFournisseur;
use App\Services\Paiement\Fournisseur\FournisseurPaiement;
use App\Services\Paiement\Fournisseur\FournisseurSimulation;
use App\Services\Paiement\Fournisseur\InitiationPaiement;
use App\Services\Paiement\Fournisseur\InitiationRemboursement;
use App\Services\Paiement\Fournisseur\RappelFournisseur;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * Simulation instrumentée pour les tests : compte les demandes de débit,
 * peut échouer à l'initiation ou renvoyer une page 3-D Secure. Le reste
 * (rappels signés, relevé) est celui de la simulation.
 */
final class FournisseurPaiementTemoin implements FournisseurPaiement
{
    public int $initiations = 0;

    /** Message de l'erreur à lever à l'initiation (null : pas d'erreur). */
    public ?string $erreur = null;

    /** true : refus explicite (rien n'est parti) ; false : erreur technique (la demande est peut-être partie). */
    public bool $refusDefinitif = false;

    public ?string $urlRedirection = null;

    public function __construct(private readonly FournisseurSimulation $simulation) {}

    public function nom(): string
    {
        return $this->simulation->nom();
    }

    public function initier(Paiement $paiement): InitiationPaiement
    {
        $this->initiations++;

        if ($this->erreur !== null) {
            throw $this->refusDefinitif ? ErreurFournisseur::refus($this->erreur) : ErreurFournisseur::injoignable($this->erreur);
        }

        $initiation = $this->simulation->initier($paiement);

        return new InitiationPaiement($initiation->referenceFournisseur, $this->urlRedirection, $initiation->instructions);
    }

    public function lireRappel(Request $requete): RappelFournisseur
    {
        return $this->simulation->lireRappel($requete);
    }

    public function rembourser(Remboursement $remboursement): InitiationRemboursement
    {
        return $this->simulation->rembourser($remboursement);
    }

    public function releve(CarbonImmutable $jour): iterable
    {
        return $this->simulation->releve($jour);
    }
}
