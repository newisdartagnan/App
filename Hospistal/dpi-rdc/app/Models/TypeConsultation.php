<?php

namespace App\Models;

use App\Services\DeviseService;
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
     * Prix en francs congolais, au taux en vigueur.
     *
     * Le taux vient du paramétrage, révisé au guichet à chaque mouvement du
     * dollar — et non plus d'une valeur figée dans un fichier. Sans cela, la
     * direction relevait le taux dans l'application et la consultation
     * continuait de se facturer à l'ancien cours.
     */
    public function prixCdf(): float
    {
        return app(DeviseService::class)->versCdf((float) $this->prix_usd, 'USD');
    }
}
