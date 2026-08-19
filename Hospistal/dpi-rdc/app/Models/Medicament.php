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
    /**
     * Stock de dispensation du produit. Plusieurs officines peuvent en
     * détenir : on retient celle qui délivre aux patients (ambulatoire),
     * puis à défaut le premier stock trouvé.
     */
    public function stock(): HasOne
    {
        return $this->hasOne(StockMedicament::class)
            // Priorité au point de délivrance : officine ambulatoire, puis
            // officine de service, et en dernier le dépôt central (réserve).
            ->orderByRaw("COALESCE((
                SELECT CASE o.type WHEN 'ambulatoire' THEN 0 WHEN 'service' THEN 1 ELSE 3 END
                FROM officines o WHERE o.id = stock_medicaments.officine_id
            ), 2)")
            ->orderByDesc('quantite_disponible');
    }

    public function stocks(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(StockMedicament::class);
    }
}