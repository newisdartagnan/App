<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LigneFactureConvention extends Model
{
    use HasUuids;

    protected $table = 'lignes_facture_convention';

    protected $fillable = ['facture_convention_id', 'facture_id', 'patient_id', 'part_assurance'];

    protected function casts(): array
    {
        return ['part_assurance' => 'decimal:2'];
    }

    public function factureConvention(): BelongsTo
    {
        return $this->belongsTo(FactureConvention::class, 'facture_convention_id');
    }

    public function facture(): BelongsTo
    {
        return $this->belongsTo(Facture::class);
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }
}
