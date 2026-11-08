<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * Note de consultation structurée (DOS-02). Brouillon modifiable par le
 * médecin du rendez-vous ; signée, elle ne change plus (RG-11, déclencheur
 * en base) : une correction est un avenant.
 */
class NoteConsultation extends Model
{
    use HasUuids;

    public const CHAMPS = ['motif', 'examen', 'hypotheses', 'diagnostic_code', 'diagnostic_libelle', 'conduite'];

    protected $table = 'notes_consultation';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['signee_le' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $note) {
            if ($note->getOriginal('signee_le') !== null) {
                throw new LogicException('Une note signée ne se modifie pas : rédiger un avenant.');
            }
        });
        static::deleting(fn () => throw new LogicException('Une note de consultation ne se supprime pas.'));
    }

    public function estSignee(): bool
    {
        return $this->signee_le !== null;
    }

    public function rendezVous(): BelongsTo
    {
        return $this->belongsTo(RendezVous::class);
    }

    public function medecin(): BelongsTo
    {
        return $this->belongsTo(Medecin::class);
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'patient_id');
    }

    public function avenants(): HasMany
    {
        return $this->hasMany(AvenantNote::class, 'note_id')->orderBy('signe_le');
    }
}
