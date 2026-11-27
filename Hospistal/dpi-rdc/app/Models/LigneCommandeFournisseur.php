<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LigneCommandeFournisseur extends Model
{
    use HasUuids;

    protected $table = 'lignes_commande_fournisseur';

    protected $fillable = ['commande_id', 'medicament_id', 'quantite_commandee', 'quantite_recue', 'prix_unitaire'];

    protected function casts(): array
    {
        return ['quantite_commandee' => 'decimal:2', 'quantite_recue' => 'decimal:2', 'prix_unitaire' => 'decimal:2'];
    }

    public function commande(): BelongsTo
    {
        return $this->belongsTo(CommandeFournisseur::class, 'commande_id');
    }

    public function medicament(): BelongsTo
    {
        return $this->belongsTo(Medicament::class);
    }

    public function resteARecevoir(): float
    {
        return max(0, (float) $this->quantite_commandee - (float) $this->quantite_recue);
    }
}
