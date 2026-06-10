<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class StockMedicament extends Model
{
    use HasUuids;
    public $timestamps = false;
    protected $table = 'stock_medicaments';
    protected $fillable = [
        'medicament_id', 'establishment_id', 'quantite_disponible',
        'quantite_alerte', 'quantite_commande', 'prix_unitaire_vente',
        'prix_unitaire_achat', 'date_peremption', 'lot', 'emplacement',
    ];
    protected function casts(): array
    {
        return [
            'quantite_disponible' => 'decimal:2',
            'date_peremption' => 'date',
            'updated_at' => 'datetime',
        ];
    }
    public function medicament(): BelongsTo
    {
        return $this->belongsTo(Medicament::class);
    }
    public function estEnAlerte(): bool
    {
        return $this->quantite_disponible <= $this->quantite_alerte;
    }
    public function estPerime(): bool
    {
        return $this->date_peremption && $this->date_peremption->isPast();
    }
    public function expireBientot(): bool
    {
        return $this->date_peremption &&
               !$this->estPerime() &&
               $this->date_peremption->diffInDays(now()) <= 30;
    }
}