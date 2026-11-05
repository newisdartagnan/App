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
 * caisse. Un seul taux ACTIF par jour (heure de Kinshasa).
 *
 * Garde-fous (revue de sécurité) :
 * - bornes absolues (config/caisse.php) : une faute de frappe ne fait pas
 *   payer 1 CDF ;
 * - un écart de plus de 10 % avec le dernier taux des jours précédents
 *   attend la confirmation d'un SECOND agent ; d'ici là, pas de taux actif ;
 * - un taux déjà utilisé n'est jamais modifié (la base le garantit) : s'il
 *   est erroné, il est « remplacé » par un nouveau taux actif pour les
 *   paiements suivants, et reste dans l'historique.
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

        [$minimum, $maximum] = [(float) config('caisse.taux.minimum'), (float) config('caisse.taux.maximum')];
        if ((float) $taux < $minimum || (float) $taux > $maximum) {
            throw new PaiementRefuse(sprintf('Taux hors des bornes admises (%s à %s CDF pour un dollar) : vérifiez la saisie.', $minimum, $maximum));
        }

        $jour = self::aujourdhui();

        return DB::transaction(function () use ($taux, $jour, $agent) {
            // Verrou du jour : deux saisies simultanées ne créent pas deux taux.
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['taux:'.$jour]);

            if ($this->ecartTropGrand($taux, $jour)) {
                TauxChange::where('jour', $jour)->where('statut', 'a_confirmer')->delete();

                $enAttente = new TauxChange;
                $enAttente->forceFill(['jour' => $jour, 'cdf_par_usd' => $taux, 'saisi_par' => $agent->id, 'statut' => 'a_confirmer'])->save();

                return $enAttente;
            }

            return $this->activer($jour, $taux, $agent);
        });
    }

    /**
     * Second agent : confirme un taux en attente. Jamais celui qui l'a saisi.
     *
     * @throws PaiementRefuse
     */
    public function confirmer(TauxChange $taux, User $agent): TauxChange
    {
        $jour = self::aujourdhui();

        return DB::transaction(function () use ($taux, $agent, $jour) {
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['taux:'.$jour]);
            $enAttente = TauxChange::whereKey($taux->id)->lockForUpdate()->firstOrFail();

            if ($enAttente->statut !== 'a_confirmer') {
                throw new PaiementRefuse('Ce taux n\'attend plus de confirmation.');
            }

            if ($enAttente->jour->toDateString() !== $jour) {
                throw new PaiementRefuse('Ce taux était celui d\'un autre jour : saisissez le taux d\'aujourd\'hui.');
            }

            if ($enAttente->saisi_par === $agent->id) {
                throw new PaiementRefuse('Vous avez saisi ce taux : un autre agent de caisse doit le confirmer.');
            }

            return $this->activer($jour, (string) $enAttente->cdf_par_usd, $agent, $enAttente);
        });
    }

    public function estUtilise(TauxChange $taux): bool
    {
        return Paiement::where('taux_change_id', $taux->id)->exists();
    }

    /** Taux en attente de confirmation aujourd'hui, s'il y en a un. */
    public function enAttente(): ?TauxChange
    {
        return TauxChange::where('jour', self::aujourdhui())->where('statut', 'a_confirmer')->first();
    }

    /**
     * Rend ce taux actif pour aujourd'hui. Un taux actif encore inutilisé
     * est corrigé sur place ; déjà utilisé, il est remplacé (gardé tel quel).
     */
    private function activer(string $jour, string $taux, User $agent, ?TauxChange $enAttente = null): TauxChange
    {
        $actif = TauxChange::where('jour', $jour)->where('statut', 'actif')->lockForUpdate()->first();

        if ($actif !== null && $enAttente === null && ! $this->estUtilise($actif)) {
            $actif->forceFill(['cdf_par_usd' => $taux, 'saisi_par' => $agent->id, 'confirme_par' => null, 'confirme_le' => null])->save();
            TauxChange::where('jour', $jour)->where('statut', 'a_confirmer')->delete();

            return $actif;
        }

        // L'ancien n'est ni modifié ni supprimé : il reste dans l'historique, et
        // les paiements qui l'ont utilisé gardent leur montant.
        if ($actif !== null) {
            $actif->forceFill(['statut' => 'remplace'])->save();
        }

        if ($enAttente !== null) {
            $enAttente->forceFill(['statut' => 'actif', 'confirme_par' => $agent->id, 'confirme_le' => CarbonImmutable::now()])->save();

            return $enAttente;
        }

        TauxChange::where('jour', $jour)->where('statut', 'a_confirmer')->delete();
        $nouveau = new TauxChange;
        $nouveau->forceFill(['jour' => $jour, 'cdf_par_usd' => $taux, 'saisi_par' => $agent->id, 'statut' => 'actif'])->save();

        return $nouveau;
    }

    /** Écart avec le dernier taux actif des jours précédents (celui du jour peut être l'erreur à corriger). */
    private function ecartTropGrand(string $taux, string $jour): bool
    {
        $reference = TauxChange::where('statut', 'actif')->where('jour', '<', $jour)->orderByDesc('jour')->value('cdf_par_usd');
        if ($reference === null) {
            return false;
        }

        $ecart = abs((float) $taux - (float) $reference) / (float) $reference * 100;

        return $ecart > (float) config('caisse.taux.ecart_confirmation_pourcent');
    }

    private static function aujourdhui(): string
    {
        return CarbonImmutable::now('Africa/Kinshasa')->toDateString();
    }
}
