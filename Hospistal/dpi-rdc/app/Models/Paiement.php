<?php

namespace App\Models;

use App\Models\Concerns\Syncable;
use App\Services\DeviseService;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

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

    /**
     * Un encaissement est un fait : l'argent est entré dans le tiroir. Il ne
     * se corrige ni ne s'efface ; le trop-perçu se rend par un avoir, qui
     * laisse au patient un crédit à son nom.
     */
    protected static function booted(): void
    {
        static::creating(function (Paiement $paiement) {
            if (Facture::whereKey($paiement->facture_id)->value('statut') === 'annulee') {
                throw new LogicException('Cette facture est annulée : elle ne s\'encaisse plus.');
            }
        });

        static::updating(function (Paiement $paiement) {
            $touches = array_diff(array_keys($paiement->getDirty()), ['sync_status']);

            if ($touches !== []) {
                throw new LogicException("L'encaissement {$paiement->recu_numero} ne se modifie pas.");
            }
        });

        static::deleting(function (Paiement $paiement) {
            throw new LogicException("L'encaissement {$paiement->recu_numero} ne se supprime pas.");
        });
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
