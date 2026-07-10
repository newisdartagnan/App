<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MouvementStock extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $table = 'mouvements_stock';

    protected $fillable = [
        'medicament_id', 'establishment_id', 'user_id', 'type',
        'quantite', 'quantite_avant', 'quantite_apres', 'reference', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'quantite' => 'decimal:2',
            'quantite_avant' => 'decimal:2',
            'quantite_apres' => 'decimal:2',
            'created_at' => 'datetime',
        ];
    }

    public function medicament(): BelongsTo
    {
        return $this->belongsTo(Medicament::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
