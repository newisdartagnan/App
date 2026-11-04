<?php

namespace App\Services\Paiement\Fournisseur;

use App\Models\Paiement;
use App\Models\Remboursement;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * Agrégateur de paiement (PAY-02, PAY-03) : Mobile Money et carte par un
 * même prestataire, choisi par le client. Un pilote par prestataire ;
 * « simulation » pour le développement, refusé en production.
 *
 * Règle d'or (PAY-04, RG-01) : initier() ne confirme rien. Seul un rappel
 * serveur dont lireRappel() a vérifié la signature fait foi.
 */
interface FournisseurPaiement
{
    /** Nom court, stocké dans paiements.fournisseur et dans l'adresse du rappel. */
    public function nom(): string;

    /**
     * Demande le débit. Idempotent côté prestataire : la clé d'idempotence
     * du paiement lui est transmise, une reprise après coupure ne débite pas
     * deux fois.
     *
     * @throws ErreurFournisseur
     */
    public function initier(Paiement $paiement): InitiationPaiement;

    /**
     * Vérifie l'authenticité du rappel (signature, fraîcheur) et le traduit.
     * Lit le corps brut de la requête, jamais un champ ré-encodé.
     *
     * @throws RappelInvalide
     */
    public function lireRappel(Request $requete): RappelFournisseur;

    /**
     * PAY-07 : renvoie l'argent sur le moyen d'origine. Idempotent par la clé
     * du remboursement. Le résultat arrive par rappel signé.
     *
     * @throws ErreurFournisseur
     */
    public function rembourser(Remboursement $remboursement): InitiationRemboursement;

    /**
     * PAY-06 : opérations du prestataire sur une journée (heure de Kinshasa).
     *
     * @return iterable<LigneReleveFournisseur>
     *
     * @throws ErreurFournisseur
     */
    public function releve(CarbonImmutable $jour): iterable;
}
