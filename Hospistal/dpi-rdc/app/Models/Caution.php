<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Acompte versé à l'admission — « caution » aux urgences, avance sur soins
 * en hospitalisation. Le montant est encaissé une fois puis imputé au fur
 * et à mesure sur les factures du séjour ; le reliquat est remboursé à la
 * sortie.
 */
class Caution extends Model
{
    use HasUuids;

    protected $table = 'cautions';

    protected $fillable = [
        'visit_id', 'patient_id', 'type', 'caissier_id', 'montant', 'devise',
        'mode_paiement', 'motif', 'statut', 'montant_impute', 'montant_rembourse',
        'reference_paiement', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'montant' => 'decimal:2',
            'montant_impute' => 'decimal:2',
            'montant_rembourse' => 'decimal:2',
        ];
    }

    public const TYPES = [
        'acompte' => 'Acompte sur soins',
        'caution' => 'Caution d\'admission',
        'garantie' => 'Garantie (société / famille)',
    ];

    public const MODES_PAIEMENT = [
        'especes' => 'Espèces',
        'mobile_money' => 'Mobile money',
        'virement' => 'Virement',
        'cheque' => 'Chèque',
    ];

    public const STATUTS = [
        'versee' => 'Versé',
        'soldee' => 'Entièrement imputé',
        'remboursee_partiel' => 'Remboursé partiellement',
        'remboursee_total' => 'Remboursé',
    ];

    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function caissier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'caissier_id');
    }

    public function imputations(): HasMany
    {
        return $this->hasMany(ImputationAcompte::class);
    }

    /** Montant encore disponible pour couvrir de nouvelles factures. */
    public function resteDisponible(): float
    {
        return max(0, (float) $this->montant - (float) $this->montant_impute - (float) $this->montant_rembourse);
    }

    public function libelleType(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }

    public function libelleStatut(): string
    {
        return self::STATUTS[$this->statut] ?? $this->statut;
    }

    /** Recalcule le statut d'après ce qui a été imputé et remboursé. */
    public function rafraichirStatut(): void
    {
        $rembourse = (float) $this->montant_rembourse;
        $montant = (float) $this->montant;

        $this->update(['statut' => match (true) {
            $rembourse >= $montant => 'remboursee_total',
            $rembourse > 0 => 'remboursee_partiel',
            $this->resteDisponible() <= 0 => 'soldee',
            default => 'versee',
        }]);
    }
}
