<?php

namespace App\Services\Paiement\Fournisseur;

use App\Enums\StatutPaiement;
use App\Enums\StatutRemboursement;
use App\Models\Paiement;
use App\Models\Remboursement;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Développement et tests : aucun argent ne circule. Le débit est « accepté »
 * tout de suite ; le développeur déclenche ensuite un rappel signé, réussi
 * ou échoué, depuis la page de simulation (locale seulement). Le rappel
 * suit exactement le chemin d'un vrai rappel : signature vérifiée, même
 * contrôleur, mêmes services. Refusé en production (AppServiceProvider).
 */
final class FournisseurSimulation implements FournisseurPaiement
{
    public const NOM = 'simulation';

    public function __construct(private readonly SignatureRappel $signature) {}

    /** Seuls le poste du développeur et les tests peuvent simuler. */
    public static function environnementAutorise(): bool
    {
        return app()->environment(['local', 'testing']);
    }

    public function nom(): string
    {
        return self::NOM;
    }

    public function initier(Paiement $paiement): InitiationPaiement
    {
        return new InitiationPaiement(
            // Dérivée de la clé d'idempotence : une reprise rend la même référence.
            referenceFournisseur: 'SIM-P-'.substr(hash('sha256', $paiement->cle_idempotence), 0, 24),
            instructions: 'Simulation : aucun argent n\'est débité.',
        );
    }

    public function lireRappel(Request $requete): RappelFournisseur
    {
        $corps = $requete->getContent();
        $this->signature->verifier($corps, $requete->header(SignatureRappel::ENTETE));

        $donnees = json_decode($corps, true);
        if (! is_array($donnees)) {
            throw new RappelInvalide('Corps du rappel illisible.');
        }

        foreach (['evenement', 'type', 'reference_marchand', 'reference_fournisseur', 'statut', 'montant', 'devise'] as $champ) {
            if (! isset($donnees[$champ]) || ! is_string($donnees[$champ]) || $donnees[$champ] === '') {
                throw new RappelInvalide("Champ « {$champ} » absent du rappel.");
            }
        }

        if (! in_array($donnees['type'], [RappelFournisseur::PAIEMENT, RappelFournisseur::REMBOURSEMENT], true)
            || ! in_array($donnees['statut'], ['reussi', 'echoue'], true)
            || ! preg_match('/^\d{1,10}\.\d{2}$/', $donnees['montant'])
            || ! in_array($donnees['devise'], ['USD', 'CDF'], true)) {
            throw new RappelInvalide('Rappel mal formé.');
        }

        return new RappelFournisseur(
            identifiantEvenement: mb_substr($donnees['evenement'], 0, 100),
            type: $donnees['type'],
            referenceMarchand: mb_substr($donnees['reference_marchand'], 0, 100),
            referenceFournisseur: mb_substr($donnees['reference_fournisseur'], 0, 100),
            reussi: $donnees['statut'] === 'reussi',
            montant: $donnees['montant'],
            devise: $donnees['devise'],
            motifEchec: isset($donnees['motif']) && is_string($donnees['motif']) ? mb_substr($donnees['motif'], 0, 255) : null,
            chargeUtile: $donnees,
        );
    }

    public function rembourser(Remboursement $remboursement): InitiationRemboursement
    {
        return new InitiationRemboursement('SIM-R-'.substr(hash('sha256', $remboursement->cle_idempotence), 0, 24));
    }

    /** Le « relevé » de la simulation : ce que nos tables disent avoir réussi ce jour-là. */
    public function releve(CarbonImmutable $jour): iterable
    {
        [$debut, $fin] = [$jour->startOfDay(), $jour->endOfDay()];

        foreach (Paiement::where('fournisseur', self::NOM)->where('statut', StatutPaiement::Reussi->value)
            ->whereBetween('confirme_le', [$debut, $fin])->orderBy('id')->cursor() as $p) {
            yield new LigneReleveFournisseur(RappelFournisseur::PAIEMENT, (string) $p->reference_fournisseur, $p->cle_idempotence, (string) $p->montant, $p->devise, 'reussi');
        }

        foreach (Remboursement::where('statut', StatutRemboursement::Reussi->value)->whereNotNull('reference_fournisseur')
            ->whereHas('paiement', fn ($q) => $q->where('fournisseur', self::NOM))
            ->whereBetween('termine_le', [$debut, $fin])->orderBy('id')->cursor() as $r) {
            yield new LigneReleveFournisseur(RappelFournisseur::REMBOURSEMENT, (string) $r->reference_fournisseur, $r->cle_idempotence, (string) $r->montant, $r->devise, 'reussi');
        }
    }

    /**
     * Fabrique un rappel signé, comme l'enverrait le prestataire : pour la
     * page de simulation locale et pour les tests.
     *
     * @return array{corps: string, entete: string}
     */
    public function rappelSigne(string $type, string $referenceMarchand, string $referenceFournisseur, bool $reussi, string $montant, string $devise, ?string $motif = null, ?string $evenement = null): array
    {
        $corps = json_encode([
            'evenement' => $evenement ?? 'sim-evt-'.Str::uuid(),
            'type' => $type,
            'reference_marchand' => $referenceMarchand,
            'reference_fournisseur' => $referenceFournisseur,
            'statut' => $reussi ? 'reussi' : 'echoue',
            'montant' => $montant,
            'devise' => $devise,
            'motif' => $motif,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        return ['corps' => $corps, 'entete' => $this->signature->signer($corps)];
    }
}
