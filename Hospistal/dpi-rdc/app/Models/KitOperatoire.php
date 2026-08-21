<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Kit opératoire : une boîte d'instruments prête à l'emploi, avec ce
 * qu'elle contient. Le bloc déclare celui qu'il a ouvert ; son prix entre
 * dans la facture de l'intervention.
 */
class KitOperatoire extends Model
{
    use HasUuids;

    protected $table = 'kits_operatoires';

    protected $fillable = ['establishment_id', 'code', 'libelle', 'contenu', 'prix', 'est_actif'];

    protected function casts(): array
    {
        return [
            'contenu' => 'array',
            'prix' => 'decimal:2',
            'est_actif' => 'boolean',
        ];
    }

    public const CATALOGUE = [
        ['KIT-CESAR', 'Kit césarienne', ['Boîte de césarienne', 'Champs stériles', 'Fils résorbables', 'Compresses', 'Aspiration'], 45000],
        ['KIT-LAPARO', 'Kit laparotomie', ['Boîte de laparotomie', 'Écarteurs', 'Fils', 'Drains', 'Compresses'], 60000],
        ['KIT-PETITE-CHIR', 'Kit petite chirurgie', ['Boîte de petite chirurgie', 'Fils non résorbables', 'Compresses'], 15000],
        ['KIT-HERNIE', 'Kit cure de hernie', ['Boîte de hernie', 'Plaque prothétique', 'Fils'], 55000],
        ['KIT-ORTHO', 'Kit orthopédie', ['Boîte d\'ostéosynthèse', 'Moteur', 'Fils d\'acier'], 90000],
        ['KIT-ANESTH', 'Kit anesthésie', ['Plateau d\'intubation', 'Sondes', 'Seringues', 'Filtres'], 20000],
    ];

    public static function installerPour(Establishment $etablissement): void
    {
        foreach (self::CATALOGUE as [$code, $libelle, $contenu, $prix]) {
            self::firstOrCreate(
                ['establishment_id' => $etablissement->id, 'code' => $code],
                ['libelle' => $libelle, 'contenu' => $contenu, 'prix' => $prix, 'est_actif' => true]
            );
        }
    }

    public function establishment(): BelongsTo
    {
        return $this->belongsTo(Establishment::class);
    }

    public function libelleContenu(): string
    {
        return implode(' · ', $this->contenu ?? []);
    }
}
