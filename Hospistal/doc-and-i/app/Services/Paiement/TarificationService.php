<?php

namespace App\Services\Paiement;

use App\Models\RendezVous;
use App\Models\TauxChange;
use App\Support\Montant;
use Carbon\CarbonImmutable;

/**
 * PAY-01 : montant à payer pour un rendez-vous. Le prix est celui figé à
 * la réservation (RG-13) ; payé dans l'autre devise, il est converti au taux
 * du jour saisi par la caisse, arrondi au profit de personne d'autre que
 * l'unité : franc entier vers le haut, centime vers le haut.
 */
final class TarificationService
{
    /** @throws PaiementRefuse */
    public function pourRendezVous(RendezVous $rdv, string $devise): Tarif
    {
        if ($rdv->prix === null) {
            throw new PaiementRefuse('Le tarif de cette consultation n\'est pas encore fixé. Réessayez plus tard.');
        }

        if (! in_array($devise, config('doc-and-i.paiement.devises'), true)) {
            throw new PaiementRefuse('Cette devise n\'est pas acceptée.');
        }

        $prix = (string) $rdv->prix;

        if ($devise === $rdv->devise) {
            return new Tarif($prix, $devise, $prix, $rdv->devise, null);
        }

        $taux = $this->tauxDuJour() ?? throw new PaiementRefuse(
            'Le paiement en '.$devise.' n\'est pas possible pour le moment : payez en '.$rdv->devise.'.');

        return new Tarif($this->convertir($prix, $rdv->devise, $devise, $taux), $devise, $prix, $rdv->devise, $taux);
    }

    /** Le taux ACTIF du jour : ni un taux remplacé, ni un taux qui attend la confirmation d'un second agent. */
    public function tauxDuJour(): ?TauxChange
    {
        return TauxChange::where('jour', CarbonImmutable::now('Africa/Kinshasa')->toDateString())->where('statut', 'actif')->first();
    }

    public function convertir(string $montant, string $de, string $vers, TauxChange $taux): string
    {
        $centimes = Montant::enCentimes($montant);
        $tauxE4 = self::tauxEnDixMilliemes((string) $taux->cdf_par_usd);

        return Montant::depuisCentimes(match ("{$de}>{$vers}") {
            // Centimes USD × taux → centimes CDF, arrondis au franc supérieur.
            'USD>CDF' => self::diviserVersLeHaut($centimes * $tauxE4, 10000 * 100) * 100,
            'CDF>USD' => self::diviserVersLeHaut($centimes * 10000, $tauxE4),
            default => throw new \InvalidArgumentException("Conversion {$de} vers {$vers} inconnue."),
        });
    }

    private static function tauxEnDixMilliemes(string $taux): int
    {
        if (! preg_match('/^(\d{1,8})(?:\.(\d{1,4}))?$/', $taux, $m)) {
            throw new \InvalidArgumentException("Taux illisible : {$taux}.");
        }

        return (int) $m[1] * 10000 + (int) str_pad($m[2] ?? '0', 4, '0');
    }

    private static function diviserVersLeHaut(int $a, int $b): int
    {
        return intdiv($a + $b - 1, $b);
    }
}
