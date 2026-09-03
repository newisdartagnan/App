<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Référentiel structuré des antécédents et des allergies : ces données sont
 * choisies dans une liste, jamais saisies en texte libre, pour permettre les
 * alertes automatiques (allergie connue au produit prescrit).
 */
class ReferentielMedical extends Model
{
    use HasUuids;

    protected $table = 'referentiel_medical';

    protected $fillable = [
        'type', 'code', 'code_verifie', 'libelle', 'synonymes',
        'categorie', 'molecule', 'est_actif',
    ];

    protected function casts(): array
    {
        return ['est_actif' => 'boolean', 'code_verifie' => 'boolean'];
    }

    public function scopeAntecedents($query)
    {
        return $query->where('type', 'antecedent')->where('est_actif', true);
    }

    public function scopeAllergies($query)
    {
        return $query->where('type', 'allergie')->where('est_actif', true);
    }
}
