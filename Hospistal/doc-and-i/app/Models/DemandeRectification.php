<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Dossier médical (DOS-01 à DOS-04). Écrit par les services du dossier seulement : rien n'est remplissable en masse. */
class DemandeRectification extends Model
{
    use HasUuids;

    protected $table = 'demandes_rectification';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'traitee_le' => 'immutable_datetime',
        ];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'patient_id');
    }
}
