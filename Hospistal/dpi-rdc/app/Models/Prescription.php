<?php

namespace App\Models;

use App\Models\Concerns\Syncable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Prescription extends Model
{
    use HasUuids, Syncable;

    // brouillon → en_attente_paiement → en_attente (payée) → dispensee
    protected $fillable = [
        'consultation_id', 'patient_id', 'prescripteur_id', 'officine_id',
        'date_prescription', 'statut', 'observations', 'sync_status',
    ];

    protected function casts(): array
    {
        return ['date_prescription' => 'datetime'];
    }

    public function consultation(): BelongsTo
    {
        return $this->belongsTo(Consultation::class);
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function prescripteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prescripteur_id');
    }

    /**
     * Officine chargée de servir l'ordonnance. Le dépôt central n'en fait
     * jamais partie : il réapprovisionne les officines, il ne délivre pas.
     */
    public function officine(): BelongsTo
    {
        return $this->belongsTo(Officine::class);
    }

    /** Lignes servies par l'hôpital, hors produits achetés à l'extérieur. */
    public function lignesInternes()
    {
        return $this->lignes->where('est_externe', false);
    }

    /** Lignes à acheter à l'extérieur — imprimées sans prix. */
    public function lignesExternes()
    {
        return $this->lignes->where('est_externe', true);
    }

    public function lignes(): HasMany
    {
        return $this->hasMany(LignePrescription::class);
    }

    public function factures(): HasMany
    {
        return $this->hasMany(Facture::class);
    }

    public function estPayee(): bool
    {
        return in_array($this->statut, ['en_attente', 'dispensee', 'partiellement_dispensee'], true);
    }
}
