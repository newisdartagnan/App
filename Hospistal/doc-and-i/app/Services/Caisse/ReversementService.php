<?php

namespace App\Services\Caisse;

use App\Enums\StatutRendezVous;
use App\Models\Medecin;
use App\Models\OperationCaisse;
use App\Models\Reversement;
use App\Models\User;
use App\Services\Paiement\PaiementRefuse;
use App\Services\RendezVous\Affichage;
use App\Support\Montant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * PAY-09 : honoraires reversés aux médecins. Préparés par un agent de caisse
 * pour un médecin et une période close, puis payés par un AUTRE agent, avec
 * la référence du virement.
 *
 * Ce qui entre dans un reversement (revue de sécurité) :
 * - les encaissements de rendez-vous commencés avant la fin de la période et
 *   non annulés (ni annulés, ni expirés, ni manqués par le médecin), pas
 *   encore reversés : un encaissement d'avance attend que le rendez-vous ait
 *   eu lieu, il n'est jamais oublié ;
 * - les remboursements dont l'encaissement est reversé (avant ou maintenant) :
 *   un remboursement APRÈS reversement est imputé au reversement suivant ;
 * - chaque mouvement n'entre que dans un reversement (table de liaison, base) ;
 * - un solde négatif, par devise, devient une dette reportée sur le
 *   reversement suivant du médecin : le net n'est jamais négatif, la dette
 *   jamais abandonnée.
 */
final class ReversementService
{
    /** Rendez-vous qui n'ont pas eu lieu : leurs encaissements ne sont pas des honoraires. */
    private const RENDEZ_VOUS_NON_HONORES = [
        StatutRendezVous::AnnuleParPatient, StatutRendezVous::AnnuleParMedecin,
        StatutRendezVous::Expire, StatutRendezVous::AbsenceMedecin,
    ];

    /**
     * Un reversement par devise ayant des mouvements à reverser.
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

            $mouvements = $this->mouvementsAReverser($medecin, $fin);
            if ($mouvements->isEmpty()) {
                throw new PaiementRefuse('Rien à reverser à ce médecin sur cette période.');
            }

            $reversements = [];
            foreach ($mouvements->groupBy('devise') as $devise => $operations) {
                $solde = $operations->sum(fn (OperationCaisse $o) => Montant::enCentimes((string) $o->montant));
                $detteReprise = Montant::enCentimes((string) (Reversement::where('medecin_id', $medecin->id)->where('devise', $devise)
                    ->orderByDesc('periode_fin')->value('dette_reportee') ?? '0'));

                $brut = max(0, $solde - $detteReprise);
                // Arrondie au centime inférieur : au profit du médecin.
                $commission = intdiv($brut * $commissionCentiemes, 10000);
                $net = $brut - $commission;

                $reversement = new Reversement;
                $reversement->forceFill([
                    'medecin_id' => $medecin->id,
                    'periode_debut' => $debut->toDateString(),
                    'periode_fin' => $fin->toDateString(),
                    'devise' => $devise,
                    'solde_periode' => Montant::depuisCentimes($solde),
                    'dette_reprise' => Montant::depuisCentimes($detteReprise),
                    'dette_reportee' => Montant::depuisCentimes(max(0, $detteReprise - $solde)),
                    'montant_brut' => Montant::depuisCentimes($brut),
                    'commission' => Montant::depuisCentimes($commission),
                    'montant_net' => Montant::depuisCentimes($net),
                    'statut' => $net > 0 ? 'prepare' : 'sans_versement',
                    'prepare_par' => $agent->id,
                ])->save();

                $maintenant = CarbonImmutable::now();
                DB::table('reversement_operations')->insert($operations->map(fn (OperationCaisse $o) => [
                    'id' => (string) Str::uuid(),
                    'reversement_id' => $reversement->id,
                    'operation_caisse_id' => $o->id,
                    'created_at' => $maintenant,
                    'updated_at' => $maintenant,
                ])->all());

                $reversements[] = $reversement;
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

            if ($r->statut === 'sans_versement') {
                throw new PaiementRefuse('Rien à verser sur ce reversement : la dette du médecin est reportée.');
            }

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
        $siNonNul = fn (string $montant, string $devise) => Montant::enCentimes($montant) === 0 ? null : Montant::afficher($montant, $devise);

        return Reversement::with('medecin.user')
            ->when($medecin, fn ($q) => $q->where('medecin_id', $medecin->id))
            ->orderByDesc('periode_fin')->orderBy('devise')->limit($limite)->get()
            ->map(fn (Reversement $r) => [
                'id' => $r->id,
                'medecin' => Affichage::medecin($r->medecin),
                'periode' => 'Du '.$r->periode_debut->format('d/m/Y').' au '.$r->periode_fin->format('d/m/Y'),
                'devise' => $r->devise,
                'solde' => Montant::afficher((string) $r->solde_periode, $r->devise),
                'detteReprise' => $siNonNul((string) $r->dette_reprise, $r->devise),
                'detteReportee' => $siNonNul((string) $r->dette_reportee, $r->devise),
                'brut' => Montant::afficher((string) $r->montant_brut, $r->devise),
                'commission' => Montant::afficher((string) $r->commission, $r->devise),
                'net' => Montant::afficher((string) $r->montant_net, $r->devise),
                'statut' => match ($r->statut) {
                    'paye' => 'Payé',
                    'sans_versement' => 'Rien à verser (dette reportée)',
                    default => 'Préparé, à payer',
                },
                'paye' => $r->statut === 'paye',
                'aPayer' => $r->statut === 'prepare',
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

    /**
     * Mouvements du médecin, jusqu'à la fin de la période, pas encore reversés :
     * encaissements de rendez-vous honorés et commencés, et remboursements
     * dont l'encaissement est (ou va être) reversé.
     *
     * @return Collection<int, OperationCaisse>
     */
    private function mouvementsAReverser(Medecin $medecin, CarbonImmutable $fin): Collection
    {
        $dejaReverses = DB::table('reversement_operations')->select('operation_caisse_id');
        $finPeriode = $fin->endOfDay();

        $encaissements = OperationCaisse::where('medecin_id', $medecin->id)
            ->where('type', 'encaissement')
            ->where('jour', '<=', $fin->toDateString())
            ->whereNotIn('id', $dejaReverses)
            ->whereHas('paiement.rendezVous', fn ($q) => $q->where('debut', '<=', $finPeriode)
                ->whereNotIn('statut', array_map(fn (StatutRendezVous $s) => $s->value, self::RENDEZ_VOUS_NON_HONORES)))
            ->get();

        $paiementsDejaReverses = OperationCaisse::where('type', 'encaissement')->whereIn('id', $dejaReverses)->select('paiement_id');

        $remboursements = OperationCaisse::where('medecin_id', $medecin->id)
            ->where('type', 'remboursement')
            ->where('jour', '<=', $fin->toDateString())
            ->whereNotIn('id', $dejaReverses)
            ->where(fn ($q) => $q->whereIn('paiement_id', $encaissements->pluck('paiement_id'))->orWhereIn('paiement_id', $paiementsDejaReverses))
            ->get();

        return $encaissements->concat($remboursements)->sortBy('devise')->values();
    }
}
