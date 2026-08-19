<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Ligne du plan d'administration des traitements (MAR) : un traitement pour
 * un jour donné, avec les heures d'administration prévues.
 */
class PlanAdministration extends Model
{
    use HasUuids;

    protected $table = 'plans_administration';

    protected $fillable = [
        'visit_id', 'ligne_prescription_id', 'libelle', 'jour', 'heures', 'cree_par',
    ];

    protected function casts(): array
    {
        return ['jour' => 'date', 'heures' => 'array'];
    }

    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }

    public function lignePrescription(): BelongsTo
    {
        return $this->belongsTo(LignePrescription::class, 'ligne_prescription_id');
    }

    public function administrations(): HasMany
    {
        return $this->hasMany(AdministrationTraitement::class, 'plan_id');
    }

    public function administreeA(int $heure): ?AdministrationTraitement
    {
        return $this->administrations->firstWhere('heure', $heure);
    }

    /** Prise prévue et déjà passée sans administration enregistrée. */
    public function enRetardA(int $heure): bool
    {
        if (! in_array($heure, $this->heures ?? [], true) || $this->administreeA($heure)) {
            return false;
        }

        return $this->jour->copy()->setHour($heure)->addHour()->isPast();
    }
}
