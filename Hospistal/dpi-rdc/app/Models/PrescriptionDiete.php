<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Diète prescrite pour un séjour. Celle dont `fin` est nulle est la diète
 * en cours ; changer de régime clôt la précédente et en ouvre une nouvelle,
 * pour que la cuisine et la facturation gardent l'historique exact.
 */
class PrescriptionDiete extends Model
{
    use HasUuids;

    protected $table = 'prescriptions_diete';

    protected $fillable = [
        'visit_id', 'type_diete_id', 'user_id', 'facture_id',
        'jours_factures', 'debut', 'fin', 'observation',
    ];

    protected function casts(): array
    {
        return ['debut' => 'date', 'fin' => 'date'];
    }

    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }

    public function typeDiete(): BelongsTo
    {
        return $this->belongsTo(TypeDiete::class);
    }

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function facture(): BelongsTo
    {
        return $this->belongsTo(Facture::class);
    }

    public function estFacturee(): bool
    {
        return $this->facture_id !== null;
    }

    public function scopeEnCours(Builder $query): Builder
    {
        return $query->whereNull('fin');
    }

    /** Nombre de journées servies, la journée entamée comptant pour une. */
    public function joursServis(): int
    {
        $fin = $this->fin ?? now();

        return max(1, (int) $this->debut->startOfDay()->diffInDays($fin->copy()->startOfDay()) + 1);
    }

    /** Montant facturable de la diète sur la période servie. */
    public function montant(): float
    {
        return round($this->joursServis() * (float) ($this->typeDiete?->prix_journalier ?? 0), 2);
    }
}
