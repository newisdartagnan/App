<?php

namespace App\Models;

use App\Models\Concerns\Syncable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Paiement extends Model
{
    use HasUuids, Syncable;

    public $timestamps = false;

    protected $fillable = [
        'facture_id', 'caissier_id', 'date_paiement', 'montant',
        'mode_paiement', 'reference_paiement', 'recu_numero', 'notes', 'sync_status',
    ];

    protected function casts(): array
    {
        return [
            'date_paiement' => 'datetime',
            'montant' => 'decimal:2',
        ];
    }

    public function facture(): BelongsTo
    {
        return $this->belongsTo(Facture::class);
    }

    public function caissier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'caissier_id');
    }
}
