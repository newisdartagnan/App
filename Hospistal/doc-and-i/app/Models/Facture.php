<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Facture ou avoir (PAY-05, PAY-08), numéroté sans trou. Non modifiable :
 * la base refuse UPDATE et DELETE ; le modèle refuse avant elle.
 */
class Facture extends Model
{
    use HasUuids;

    protected $table = 'factures';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['montant' => 'decimal:2', 'emise_le' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Une facture ne se modifie pas : émettre un avoir.'));
        static::deleting(fn () => throw new LogicException('Une facture ne se supprime pas : émettre un avoir.'));
    }

    public function estAvoir(): bool
    {
        return $this->type === 'avoir';
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'patient_id');
    }

    public function medecin(): BelongsTo
    {
        return $this->belongsTo(Medecin::class);
    }

    public function paiement(): BelongsTo
    {
        return $this->belongsTo(Paiement::class);
    }

    public function remboursement(): BelongsTo
    {
        return $this->belongsTo(Remboursement::class);
    }

    public function origine(): BelongsTo
    {
        return $this->belongsTo(self::class, 'facture_origine_id');
    }
}
