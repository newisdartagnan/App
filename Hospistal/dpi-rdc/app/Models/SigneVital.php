<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Surveillance des constantes durant le séjour (dossier infirmier GPS).
 */
class SigneVital extends Model
{
    use HasUuids;

    protected $table = 'signes_vitaux';

    protected $fillable = [
        'visit_id', 'user_id', 'mesure_at', 'poids_kg', 'temperature',
        'tension_systolique', 'tension_diastolique', 'frequence_cardiaque',
        'frequence_respiratoire', 'saturation_o2', 'glycemie', 'observation',
    ];

    protected function casts(): array
    {
        return ['mesure_at' => 'datetime'];
    }

    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Constantes hors normes à signaler à l'équipe.
     *
     * @return array<int, string>
     */
    public function alertes(): array
    {
        $alertes = [];
        if ($this->tension_systolique && $this->tension_systolique > 180) {
            $alertes[] = "TA systolique {$this->tension_systolique} mmHg";
        }
        if ($this->temperature && $this->temperature > 39.5) {
            $alertes[] = "T° {$this->temperature} °C";
        }
        if ($this->saturation_o2 && $this->saturation_o2 < 90) {
            $alertes[] = "SpO₂ {$this->saturation_o2} %";
        }
        if ($this->frequence_cardiaque && ($this->frequence_cardiaque > 150 || $this->frequence_cardiaque < 40)) {
            $alertes[] = "FC {$this->frequence_cardiaque} bpm";
        }

        return $alertes;
    }
}
