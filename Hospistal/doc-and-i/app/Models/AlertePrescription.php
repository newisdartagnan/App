<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Alerte de prescription maintenue par le médecin (PRE-02) : allergie connue
 * ou interaction majeure, pour une ligne d'ordonnance. Trace non modifiable.
 */
class AlertePrescription extends Model
{
    use HasUuids;

    protected $table = 'alertes_prescription';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['maintenue_le' => 'immutable_datetime', 'rang' => 'integer'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Une alerte maintenue ne se modifie pas.'));
        static::deleting(fn () => throw new LogicException('Une alerte maintenue ne se supprime pas.'));
    }

    public function ordonnance(): BelongsTo
    {
        return $this->belongsTo(Ordonnance::class);
    }
}
