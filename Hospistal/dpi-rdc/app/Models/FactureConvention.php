<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Facture adressée à une société ou une assurance : elle regroupe, sur une
 * période, la part prise en charge de toutes les factures patients couvertes.
 */
class FactureConvention extends Model
{
    use HasUuids;

    protected $table = 'factures_convention';

    protected $fillable = [
        'numero', 'assurance_id', 'emise_par', 'periode_debut', 'periode_fin',
        'mode', 'devise', 'taux_change', 'montant_total', 'montant_regle',
        'statut', 'date_reglement', 'observation',
    ];

    protected function casts(): array
    {
        return [
            'periode_debut' => 'date',
            'periode_fin' => 'date',
            'montant_total' => 'decimal:2',
            'montant_regle' => 'decimal:2',
            'taux_change' => 'decimal:4',
            'date_reglement' => 'datetime',
        ];
    }

    /** Devises acceptées pour une convention (ambassades, organismes). */
    public const DEVISES = ['CDF' => 'Franc congolais', 'USD' => 'Dollar américain', 'EUR' => 'Euro'];

    public function assurance(): BelongsTo
    {
        return $this->belongsTo(Assurance::class);
    }

    public function emisePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'emise_par');
    }

    public function lignes(): HasMany
    {
        return $this->hasMany(LigneFactureConvention::class, 'facture_convention_id');
    }

    public function reglements(): HasMany
    {
        return $this->hasMany(ReglementConvention::class, 'facture_convention_id');
    }

    public function resteDu(): float
    {
        return max(0, (float) $this->montant_total - (float) $this->montant_regle);
    }

    public function estSoldee(): bool
    {
        return $this->resteDu() <= 0.01;
    }

    public static function genererNumero(): string
    {
        $prefix = 'FCV-' . now()->format('Y') . '-';
        $last = static::where('numero', 'like', $prefix . '%')
            ->orderByDesc('numero')->value('numero');

        return $prefix . str_pad((string) ($last ? (int) substr($last, -6) + 1 : 1), 6, '0', STR_PAD_LEFT);
    }
}
