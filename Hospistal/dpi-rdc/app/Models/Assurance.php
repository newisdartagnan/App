<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Assurance extends Model
{
    use HasUuids;
    protected $fillable = [
        'nom', 'code', 'taux_couverture', 'plafond_annuel_usd',
        'plafond_annuel_cdf', 'est_actif', 'notes',
    ];
    protected function casts(): array
    {
        return ['est_actif' => 'boolean'];
    }
    public function couvertures(): HasMany
    {
        return $this->hasMany(AssuranceCouverture::class);
    }
    public function patientAssurances(): HasMany
    {
        return $this->hasMany(PatientAssurance::class);
    }
    public function couvreActe(string $type, ?string $referenceId = null): bool
    {
        // Si aucune règle définie pour ce type → couvert par défaut
        $regles = $this->couvertures()->where('type', $type)->get();
        if ($regles->isEmpty()) return true;
        // Cherche règle spécifique
        if ($referenceId) {
            $specifique = $regles->where('reference_id', $referenceId)->first();
            if ($specifique) return $specifique->couvert;
        }
        // Règle générale pour ce type
        $generale = $regles->whereNull('reference_id')->first();
        return $generale ? $generale->couvert : true;
    }
    public function tauxPourActe(string $type, ?string $referenceId = null): float
    {
        if ($referenceId) {
            $specifique = $this->couvertures()
                ->where('type', $type)
                ->where('reference_id', $referenceId)
                ->whereNotNull('taux_specifique')
                ->first();
            if ($specifique) return (float) $specifique->taux_specifique;
        }
        return (float) $this->taux_couverture;
    }
}