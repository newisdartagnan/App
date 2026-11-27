<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Une intervention de l'ambulance : départ, arrivée, distance parcourue. */
class SortieAmbulance extends Model
{
    use HasUuids;

    protected $table = 'sorties_ambulance';

    protected $fillable = [
        'ambulance_id', 'patient_id', 'chauffeur', 'motif', 'destination',
        'depart_at', 'arrivee_at', 'km_depart', 'km_arrivee', 'observations', 'user_id',
    ];

    protected function casts(): array
    {
        return [
            'depart_at' => 'datetime', 'arrivee_at' => 'datetime',
            'km_depart' => 'decimal:1', 'km_arrivee' => 'decimal:1',
        ];
    }

    public function ambulance(): BelongsTo
    {
        return $this->belongsTo(Ambulance::class);
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** Durée en minutes, une fois l'ambulance rentrée. */
    public function dureeMinutes(): ?int
    {
        return $this->arrivee_at ? (int) $this->depart_at->diffInMinutes($this->arrivee_at) : null;
    }

    public function distanceKm(): ?float
    {
        return $this->km_arrivee !== null && $this->km_depart !== null
            ? max(0, (float) $this->km_arrivee - (float) $this->km_depart)
            : null;
    }
}
