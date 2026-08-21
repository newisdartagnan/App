<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Officine extends Model
{
    use HasUuids;

    protected $fillable = ['nom', 'type', 'service_id', 'est_actif'];

    protected function casts(): array
    {
        return ['est_actif' => 'boolean'];
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function stocks(): HasMany
    {
        return $this->hasMany(StockMedicament::class);
    }

    /**
     * Le dépôt central ne délivre jamais à un patient : il réapprovisionne
     * les officines, qui seules servent les ordonnances.
     */
    public function delivreAuxPatients(): bool
    {
        return $this->type !== 'depot_central';
    }

    public function estDepotCentral(): bool
    {
        return $this->type === 'depot_central';
    }

    /**
     * Officine qui doit servir une ordonnance, d'après le lieu de soins.
     *
     * Un patient vu en consultation externe passe à l'officine ambulatoire ;
     * un patient des urgences à l'officine des urgences ; un hospitalisé à
     * l'officine de son service. Jamais au dépôt central.
     */
    public static function pourVisite(?Visit $visite): ?self
    {
        $ambulatoire = self::where('type', 'ambulatoire')->where('est_actif', true)->first();

        if (! $visite) {
            return $ambulatoire;
        }

        // Aux urgences comme en hospitalisation, c'est l'officine du service
        // où le patient se trouve qui sert.
        if ($visite->service_id) {
            $officineService = self::where('type', 'service')
                ->where('service_id', $visite->service_id)
                ->where('est_actif', true)
                ->first();

            if ($officineService) {
                return $officineService;
            }
        }

        if ($visite->type === 'urgence') {
            $urgences = self::where('type', 'service')
                ->whereHas('service', fn ($q) => $q->where('type', 'urgence'))
                ->where('est_actif', true)
                ->first();

            if ($urgences) {
                return $urgences;
            }
        }

        return $ambulatoire;
    }
}
