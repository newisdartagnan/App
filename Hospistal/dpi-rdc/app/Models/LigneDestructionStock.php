<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LigneDestructionStock extends Model
{
    use HasUuids;

    protected $table = 'lignes_destruction_stock';

    protected $fillable = ['destruction_id', 'medicament_id', 'lot', 'date_peremption', 'quantite', 'prix_unitaire_achat'];

    protected function casts(): array
    {
        return ['date_peremption' => 'date'];
    }

    public function medicament(): BelongsTo
    {
        return $this->belongsTo(Medicament::class);
    }
}
