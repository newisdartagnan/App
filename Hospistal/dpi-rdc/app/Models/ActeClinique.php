<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActeClinique extends Model
{
    use HasUuids;

    protected $fillable = [
        'visit_id', 'patient_id', 'prescripteur_id', 'domaine', 'libelle',
        'prix', 'quantite', 'statut', 'compte_rendu', 'date_realisation', 'facture_id',
    ];

    protected function casts(): array
    {
        return [
            'prix' => 'decimal:2',
            'quantite' => 'decimal:2',
            'date_realisation' => 'datetime',
        ];
    }

    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
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
        return (float) ($this->prix * $this->quantite);
    }
}
