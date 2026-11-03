<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Plage hebdomadaire : jour ISO (1 = lundi … 7 = dimanche), heures de Kinshasa. */
class PlageDisponibilite extends Model
{
    use HasUuids;

    protected $table = 'plages_disponibilite';

    protected $fillable = ['jour_semaine', 'heure_debut', 'heure_fin'];

    protected function casts(): array
    {
        return ['jour_semaine' => 'integer'];
    }

    public function medecin(): BelongsTo
    {
        return $this->belongsTo(Medecin::class);
    }
}
