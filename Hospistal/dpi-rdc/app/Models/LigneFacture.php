<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LigneFacture extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'facture_id', 'type', 'libelle', 'reference_id',
        'quantite', 'prix_unitaire', 'remise_ligne', 'total_ligne',
    ];

    protected function casts(): array
    {
        return [
            'quantite' => 'decimal:2',
            'prix_unitaire' => 'decimal:2',
            'remise_ligne' => 'decimal:2',
            'total_ligne' => 'decimal:2',
        ];
    }

    public function facture(): BelongsTo
    {
        return $this->belongsTo(Facture::class);
    }
}
