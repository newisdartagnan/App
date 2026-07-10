<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PatientAssurance extends Model
{
    use HasUuids;
    protected $table = 'patient_assurances';
    protected $fillable = [
        'patient_id', 'assurance_id', 'numero_police', 'nom_beneficiaire',
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