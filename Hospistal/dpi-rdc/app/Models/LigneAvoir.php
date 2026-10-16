<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** Part d'une ligne de facture reprise par un avoir. */
class LigneAvoir extends Model
{
    use HasUuids;

    protected $table = 'lignes_avoir';

    public const UPDATED_AT = null;

    protected $fillable = [
        'avoir_id', 'ligne_facture_id', 'libelle',
        'montant', 'montant_patient', 'montant_assurance',
    ];

    protected function casts(): array
    {
        return [
            'montant' => 'decimal:2',
            'montant_patient' => 'decimal:2',
            'montant_assurance' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Une ligne d\'avoir ne se modifie pas.'));
        static::deleting(fn () => throw new LogicException('Une ligne d\'avoir ne se supprime pas.'));
    }

    public function avoir(): BelongsTo
    {
        return $this->belongsTo(Avoir::class);
    }

    public function ligneFacture(): BelongsTo
    {
        return $this->belongsTo(LigneFacture::class);
    }
}
