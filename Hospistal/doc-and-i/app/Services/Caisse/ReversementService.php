<?php

namespace App\Services\Caisse;

use App\Models\Medecin;
use App\Models\OperationCaisse;
use App\Models\Reversement;
use App\Models\User;
use App\Services\Paiement\PaiementRefuse;
use App\Services\RendezVous\Affichage;
use App\Support\Montant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * PAY-09 : honoraires reversés aux médecins. Préparés par un agent de caisse
 * pour un médecin et une période close (encaissements moins remboursements
 * des rendez-vous du médecin, par devise, moins la commission de la
 * plateforme), puis payés par un AUTRE agent, avec la référence du virement.
 */
final class ReversementService
{
    /**
     * Un reversement par devise ayant un solde positif sur la période.
     *
     * @return list<Reversement>
     *
     * @throws PaiementRefuse
     */
    public function preparer(Medecin $medecin, CarbonImmutable $debut, CarbonImmutable $fin, User $agent): array
    {
        $commissionCentiemes = self::commissionEnCentiemesDePourcent();
        [$debut, $fin] = [$debut->startOfDay(), $fin->startOfDay()];

        if ($fin < $debut) {
            throw new PaiementRefuse('La fin de la période doit suivre son début.');
        }

        if ($fin >= CarbonImmutable::now('Africa/Kinshasa')->startOfDay()) {
            throw new PaiementRefuse('La période doit être close : elle se termine au plus tard hier.');
        }

        return DB::transaction(function () use ($medecin, $debut, $fin, $agent, $commissionCentiemes) {
            // Deux préparations simultanées pour un même médecin se suivent.
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['reversement:medecin:'.$medecin->id]);

            $dejaReverse = Reversement::where('medecin_id', $medecin->id)
                ->where('periode_debut', '<=', $fin->toDateString())
                ->where('periode_fin', '>=', $debut->toDateString())
                ->exists();
            if ($dejaReverse) {
                throw new PaiementRefuse('Cette période, ou une partie, a déjà un reversement pour ce médecin.');
            }

            $soldes = OperationCaisse::where('medecin_id', $medecin->id)
                ->whereBetween('jour', [$debut->toDateString(), $fin->toDateString()])
                ->toBase()->selectRaw('devise, SUM(montant) AS solde')->groupBy('devise')->orderBy('devise')->get();

            $reversements = [];
            foreach ($soldes as $solde) {
                $brut = Montant::enCentimes((string) $solde->solde);
                if ($brut <= 0) {
                    continue;
                }

                // Arrondie au centime inférieur : au profit du médecin.
                $commission = intdiv($brut * $commissionCentiemes, 10000);

                $reversement = new Reversement;
                $reversement->forceFill([
                    'medecin_id' => $medecin->id,
                    'periode_debut' => $debut->toDateString(),
                    'periode_fin' => $fin->toDateString(),
                    'devise' => $solde->devise,
                    'montant_brut' => Montant::depuisCentimes($brut),
                    'commission' => Montant::depuisCentimes($commission),
                    'montant_net' => Montant::depuisCentimes($brut - $commission),
                    'statut' => 'prepare',
                    'prepare_par' => $agent->id,
                ])->save();
                $reversements[] = $reversement;
            }

            if ($reversements === []) {
                throw new PaiementRefuse('Rien à reverser à ce médecin sur cette période.');
            }

            return $reversements;
        });
    }

    /**
     * Quatre yeux : payé par un autre agent que celui qui l'a préparé (la
     * base le garantit aussi). Un second paiement est refusé.
     *
     * @throws PaiementRefuse
     */
    public function payer(Reversement $reversement, User $agent, string $referenceVirement): Reversement
    {
        $reference = trim($referenceVirement);
        if ($reference === '' || mb_strlen($reference) > 100) {
            throw new PaiementRefuse('La référence du virement est obligatoire (100 caractères au plus).');
        }

        return DB::transaction(function () use ($reversement, $agent, $reference) {
            $r = Reversement::whereKey($reversement->id)->lockForUpdate()->firstOrFail();

            if ($r->statut !== 'prepare') {
                throw new PaiementRefuse('Ce reversement est déjà payé.');
            }

            if ($r->prepare_par === $agent->id) {
                throw new PaiementRefuse('Vous avez préparé ce reversement : un autre agent de caisse doit le payer.');
            }

            $r->forceFill([
                'statut' => 'paye',
                'paye_par' => $agent->id,
                'paye_le' => CarbonImmutable::now(),
                'reference_virement' => $reference,
            ])->save();

            return $r;
        });
    }

    /** @return list<array<string, mixed>> */
    public function lister(?Medecin $medecin = null, int $limite = 100): array
    {
        return Reversement::with('medecin.user')
            ->when($medecin, fn ($q) => $q->where('medecin_id', $medecin->id))
            ->orderByDesc('periode_fin')->orderBy('devise')->limit($limite)->get()
            ->map(fn (Reversement $r) => [
                'id' => $r->id,
                'medecin' => Affichage::medecin($r->medecin),
                'periode' => 'Du '.$r->periode_debut->format('d/m/Y').' au '.$r->periode_fin->format('d/m/Y'),
                'devise' => $r->devise,
                'brut' => Montant::afficher((string) $r->montant_brut, $r->devise),
                'commission' => Montant::afficher((string) $r->commission, $r->devise),
                'net' => Montant::afficher((string) $r->montant_net, $r->devise),
                'statut' => $r->statut === 'paye' ? 'Payé' : 'Préparé, à payer',
                'paye' => $r->statut === 'paye',
                'payeLe' => $r->paye_le?->setTimezone('Africa/Kinshasa')->format('d/m/Y H:i'),
                'referenceVirement' => $r->reference_virement,
                'preparePar' => $r->prepare_par,
            ])->all();
    }

    /**
     * Commission de la plateforme en centièmes de pour cent (12,5 % → 1250).
     * Pas de valeur par défaut : c'est une décision du client.
     *
     * @throws PaiementRefuse
     */
    public static function commissionEnCentiemesDePourcent(): int
    {
        $valeur = config('doc-and-i.paiement.commission_pourcent');
        $valeur = $valeur === null ? '' : str_replace(',', '.', trim((string) $valeur));

        if (preg_match('/^(\d{1,3})(?:\.(\d{1,2}))?$/', $valeur, $m) !== 1) {
            throw new PaiementRefuse('La commission de la plateforme n\'est pas configurée (DOC_AND_I_COMMISSION_POURCENT) : aucun reversement possible.');
        }

        $centiemes = (int) $m[1] * 100 + (int) str_pad($m[2] ?? '0', 2, '0');
        if ($centiemes > 10000) {
            throw new PaiementRefuse('La commission de la plateforme dépasse 100 % : vérifier DOC_AND_I_COMMISSION_POURCENT.');
        }

        return $centiemes;
    }
}
