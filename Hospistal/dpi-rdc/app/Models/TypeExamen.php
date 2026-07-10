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
}
