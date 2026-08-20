<?php

namespace App\Models;

use App\Models\Concerns\Syncable;
use App\Services\DeviseService;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Paiement extends Model
{
    use HasUuids, Syncable;

    public $timestamps = false;

    protected $fillable = [
        'facture_id', 'caissier_id', 'date_paiement', 'montant',
        'devise', 'taux_change', 'montant_cdf',
        'mode_paiement', 'reference_paiement', 'recu_numero', 'notes', 'sync_status',
    ];

    protected function casts(): array
    {
        return [
            'date_paiement' => 'datetime',
            'montant' => 'decimal:2',
            'taux_change' => 'decimal:4',
            'montant_cdf' => 'decimal:2',
        ];
    }

    /** « 100,00 $ (230 000 CDF) » : ce qui est entré en caisse et son poids. */
    public function montantFormate(): string
    {
        return app(DeviseService::class)->formaterAvecContreValeur(
            (float) $this->montant,
            $this->devise ?: 'CDF',
            (float) ($this->taux_change ?: 1)
        );
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
