<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LigneReceptionStock extends Model
{
    use HasUuids;

    protected $table = 'lignes_reception_stock';

    protected $fillable = [
        'reception_id', 'ligne_commande_id', 'medicament_id', 'quantite', 'lot',
        'date_peremption', 'prix_unitaire_achat', 'prix_unitaire_vente',
    ];

    protected function casts(): array
    {
        return ['date_peremption' => 'date', 'quantite' => 'decimal:2'];
    }

    public function medicament(): BelongsTo
    {
        return $this->belongsTo(Medicament::class);
    }
}
