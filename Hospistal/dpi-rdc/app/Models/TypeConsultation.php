<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class TypeConsultation extends Model
{
    use HasUuids;

    protected $table = 'types_consultation';

    protected $fillable = [
        'code', 'libelle', 'categorie', 'specialite', 'prix_usd', 'est_actif',
    ];

    protected function casts(): array
    {
        return [
            'prix_usd' => 'decimal:2',
            'est_actif' => 'boolean',
        ];
    }

    /**
     * Prix converti en francs congolais (taux configurable).
     */
    public function prixCdf(): float
    {
        return (float) $this->prix_usd * (float) config('dpi.taux_usd_cdf', 2800);
    }
}
