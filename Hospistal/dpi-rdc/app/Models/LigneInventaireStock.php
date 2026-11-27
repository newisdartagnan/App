<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LigneInventaireStock extends Model
{
    use HasUuids;

    protected $table = 'lignes_inventaire_stock';

    protected $fillable = [
        'inventaire_id', 'stock_medicament_id', 'medicament_id', 'lot', 'date_peremption',
        'quantite_theorique', 'quantite_comptee', 'motif_ecart',
    ];

    protected function casts(): array
    {
        return ['date_peremption' => 'date', 'quantite_theorique' => 'decimal:2', 'quantite_comptee' => 'decimal:2'];
    }

    public function medicament(): BelongsTo
    {
        return $this->belongsTo(Medicament::class);
    }

    public function ecart(): ?float
    {
        return $this->quantite_comptee === null ? null : round((float) $this->quantite_comptee - (float) $this->quantite_theorique, 2);
    }
}
