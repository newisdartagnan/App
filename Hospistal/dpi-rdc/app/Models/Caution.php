<?php

namespace App\Models;

use App\Services\DeviseService;
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
        'taux_change', 'montant_cdf', 'mode_paiement', 'motif', 'statut',
        'montant_impute', 'montant_impute_cdf',
        'montant_rembourse', 'montant_rembourse_cdf',
        'reference_paiement', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'montant' => 'decimal:2',
            'montant_cdf' => 'decimal:2',
            'taux_change' => 'decimal:4',
            'montant_impute' => 'decimal:2',
            'montant_impute_cdf' => 'decimal:2',
            'montant_rembourse' => 'decimal:2',
            'montant_rembourse_cdf' => 'decimal:2',
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

    /**
     * Montant encore disponible, dans la devise du versement.
     * Un acompte de 100 $ se vide en dollars, pas en francs.
     */
    public function resteDisponible(): float
    {
        return max(0, (float) $this->montant - (float) $this->montant_impute - (float) $this->montant_rembourse);
    }

    /**
     * Contre-valeur encore disponible en francs congolais, au taux figé lors
     * du versement. C'est cette valeur qui sert à imputer sur les factures :
     * elle ne bouge plus si le change évolue.
     */
    public function resteDisponibleCdf(): float
    {
        return max(0, (float) $this->montant_cdf
            - (float) $this->montant_impute_cdf
            - (float) $this->montant_rembourse_cdf);
    }

    /** Taux effectivement appliqué au versement. */
    public function tauxApplique(): float
    {
        return (float) $this->taux_change ?: 1.0;
    }

    /** « 100,00 $ (230 000 CDF) » — ce qu'on a reçu et ce que ça pèse. */
    public function montantFormate(): string
    {
        return app(DeviseService::class)
            ->formaterAvecContreValeur((float) $this->montant, $this->devise, $this->tauxApplique());
    }

    public function resteFormate(): string
    {
        return app(DeviseService::class)
            ->formaterAvecContreValeur($this->resteDisponible(), $this->devise, $this->tauxApplique());
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
