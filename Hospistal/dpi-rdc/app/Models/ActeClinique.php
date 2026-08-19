<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActeClinique extends Model
{
    use HasUuids;

    protected $table = 'actes_cliniques';

    protected $attributes = [
        'quantite' => 1,
    ];

    protected $fillable = [
        'visit_id', 'patient_id', 'prescripteur_id', 'operateur_id', 'domaine', 'libelle',
        'prix', 'quantite', 'statut', 'compte_rendu', 'date_realisation', 'facture_id',
        'date_prevue', 'duree_minutes', 'consentement', 'urgence', 'indication',
    ];

    protected function casts(): array
    {
        return [
            'prix' => 'decimal:2',
            'quantite' => 'decimal:2',
            'date_realisation' => 'datetime',
            'date_prevue' => 'datetime',
            'consentement' => 'boolean',
            'urgence' => 'boolean',
        ];
    }

    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }

    /** Chirurgien / opérateur qui réalise l'acte. */
    public function operateur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'operateur_id');
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function prescripteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prescripteur_id');
    }

    public function facture(): BelongsTo
    {
        return $this->belongsTo(Facture::class);
    }

    public function montantTotal(): float
    {
        return (float) ($this->prix * ($this->quantite ?? 1));
    }
}
