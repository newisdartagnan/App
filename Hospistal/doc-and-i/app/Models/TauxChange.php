<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** PAY-01 : francs congolais pour un dollar, un taux par jour. */
class TauxChange extends Model
{
    use HasUuids;

    protected $table = 'taux_change';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['jour' => 'immutable_date', 'cdf_par_usd' => 'decimal:4'];
    }

    public function saisiPar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'saisi_par');
    }
}
