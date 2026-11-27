<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InventaireStock extends Model
{
    use HasUuids;

    protected $table = 'inventaires_stock';

    protected $fillable = ['numero', 'officine_id', 'statut', 'date_inventaire', 'observations', 'user_id', 'valide_par', 'valide_at'];

    protected function casts(): array
    {
        return ['date_inventaire' => 'date', 'valide_at' => 'datetime'];
    }

    public function lignes(): HasMany
    {
        return $this->hasMany(LigneInventaireStock::class, 'inventaire_id');
    }

    public function officine(): BelongsTo
    {
        return $this->belongsTo(Officine::class);
    }

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
