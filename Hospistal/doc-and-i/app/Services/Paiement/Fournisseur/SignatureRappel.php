<?php

namespace App\Services\Paiement\Fournisseur;

/**
 * Signature HMAC-SHA256 d'un rappel : en-tête « t=<horodatage>,v1=<hex> »,
 * calculée sur « <horodatage>.<corps brut> ». L'horodatage borne le rejeu
 * (tolérance en secondes) ; l'identifiant d'événement unique fait le reste.
 * Utilisée par le pilote de simulation ; un vrai prestataire impose souvent
 * son propre schéma, que son pilote vérifie de la même façon.
 */
final class SignatureRappel
{
    public const ENTETE = 'X-Medihome-Signature';

    public function __construct(private readonly string $secret, private readonly int $toleranceSecondes = 300)
    {
        if (strlen($secret) < 32) {
            throw new \InvalidArgumentException('Secret de signature des rappels trop court (32 caractères au moins, voir PAIEMENT_SECRET_RAPPEL).');
        }
    }

    public function signer(string $corps, ?int $horodatage = null): string
    {
        $horodatage ??= time();

        return 't='.$horodatage.',v1='.hash_hmac('sha256', $horodatage.'.'.$corps, $this->secret);
    }

    /** @throws RappelInvalide */
    public function verifier(string $corps, ?string $entete, ?int $maintenant = null): void
    {
        if ($entete === null || ! preg_match('/^t=(\d{1,12}),v1=([0-9a-f]{64})$/', $entete, $m)) {
            throw new RappelInvalide('Signature absente ou mal formée.');
        }

        if (abs(($maintenant ?? time()) - (int) $m[1]) > $this->toleranceSecondes) {
            throw new RappelInvalide('Signature périmée.');
        }

        if (! hash_equals(hash_hmac('sha256', $m[1].'.'.$corps, $this->secret), $m[2])) {
            throw new RappelInvalide('Signature invalide.');
        }
    }
}
