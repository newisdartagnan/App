<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TypeExamen extends Model
{
    use HasUuids;

    protected $table = 'types_examens';

    protected $fillable = [
        'code', 'categorie', 'libelle', 'delai_heures', 'prix',
        'valeurs_reference', 'est_actif',
    ];

    protected function casts(): array
    {
        return [
            'prix' => 'decimal:2',
            'valeurs_reference' => 'array',
            'est_actif' => 'boolean',
        ];
    }

    public function resultats(): HasMany
    {
        return $this->hasMany(ResultatExamen::class, 'type_examen_id');
    }

    /**
     * Prix d'un sous-examen : le prix du panel divisé à parts égales entre
     * ses paramètres (une prescription partielle est facturée au prorata).
     */
    public function prixSousExamen(): float
    {
        $nb = count($this->valeurs_reference['parametres'] ?? []);

        return $nb > 1 ? round((float) $this->prix / $nb, 2) : (float) $this->prix;
    }
}
