<?php

namespace App\Services\Caisse;

use App\Models\Paiement;
use App\Models\TauxChange;
use App\Models\User;
use App\Services\Paiement\PaiementRefuse;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * PAY-01 : taux du jour (francs congolais pour un dollar), saisi par la
 * caisse. Un seul par jour (heure de Kinshasa) ; corrigeable tant qu'aucun
 * paiement ne l'a utilisé, figé ensuite (la base le garantit aussi).
 */
final class TauxDuJourService
{
    public const FORMAT = '/^\d{1,8}(\.\d{1,4})?$/';

    /** @throws PaiementRefuse */
    public function enregistrer(string $saisie, User $agent): TauxChange
    {
        $taux = str_replace(',', '.', trim($saisie));
        if (preg_match(self::FORMAT, $taux) !== 1 || (float) $taux <= 0) {
            throw new PaiementRefuse('Taux illisible : saisissez les francs congolais pour un dollar (exemple : 2850 ou 2850,50).');
        }

        $jour = CarbonImmutable::now('Africa/Kinshasa')->toDateString();

        return DB::transaction(function () use ($taux, $jour, $agent) {
            // Verrou du jour : deux saisies simultanées ne créent pas deux taux.
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['taux:'.$jour]);
            $existant = TauxChange::where('jour', $jour)->lockForUpdate()->first();

            if ($existant !== null && Paiement::where('taux_change_id', $existant->id)->exists()) {
                throw new PaiementRefuse('Le taux du jour a déjà servi à un paiement : il ne peut plus être modifié.');
            }

            $ligne = $existant ?? new TauxChange;
            $ligne->forceFill(['jour' => $jour, 'cdf_par_usd' => $taux, 'saisi_par' => $agent->id])->save();

            return $ligne;
        });
    }

    public function estUtilise(TauxChange $taux): bool
    {
        return Paiement::where('taux_change_id', $taux->id)->exists();
    }
}
