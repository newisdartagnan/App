<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Résultat déposé par le laboratoire (PRE-06). Le patient ne le voit
 * qu'une fois validé par le médecin (PRE-07, RG-10) ; validé, il ne change
 * plus (déclencheur en base).
 */
class ResultatExamen extends Model
{
    use HasUuids;

    protected $table = 'resultats_examen';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'valeurs' => 'array',
            'critique' => 'boolean',
            'depose_le' => 'immutable_datetime',
            'valide_le' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $resultat) {
            if ($resultat->getOriginal('valide_le') !== null) {
                throw new LogicException('Un résultat validé ne se modifie pas.');
            }
        });
        static::deleting(fn () => throw new LogicException('Un résultat ne se supprime pas.'));
    }

    public function estValide(): bool
    {
        return $this->valide_le !== null;
    }

    public function bon(): BelongsTo
    {
        return $this->belongsTo(BonExamen::class, 'bon_id');
    }
}
