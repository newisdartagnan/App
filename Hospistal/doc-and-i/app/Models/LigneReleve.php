<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** PAY-06 : une ligne du relevé de l'agrégateur, importée pour le rapprochement. */
class LigneReleve extends Model
{
    use HasUuids;

    protected $table = 'releves_fournisseur';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['jour' => 'immutable_date', 'montant' => 'decimal:2'];
    }
}
