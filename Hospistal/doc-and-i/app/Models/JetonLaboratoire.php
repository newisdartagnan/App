<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Accès API d'un laboratoire partenaire (dépôt de résultats depuis son logiciel). Seule l'empreinte du jeton est gardée. */
class JetonLaboratoire extends Model
{
    use HasUuids;

    protected $table = 'jetons_laboratoire';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['dernier_usage_le' => 'immutable_datetime', 'revoque_le' => 'immutable_datetime'];
    }

    public function laboratoire(): BelongsTo
    {
        return $this->belongsTo(Partenaire::class, 'partenaire_id');
    }
}
