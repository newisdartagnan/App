<?php
namespace App\Models;
use App\Models\Concerns\Syncable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Facture extends Model
{
    use HasUuids, Syncable;
    protected $fillable = [
        'patient_id', 'visit_id', 'prescription_id', 'establishment_id', 'numero_facture',
        'date_facture', 'statut', 'type_prise_en_charge',
        'assurance_part', 'patient_part', 'remise',
        'total_ht', 'total_ttc', 'observations', 'sync_status',
    ];
    protected function casts(): array
    {
        return ['date_facture' => 'datetime'];
    }
    public function patient(): BelongsTo { return $this->belongsTo(Patient::class); }
    public function visit(): BelongsTo { return $this->belongsTo(Visit::class); }
    public function prescription(): BelongsTo { return $this->belongsTo(Prescription::class); }
    public function lignes(): HasMany { return $this->hasMany(LigneFacture::class); }
    public function paiements(): HasMany { return $this->hasMany(Paiement::class); }
    public function bonsSortie(): HasMany { return $this->hasMany(BonSortie::class); }
    public function lignesTiersPayant(): HasMany { return $this->hasMany(FactureTiersPayant::class); }
    public function montantPaye(): float
    {
        return (float) $this->paiements()->sum('montant');
    }
    public function soldeRestant(): float
    {
        return max(0, $this->patient_part - $this->montantPaye());
    }
    public function estSoldee(): bool
    {
        return $this->montantPaye() >= $this->patient_part;
    }
}