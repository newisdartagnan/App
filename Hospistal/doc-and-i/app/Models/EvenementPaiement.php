<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Rappel serveur de l'opérateur, gardé une fois sa signature vérifiée (PAY-04, RG-02). */
class EvenementPaiement extends Model
{
    use HasUuids;

    protected $table = 'evenements_paiement';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'charge_utile' => 'array',
            'recu_le' => 'immutable_datetime',
            'traite_le' => 'immutable_datetime',
        ];
    }

    public function paiement(): BelongsTo
    {
        return $this->belongsTo(Paiement::class);
    }

    public function remboursement(): BelongsTo
    {
        return $this->belongsTo(Remboursement::class);
    }
}
