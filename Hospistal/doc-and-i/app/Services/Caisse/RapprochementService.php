<?php

namespace App\Services\Caisse;

use App\Enums\StatutPaiement;
use App\Enums\StatutRemboursement;
use App\Models\EcartRapprochement;
use App\Models\LigneReleve;
use App\Models\Paiement;
use App\Models\Remboursement;
use App\Models\User;
use App\Services\Paiement\Fournisseur\FournisseurPaiement;
use App\Services\Paiement\Fournisseur\LigneReleveFournisseur;
use App\Services\Paiement\Fournisseur\RappelFournisseur;
use App\Services\Paiement\PaiementRefuse;
use App\Support\Montant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * PAY-06 : rapprochement quotidien avec le relevé de l'agrégateur.
 *
 * Le relevé d'un jour est importé (idempotent : une ligne par référence du
 * prestataire), puis comparé à nos paiements et remboursements réussis ce
 * jour-là. Chaque différence devient un écart à traiter par la caisse :
 * absent chez nous, absent du relevé, montant différent, statut différent.
 * Relancé, le rapprochement ne recrée aucun écart (index unique en base).
 */
final class RapprochementService
{
    public const FUSEAU = 'Africa/Kinshasa';

    /** @return array{lignes: int, ecarts: int} nombre de lignes du relevé, nombre d'écarts NOUVEAUX */
    public function rapprocher(CarbonImmutable $jour): array
    {
        $fournisseur = app(FournisseurPaiement::class);
        $nom = $fournisseur->nom();
        $jour = CarbonImmutable::parse($jour->format('Y-m-d'), self::FUSEAU);

        // Appel au prestataire hors transaction : aucun verrou tenu pendant l'attente.
        /** @var list<LigneReleveFournisseur> $releve */
        $releve = iterator_to_array($fournisseur->releve($jour), false);

        return DB::transaction(function () use ($nom, $jour, $releve) {
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['rapprochement:'.$nom.':'.$jour->toDateString()]);
            $maintenant = CarbonImmutable::now();

            foreach ($releve as $ligne) {
                DB::table('releves_fournisseur')->upsert([[
                    'id' => (string) Str::uuid(),
                    'fournisseur' => $nom,
                    'jour' => $jour->toDateString(),
                    'type' => $ligne->type,
                    'reference_fournisseur' => mb_substr($ligne->referenceFournisseur, 0, 100),
                    'reference_marchand' => $ligne->referenceMarchand === null ? null : mb_substr($ligne->referenceMarchand, 0, 100),
                    'montant' => Montant::depuisCentimes(Montant::enCentimes($ligne->montant)),
                    'devise' => $ligne->devise,
                    'statut' => $ligne->statut,
                    'created_at' => $maintenant,
                    'updated_at' => $maintenant,
                ]], ['fournisseur', 'type', 'reference_fournisseur'], ['jour', 'reference_marchand', 'montant', 'devise', 'statut', 'updated_at']);
            }

            $lignes = LigneReleve::where('fournisseur', $nom)->where('jour', $jour->toDateString())->orderBy('id')->get();
            [$debut, $fin] = [$jour->startOfDay(), $jour->endOfDay()];

            $ecarts = $this->comparer(
                $nom, $jour, $lignes->where('type', RappelFournisseur::PAIEMENT),
                Paiement::where('fournisseur', $nom)->where('statut', StatutPaiement::Reussi->value)->whereBetween('confirme_le', [$debut, $fin])->get(),
                fn (LigneReleve $l) => Paiement::where('fournisseur', $nom)->where('reference_fournisseur', $l->reference_fournisseur)->first()
                    ?? ($l->reference_marchand ? Paiement::where('fournisseur', $nom)->where('cle_idempotence', $l->reference_marchand)->first() : null),
                fn (Paiement $p) => $p->statut === StatutPaiement::Reussi,
                'paiement_id',
            ) + $this->comparer(
                $nom, $jour, $lignes->where('type', RappelFournisseur::REMBOURSEMENT),
                Remboursement::where('statut', StatutRemboursement::Reussi->value)->whereBetween('termine_le', [$debut, $fin])
                    ->whereHas('paiement', fn ($q) => $q->where('fournisseur', $nom))->get(),
                fn (LigneReleve $l) => Remboursement::where('reference_fournisseur', $l->reference_fournisseur)->whereHas('paiement', fn ($q) => $q->where('fournisseur', $nom))->first()
                    ?? ($l->reference_marchand ? Remboursement::where('cle_idempotence', $l->reference_marchand)->first() : null),
                fn (Remboursement $r) => $r->statut === StatutRemboursement::Reussi,
                'remboursement_id',
            );

            return ['lignes' => count($releve), 'ecarts' => $ecarts];
        });
    }

    /** Traité avec un commentaire obligatoire ; un écart traité ne se rouvre pas. */
    public function traiter(EcartRapprochement $ecart, User $agent, string $commentaire): EcartRapprochement
    {
        $commentaire = trim($commentaire);
        if ($commentaire === '') {
            throw new PaiementRefuse('Expliquez comment l\'écart a été traité.');
        }

        return DB::transaction(function () use ($ecart, $agent, $commentaire) {
            $e = EcartRapprochement::whereKey($ecart->id)->lockForUpdate()->firstOrFail();
            if ($e->statut !== 'ouvert') {
                throw new PaiementRefuse('Cet écart a déjà été traité.');
            }

            $e->forceFill([
                'statut' => 'traite',
                'traite_par' => $agent->id,
                'traite_le' => CarbonImmutable::now(),
                'commentaire' => mb_substr($commentaire, 0, 500),
            ])->save();

            return $e;
        });
    }

    /**
     * Nombre d'écarts créés, ou 0 s'ils existaient déjà.
     *
     * @param  iterable<LigneReleve>  $releve
     * @param  iterable<Paiement|Remboursement>  $nosOperations  réussies ce jour-là
     */
    private function comparer(string $nom, CarbonImmutable $jour, iterable $releve, iterable $nosOperations, callable $retrouver, callable $estReussi, string $colonne): int
    {
        $crees = 0;
        $vus = [];

        foreach ($releve as $ligne) {
            $notre = $retrouver($ligne);

            if ($notre === null) {
                $crees += $this->ecart($nom, $jour, 'absent_chez_nous', null, null, $ligne->reference_fournisseur, null, (string) $ligne->montant, $ligne->devise);

                continue;
            }

            $vus[$notre->id] = true;
            $paiementId = $colonne === 'paiement_id' ? $notre->id : null;
            $remboursementId = $colonne === 'remboursement_id' ? $notre->id : null;

            $nature = match (true) {
                ($ligne->statut === 'reussi') !== $estReussi($notre) => 'statut_different',
                $ligne->devise !== $notre->devise,
                Montant::enCentimes((string) $ligne->montant) !== Montant::enCentimes((string) $notre->montant) => 'montant_different',
                default => null,
            };

            if ($nature !== null) {
                $crees += $this->ecart($nom, $jour, $nature, $paiementId, $remboursementId, $ligne->reference_fournisseur,
                    (string) $notre->montant, (string) $ligne->montant, $ligne->devise);
            }
        }

        foreach ($nosOperations as $notre) {
            if (! isset($vus[$notre->id])) {
                $crees += $this->ecart($nom, $jour, 'absent_du_releve',
                    $colonne === 'paiement_id' ? $notre->id : null, $colonne === 'remboursement_id' ? $notre->id : null,
                    $notre->reference_fournisseur, (string) $notre->montant, null, $notre->devise);
            }
        }

        return $crees;
    }

    private function ecart(string $nom, CarbonImmutable $jour, string $nature, ?string $paiementId, ?string $remboursementId, ?string $reference, ?string $attendu, ?string $releve, ?string $devise): int
    {
        $maintenant = CarbonImmutable::now();

        // Index unique (jour, fournisseur, nature, paiement, remboursement, référence) :
        // une relance n'insère rien.
        return DB::table('ecarts_rapprochement')->insertOrIgnore([
            'id' => (string) Str::uuid(),
            'jour' => $jour->toDateString(),
            'fournisseur' => $nom,
            'nature' => $nature,
            'paiement_id' => $paiementId,
            'remboursement_id' => $remboursementId,
            'reference_fournisseur' => $reference,
            'montant_attendu' => $attendu,
            'montant_releve' => $releve,
            'devise' => $devise,
            'statut' => 'ouvert',
            'created_at' => $maintenant,
            'updated_at' => $maintenant,
        ]);
    }
}
