<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class RubriqueDepense extends Model
{
    use HasUuids;

    protected $table = 'rubriques_depense';

    protected $fillable = ['code', 'libelle', 'nature', 'est_actif'];

    protected function casts(): array
    {
        return ['est_actif' => 'boolean'];
    }

    public const NATURES = [
        'personnel' => 'Personnel', 'achats_medicaux' => 'Achats médicaux', 'fonctionnement' => 'Fonctionnement',
        'entretien' => 'Entretien', 'investissement' => 'Investissement', 'autre' => 'Autre',
    ];
}
