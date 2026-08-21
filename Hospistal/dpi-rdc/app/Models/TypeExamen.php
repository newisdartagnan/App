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
        'valeurs_reference', 'est_actif', 'domaine', 'modalite',
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

    /** Libellés des unités d'analyse, pour le registre journalier. */
    public const UNITES = [
        'hematologie' => 'Hématologie',
        'biochimie' => 'Biochimie',
        'serologie' => 'Sérologie / Immunologie',
        'microbiologie' => 'Microbiologie / Bactériologie',
        'parasitologie' => 'Parasitologie',
        'imagerie' => 'Imagerie médicale',
        'radiologie' => 'Radiologie',
        'echographie' => 'Échographie',
        'autre' => 'Autres analyses',
    ];

    /** Modalités d'imagerie, telles qu'elles figurent au registre. */
    public const MODALITES = [
        'radiographie' => 'Radiographie',
        'echographie' => 'Échographie',
        'scanner' => 'Scanner (TDM)',
        'irm' => 'IRM',
        'mammographie' => 'Mammographie',
        'doppler' => 'Doppler',
        'autre' => 'Autre modalité',
    ];

    public function estImagerie(): bool
    {
        return $this->domaine === 'imagerie';
    }

    /** Nom lisible de la modalité d'imagerie. */
    public function libelleModalite(): string
    {
        return self::MODALITES[$this->modalite] ?? self::MODALITES['autre'];
    }

    /** Modalité déduite du code du catalogue (IMG-ECHO-ABD → échographie). */
    public static function modaliteDepuisCode(string $code): string
    {
        foreach ([
            'IMG-RX-' => 'radiographie',
            'IMG-ECHO-' => 'echographie',
            'IMG-SCAN' => 'scanner',
            'IMG-IRM' => 'irm',
            'IMG-MAMMO' => 'mammographie',
            'IMG-DOPP' => 'doppler',
        ] as $prefixe => $modalite) {
            if (str_starts_with($code, $prefixe)) {
                return $modalite;
            }
        }

        return 'autre';
    }

    /**
     * Nom lisible de l'unité d'analyse (le catalogue stocke un code).
     */
    public function uniteAnalyse(): string
    {
        $code = strtolower((string) $this->categorie);

        return self::UNITES[$code] ?? ucfirst(str_replace('_', ' ', $code ?: 'autres analyses'));
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
