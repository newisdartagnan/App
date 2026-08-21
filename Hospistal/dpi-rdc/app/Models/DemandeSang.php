<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Demande de sang adressée à la banque par un service.
 */
class DemandeSang extends Model
{
    use HasUuids;

    protected $table = 'demandes_sang';

    protected $fillable = [
        'establishment_id', 'patient_id', 'visit_id', 'demandeur_id', 'numero',
        'groupe_demande', 'type_produit', 'nombre_poches', 'urgence',
        'indication', 'hemoglobine', 'statut', 'motif_refus',
    ];

    protected function casts(): array
    {
        return [
            'urgence' => 'boolean',
            'hemoglobine' => 'decimal:1',
        ];
    }

    public const STATUTS = [
        'en_attente' => 'En attente',
        'servie' => 'Servie',
        'partiellement_servie' => 'Partiellement servie',
        'refusee' => 'Refusée',
        'annulee' => 'Annulée',
    ];

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }

    public function demandeur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'demandeur_id');
    }

    /** Poches déjà délivrées sur cette demande. */
    public function transfusions(): HasMany
    {
        return $this->hasMany(Transfusion::class, 'demande_id');
    }

    public function libelleStatut(): string
    {
        return self::STATUTS[$this->statut] ?? $this->statut;
    }

    public function estOuverte(): bool
    {
        return in_array($this->statut, ['en_attente', 'partiellement_servie'], true);
    }

    public function pochesServies(): int
    {
        return $this->transfusions()->count();
    }

    public function pochesRestantes(): int
    {
        return max(0, $this->nombre_poches - $this->pochesServies());
    }

    /** Groupe à servir : celui de la demande, sinon celui du dossier. */
    public function groupeReceveur(): ?string
    {
        return $this->groupe_demande ?: $this->patient?->groupe_sanguin;
    }
}
