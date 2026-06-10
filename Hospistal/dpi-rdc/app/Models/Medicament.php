<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
class Medicament extends Model
{
    use HasUuids;
    protected $fillable = [
        'establishment_id', 'code_ucd', 'denomination_commune', 'nom_commercial',
        'forme', 'dosage', 'unite_dispensation', 'classe_therapeutique',
        'necessite_ordonnance', 'est_actif',
    ];
    protected function casts(): array
    {
        return [
            'necessite_ordonnance' => 'boolean',
            'est_actif' => 'boolean',
        ];
    }
    public function establishment(): BelongsTo
    {
        return $this->belongsTo(Establishment::class);
    }
    public function stock(): HasOne
    {
        return $this->hasOne(StockMedicament::class);
    }
}