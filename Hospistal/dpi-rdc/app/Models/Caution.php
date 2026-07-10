<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Caution extends Model
{
    use HasUuids;
    protected $fillable = [
        'visit_id', 'patient_id', 'caissier_id', 'montant', 'devise',
        'statut', 'montant_impute', 'montant_rembourse',
        'reference_paiement', 'notes',
    ];
    public function visit(): BelongsTo { return $this->belongsTo(Visit::class); }
    public function patient(): BelongsTo { return $this->belongsTo(Patient::class); }
    public function caissier(): BelongsTo { return $this->belongsTo(User::class, 'caissier_id'); }
    public function resteDisponible(): float
    {
        return max(0, $this->montant - $this->montant_impute);
    }
}