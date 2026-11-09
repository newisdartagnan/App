<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** Ligne d'un bon d'examen : libellé et prix recopiés du catalogue, non modifiables. */
class LigneBonExamen extends Model
{
    use HasUuids;

    protected $table = 'lignes_bon_examen';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['prix' => 'decimal:2', 'rang' => 'integer'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Une ligne de bon d\'examen ne se modifie pas.'));
        static::deleting(fn () => throw new LogicException('Une ligne de bon d\'examen ne se supprime pas.'));
    }

    public function bon(): BelongsTo
    {
        return $this->belongsTo(BonExamen::class, 'bon_id');
    }

    public function examen(): BelongsTo
    {
        return $this->belongsTo(Examen::class);
    }
}
