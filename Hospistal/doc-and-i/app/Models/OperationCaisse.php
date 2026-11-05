<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** Ligne du journal de caisse (PAY-09), écrite par JournalCaisse seulement. Non modifiable. */
class OperationCaisse extends Model
{
    use HasUuids;

    protected $table = 'operations_caisse';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['montant' => 'decimal:2', 'jour' => 'immutable_date'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Le journal de caisse ne se modifie pas.'));
        static::deleting(fn () => throw new LogicException('Le journal de caisse ne se modifie pas.'));
    }

    public function paiement(): BelongsTo
    {
        return $this->belongsTo(Paiement::class);
    }

    public function remboursement(): BelongsTo
    {
        return $this->belongsTo(Remboursement::class);
    }

    public function medecin(): BelongsTo
    {
        return $this->belongsTo(Medecin::class);
    }
}
