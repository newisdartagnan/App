<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
class LignePrescription extends Model
{
    use HasUuids;
    protected $table = 'lignes_prescription';
    protected $fillable = [
        'prescription_id', 'medicament_id', 'dose', 'frequence',
        'duree_jours', 'voie_administration', 'instructions',
        'quantite_totale', 'quantite_dispensee', 'est_substituable',
    ];
    protected function casts(): array
    {
        return ['est_substituable' => 'boolean'];
    }
    public function prescription(): BelongsTo
    {
        return $this->belongsTo(Prescription::class);
    }
    public function medicament(): BelongsTo
    {
        return $this->belongsTo(Medicament::class);
    }
    public function dispensations(): HasMany
    {
        return $this->hasMany(Dispensation::class, 'ligne_prescription_id');
    }
    public function quantiteRestante(): float
    {
        return max(0, $this->quantite_totale - $this->quantite_dispensee);
    }
}