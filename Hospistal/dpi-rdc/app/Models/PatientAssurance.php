<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PatientAssurance extends Model
{
    use HasUuids;

    protected $table = 'patient_assurances';

    protected $fillable = [
        'patient_id', 'assurance_id', 'assure_principal_id', 'lien_parente', 'numero_police', 'nom_beneficiaire',
        'date_debut', 'date_fin', 'annee_courante',
        'consomme_annuel_usd', 'consomme_annuel_cdf', 'est_actif',
    ];

    protected function casts(): array
    {
        return [
            'date_debut' => 'date',
            'date_fin' => 'date',
            'est_actif' => 'boolean',
        ];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function assurance(): BelongsTo
    {
        return $this->belongsTo(Assurance::class);
    }

    /**
     * Pourquoi ce bénéficiaire n'est pas couvert à cette date ; null s'il
     * l'est. Un ayant droit radié reste sur la fiche, mais ne se facture
     * plus à l'employeur.
     */
    public function horsCouverture(?Carbon $date = null): ?string
    {
        $date ??= now();

        return match (true) {
            $this->date_debut && $this->date_debut->gt($date) => 'la couverture du bénéficiaire ne commence que le '
                .$this->date_debut->format('d/m/Y'),
            $this->date_fin && $this->date_fin->lt($date->copy()->startOfDay()) => 'la couverture du bénéficiaire a pris fin le '
                .$this->date_fin->format('d/m/Y'),
            default => null,
        };
    }

    public function plafondAtteint(string $devise = 'CDF'): bool
    {
        $assurance = $this->assurance;
        if ($devise === 'USD' && $assurance->plafond_annuel_usd) {
            return $this->consomme_annuel_usd >= $assurance->plafond_annuel_usd;
        }
        if ($devise === 'CDF' && $assurance->plafond_annuel_cdf) {
            return $this->consomme_annuel_cdf >= $assurance->plafond_annuel_cdf;
        }

        return false;
    }

    public function resteDisponible(string $devise = 'CDF'): float
    {
        $assurance = $this->assurance;
        if ($devise === 'USD' && $assurance->plafond_annuel_usd) {
            return max(0, $assurance->plafond_annuel_usd - $this->consomme_annuel_usd);
        }
        if ($devise === 'CDF' && $assurance->plafond_annuel_cdf) {
            return max(0, $assurance->plafond_annuel_cdf - $this->consomme_annuel_cdf);
        }

        return PHP_FLOAT_MAX;
    }
}
