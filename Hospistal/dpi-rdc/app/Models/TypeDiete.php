<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Régime alimentaire servi par la cuisine de l'hôpital. La diète est une
 * prestation facturable rattachée au séjour, au même titre que le lit.
 */
class TypeDiete extends Model
{
    use HasUuids;

    protected $table = 'types_diete';

    protected $fillable = [
        'establishment_id', 'code', 'libelle', 'description',
        'prix_journalier', 'is_active',
    ];

    protected function casts(): array
    {
        return ['prix_journalier' => 'decimal:2', 'is_active' => 'boolean'];
    }

    /**
     * Régimes servis dans les hôpitaux congolais, installés par défaut dans
     * chaque établissement. Prix journaliers en francs congolais.
     */
    public const CATALOGUE = [
        ['DB', 'Diète basique', 'Régime standard de l\'hôpital, sans restriction particulière.', 3000],
        ['DHC', 'Diète hypocalorique', 'Régime allégé, patients en surcharge pondérale.', 3000],
        ['DDIAB', 'Diète diabétique', 'Sans sucres rapides, apports glucidiques répartis.', 3500],
        ['DHS', 'Diète hyposodée', 'Sans sel ajouté : cardiopathie, HTA, insuffisance rénale.', 3500],
        ['DHP', 'Diète hyperprotéinée', 'Enrichie en protéines : dénutrition, escarres, post-opératoire.', 4500],
        ['DLIQ', 'Diète liquide', 'Alimentation liquide, pré ou post-intervention digestive.', 3000],
        ['DSR', 'Diète sans résidu', 'Préparation coloscopie, poussée inflammatoire digestive.', 3500],
        ['DABS', 'À jeun', 'Aucun apport oral : bloc opératoire, occlusion, trouble de la déglutition.', 0],
    ];

    /** Installe le catalogue pour un établissement, sans écraser l'existant. */
    public static function installerPour(string $establishmentId): void
    {
        foreach (self::CATALOGUE as [$code, $libelle, $description, $prix]) {
            self::firstOrCreate(
                ['establishment_id' => $establishmentId, 'code' => $code],
                ['libelle' => $libelle, 'description' => $description, 'prix_journalier' => $prix, 'is_active' => true]
            );
        }
    }

    public function establishment(): BelongsTo
    {
        return $this->belongsTo(Establishment::class);
    }

    public function prescriptions(): HasMany
    {
        return $this->hasMany(PrescriptionDiete::class);
    }
}
