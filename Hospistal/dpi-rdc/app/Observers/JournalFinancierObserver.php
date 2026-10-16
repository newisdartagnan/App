<?php

namespace App\Observers;

use App\Models\AuditLog;
use App\Models\Avoir;
use App\Models\Caution;
use App\Models\Facture;
use App\Models\FactureConvention;
use App\Models\LigneAvoir;
use App\Models\Visit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

/**
 * Journal des pièces de caisse : factures, paiements, acomptes, avoirs,
 * bordereaux d'assureur.
 *
 * Contrairement au journal général, celui-ci s'écrit tout de suite, dans
 * la même transaction que l'écriture qu'il trace : une écriture de caisse
 * sans sa trace, ou une trace sans son écriture, ne doit pas pouvoir
 * exister. Chaque entrée dit qui, quand, depuis quel poste, ce qui valait
 * avant et ce qui vaut après — et à quelle facture elle se rattache.
 */
class JournalFinancierObserver
{
    /** Ce qui n'apporte rien au contrôle et alourdit chaque ligne. */
    private const IGNORES = ['updated_at', 'sync_status', 'password', 'remember_token', 'offline_token'];

    public function created(Model $model): void
    {
        $this->ecrire('create', $model, null, $this->nettoyer($model->getAttributes()));
    }

    public function updated(Model $model): void
    {
        $nouveau = $this->nettoyer($model->getChanges());

        // Une simple remise en file de synchronisation n'est pas un geste
        // de caisse.
        if ($nouveau === []) {
            return;
        }

        $ancien = array_intersect_key($model->getOriginal(), $nouveau);

        $this->ecrire('update', $model, $this->nettoyer($ancien), $nouveau);
    }

    public function deleted(Model $model): void
    {
        $this->ecrire('delete', $model, $this->nettoyer($model->getOriginal()), null);
    }

    private function ecrire(string $action, Model $model, ?array $ancien, ?array $nouveau): void
    {
        AuditLog::create([
            'user_id' => Auth::id(),
            'establishment_id' => $this->etablissement($model),
            'action' => "{$model->getTable()}.{$action}",
            'table_name' => $model->getTable(),
            'record_id' => $model->getKey(),
            'facture_id' => $this->facture($model),
            'ip_address' => Request::ip(),
            'user_agent' => Request::userAgent(),
            'old_values' => $ancien,
            'new_values' => $nouveau,
            'created_at' => now(),
        ]);
    }

    /** La facture à laquelle l'écriture se rattache, s'il y en a une. */
    private function facture(Model $model): ?string
    {
        return match (true) {
            $model instanceof Facture => $model->getKey(),
            $model instanceof FactureConvention => null,
            $model instanceof LigneAvoir => Avoir::whereKey($model->avoir_id)->value('facture_id'),
            // Le crédit né d'un avoir appartient au journal de la facture
            // annulée ; un acompte ordinaire n'a pas de facture.
            $model instanceof Caution => $model->avoir_id
                ? Avoir::whereKey($model->avoir_id)->value('facture_id')
                : null,
            default => $model->getAttribute('facture_id'),
        };
    }

    private function etablissement(Model $model): ?string
    {
        if ($id = $model->getAttribute('establishment_id')) {
            return $id;
        }

        if ($facture = $this->facture($model)) {
            return Facture::whereKey($facture)->value('establishment_id');
        }

        if ($visite = $model->getAttribute('visit_id')) {
            return Visit::whereKey($visite)->value('establishment_id');
        }

        return auth()->user()?->establishment_id;
    }

    /**
     * Les montants et les dates passent tels quels ; les tableaux sont
     * gardés lisibles.
     *
     * @param  array<string, mixed>  $valeurs
     * @return array<string, mixed>
     */
    private function nettoyer(array $valeurs): array
    {
        $propres = array_diff_key($valeurs, array_flip(self::IGNORES));

        return array_map(
            fn ($v) => is_string($v) && in_array(substr($v, 0, 1), ['[', '{'], true)
                ? (json_decode($v, true) ?? $v)
                : $v,
            $propres
        );
    }
}
