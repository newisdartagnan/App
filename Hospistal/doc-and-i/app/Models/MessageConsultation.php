<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** Message patient–médecin d'une consultation (TLC-03). Donnée du dossier : non modifiable. */
class MessageConsultation extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    public const CREATED_AT = 'cree_le';

    protected $table = 'messages_consultation';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['cree_le' => 'immutable_datetime', 'piece_taille' => 'integer'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Un message de consultation ne se modifie pas.'));
        static::deleting(fn () => throw new LogicException('Un message de consultation ne se supprime pas.'));
    }

    public function consultation(): BelongsTo
    {
        return $this->belongsTo(Consultation::class);
    }

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'auteur_id');
    }
}
