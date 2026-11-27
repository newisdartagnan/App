<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Catégorie de convention (A, B, C, D, CD, DD…) et sa rangée de coefficients.
 * La fonction tarifaire n d'un acte lit le coefficient n (le premier est 1).
 */
class CategorieTarifaire extends Model
{
    use HasUuids;

    public const NB_FONCTIONS = 8;

    protected $table = 'categories_tarifaires';

    protected $fillable = ['numero', 'nom', 'commentaire', 'coefficients'];

    protected function casts(): array
    {
        return ['coefficients' => 'array'];
    }

    public function assurances(): HasMany
    {
        return $this->hasMany(Assurance::class, 'categorie_tarifaire_id');
    }

    /** Coefficient de la fonction tarifaire $fonction (1 à 8) ; 1 si absent. */
    public function coefficient(?int $fonction): float
    {
        if (! $fonction || $fonction < 1) {
            return 1.0;
        }
        $valeur = $this->coefficients[$fonction - 1] ?? null;

        return $valeur === null || $valeur === '' ? 1.0 : (float) $valeur;
    }
}
