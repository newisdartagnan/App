<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class FactureTiersPayant extends Model
{
    use HasUuids;

    protected $table = 'facture_tiers_payant';

    protected $fillable = [
        'facture_id', 'ligne_facture_id', 'assurance_id',
        'acte_couvert', 'taux_applique', 'montant_acte',
        'part_assurance', 'part_patient', 'plafond_atteint', 'devise',
    ];

    protected function casts(): array
    {
        return [
            'acte_couvert' => 'boolean',
            'plafond_atteint' => 'boolean',
        ];
    }

    /**
     * La répartition entre l'assureur et le patient est celle qui figure
     * sur la facture remise : elle ne se recalcule pas après coup.
     */
    protected static function booted(): void
    {
        $refuser = function (FactureTiersPayant $ligne) {
            $facture = Facture::find($ligne->facture_id);

            if ($facture && $facture->statut !== 'brouillon') {
                throw new LogicException(
                    "La part assureur de la facture {$facture->numero_facture} est figée : établissez un avoir."
                );
            }
        };

        static::updating($refuser);
        static::deleting($refuser);
    }

    public function assurance(): BelongsTo
    {
        return $this->belongsTo(Assurance::class);
    }

    public function facture(): BelongsTo
    {
        return $this->belongsTo(Facture::class);
    }
}
