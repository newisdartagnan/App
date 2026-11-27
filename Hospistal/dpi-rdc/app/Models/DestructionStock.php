<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Procès-verbal de destruction de produits périmés ou avariés. */
class DestructionStock extends Model
{
    use HasUuids;

    protected $table = 'destructions_stock';

    protected $fillable = ['numero', 'officine_id', 'date_destruction', 'motif', 'temoins', 'valeur_totale', 'user_id'];

    protected function casts(): array
    {
        return ['date_destruction' => 'date', 'valeur_totale' => 'decimal:2'];
    }

    public function lignes(): HasMany
    {
        return $this->hasMany(LigneDestructionStock::class, 'destruction_id');
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
