<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Dossier médical (DOS-01 à DOS-04). Écrit par les services du dossier seulement : rien n'est remplissable en masse. */
class DossierPatient extends Model
{
    use HasUuids;

    public const GROUPES_SANGUINS = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];

    protected $table = 'dossiers_patients';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'groupe_sanguin_le' => 'immutable_datetime',
        ];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'patient_id');
    }
}
