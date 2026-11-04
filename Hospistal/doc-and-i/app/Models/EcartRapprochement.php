<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** PAY-06 : écart entre nos paiements et le relevé de l'agrégateur, à traiter par la caisse. */
class EcartRapprochement extends Model
{
    use HasUuids;

    protected $table = 'ecarts_rapprochement';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'jour' => 'immutable_date',
            'montant_attendu' => 'decimal:2',
            'montant_releve' => 'decimal:2',
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
