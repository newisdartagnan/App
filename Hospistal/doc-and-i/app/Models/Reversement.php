<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** PAY-09 : honoraires d'un médecin sur une période, préparés puis payés par deux personnes. */
class Reversement extends Model
{
    use HasUuids;

    protected $table = 'reversements';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'periode_debut' => 'immutable_date',
            'periode_fin' => 'immutable_date',
            'montant_brut' => 'decimal:2',
            'commission' => 'decimal:2',
            'montant_net' => 'decimal:2',
            'paye_le' => 'immutable_datetime',
        ];
    }

    public function medecin(): BelongsTo
    {
        return $this->belongsTo(Medecin::class);
    }
}
