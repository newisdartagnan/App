<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Equipement extends Model
{
    use HasUuids;

    protected $table = 'equipements';

    protected $fillable = [
        'nom', 'type', 'marque', 'modele', 'numero_serie', 'statut',
        'localisation', 'date_acquisition', 'date_derniere_maintenance',
        'prochaine_maintenance', 'observations',
    ];

    protected function casts(): array
    {
        return [
            'date_acquisition' => 'date',
            'date_derniere_maintenance' => 'date',
            'prochaine_maintenance' => 'date',
        ];
    }
}
