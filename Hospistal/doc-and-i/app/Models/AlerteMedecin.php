<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Portail d'alertes des médecins (PRE-08) : résultat critique, résultat à valider, constante anormale. */
class AlerteMedecin extends Model
{
    use HasUuids;

    protected $table = 'alertes_medecin';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['lue_le' => 'immutable_datetime', 'traitee_le' => 'immutable_datetime', 'escaladee_le' => 'immutable_datetime'];
    }

    public function medecin(): BelongsTo
    {
        return $this->belongsTo(Medecin::class);
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'patient_id');
    }
}
