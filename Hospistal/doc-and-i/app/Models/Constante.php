<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Dossier médical (DOS-01 à DOS-04). Écrit par les services du dossier seulement : rien n'est remplissable en masse. */
class Constante extends Model
{
    use HasUuids;

    protected $table = 'constantes';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'mesure_le' => 'immutable_datetime',
            'valeur' => 'decimal:2',
            'valeur2' => 'decimal:2',
        ];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'patient_id');
    }
}
