<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Rendez-vous d'un patient, ou créneau bloqué par un prestataire
 * (congé, réunion) — dans ce cas il n'y a pas de patient rattaché.
 */
class RendezVous extends Model
{
    use HasUuids;

    protected $table = 'rendez_vous';

    protected $fillable = [
        'establishment_id', 'patient_id', 'prestataire_id', 'type_consultation_id',
        'cree_par', 'debut', 'duree_minutes', 'statut', 'contact',
        'motif', 'observation', 'annule_at', 'annule_par',
    ];

    protected function casts(): array
    {
        return ['debut' => 'datetime', 'annule_at' => 'datetime'];
    }

    public const STATUTS = [
        'fixe' => 'Fixé',
        'honore' => 'Honoré',
        'absent' => 'Patient absent',
        'annule' => 'Annulé',
        'bloque' => 'Créneau bloqué',
    ];

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function prestataire(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prestataire_id');
    }

    public function typeConsultation(): BelongsTo
    {
        return $this->belongsTo(TypeConsultation::class, 'type_consultation_id');
    }

    public function creePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cree_par');
    }

    public function fin(): \Illuminate\Support\Carbon
    {
        return $this->debut->copy()->addMinutes($this->duree_minutes);
    }

    public function estBloque(): bool
    {
        return $this->statut === 'bloque';
    }

    /** Rendez-vous qui occupent réellement le créneau. */
    public function scopeOccupants(Builder $query): Builder
    {
        return $query->whereIn('statut', ['fixe', 'bloque', 'honore']);
    }

    public function scopeDuJour(Builder $query, string $jour): Builder
    {
        return $query->whereDate('debut', $jour);
    }

    public function libelleStatut(): string
    {
        return self::STATUTS[$this->statut] ?? $this->statut;
    }
}
