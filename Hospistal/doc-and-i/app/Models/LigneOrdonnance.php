<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** Ligne d'une ordonnance (PRE-01) : médicament, dosage, voie, posologie, durée, consignes. */
class LigneOrdonnance extends Model
{
    use HasUuids;

    public const CHAMPS = ['medicament', 'dosage', 'voie', 'posologie', 'duree', 'consignes'];

    protected $table = 'lignes_ordonnance';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['rang' => 'integer'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Une ligne d\'ordonnance ne se modifie pas.'));
        static::deleting(fn () => throw new LogicException('Une ligne d\'ordonnance ne se supprime pas.'));
    }

    public function ordonnance(): BelongsTo
    {
        return $this->belongsTo(Ordonnance::class);
    }
}
