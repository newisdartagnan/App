<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Absence ponctuelle d'un médecin : congé, mission, garde ailleurs. Prime
 * sur les plages de présence hebdomadaires.
 */
class AbsenceMedecin extends Model
{
    use HasUuids;

    protected $table = 'absences_medecin';

    protected $fillable = ['user_id', 'debut', 'fin', 'motif'];

    protected function casts(): array
    {
        return ['debut' => 'date', 'fin' => 'date'];
    }

    public function medecin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function couvre(string $jour): bool
    {
        return $jour >= $this->debut->toDateString() && $jour <= $this->fin->toDateString();
    }
}
