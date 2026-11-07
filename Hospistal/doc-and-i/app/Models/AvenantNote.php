<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** Avenant daté et signé à une note de consultation (RG-11). Non modifiable. */
class AvenantNote extends Model
{
    use HasUuids;

    protected $table = 'avenants_note';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['signe_le' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Un avenant ne se modifie pas.'));
        static::deleting(fn () => throw new LogicException('Un avenant ne se supprime pas.'));
    }

    public function note(): BelongsTo
    {
        return $this->belongsTo(NoteConsultation::class, 'note_id');
    }

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'auteur_id');
    }
}
