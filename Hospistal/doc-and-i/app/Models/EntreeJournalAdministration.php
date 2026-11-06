<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** Une ligne du journal d'administration. Écrite par JournalAdministration seulement ; non modifiable. */
class EntreeJournalAdministration extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    public const CREATED_AT = 'cree_le';

    protected $table = 'journal_administration';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['details' => 'array', 'cree_le' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Le journal d\'administration ne se modifie pas.'));
        static::deleting(fn () => throw new LogicException('Le journal d\'administration ne se modifie pas.'));
    }

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'auteur_id');
    }
}
