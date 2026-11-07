<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Ligne du journal des accès au dossier (revue de sécurité) : qui a lu
 * quoi dans le dossier de quel patient, quand, d'où. Écrite par
 * JournalAccesDossier seulement ; non modifiable (déclencheur en base).
 */
class EntreeAccesDossier extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    public const CREATED_AT = 'cree_le';

    public const OBJETS = [
        'dossier' => 'Dossier médical',
        'dossier_appel' => 'Dossier pendant la consultation',
        'piece_jointe_appel' => 'Pièce jointe pendant la consultation',
    ];

    protected $table = 'acces_dossier';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['cree_le' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Le journal des accès au dossier ne se modifie pas.'));
        static::deleting(fn () => throw new LogicException('Le journal des accès au dossier ne se modifie pas.'));
    }

    public function lecteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'lecteur_id');
    }
}
