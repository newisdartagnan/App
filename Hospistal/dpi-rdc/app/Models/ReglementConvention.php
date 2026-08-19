<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReglementConvention extends Model
{
    use HasUuids;

    protected $table = 'reglements_convention';

    protected $fillable = [
        'facture_convention_id', 'encaisse_par', 'montant',
        'mode_paiement', 'reference', 'date_reglement',
    ];

    protected function casts(): array
    {
        return ['montant' => 'decimal:2', 'date_reglement' => 'datetime'];
    }

    public function factureConvention(): BelongsTo
    {
        return $this->belongsTo(FactureConvention::class, 'facture_convention_id');
    }

    public function encaissePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'encaisse_par');
    }
}
