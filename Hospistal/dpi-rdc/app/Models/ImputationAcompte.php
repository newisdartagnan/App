<?php

namespace App\Models;

use App\Services\DeviseService;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Part d'un acompte affectée à une facture précise. La trace est conservée
 * ligne à ligne : à la sortie on sait exactement ce que l'avance a couvert
 * et ce qui reste à rendre au patient.
 */
class ImputationAcompte extends Model
{
    use HasUuids;

    protected $table = 'imputations_acompte';

    protected $fillable = [
        'caution_id', 'facture_id', 'user_id',
        'montant', 'devise', 'taux_change', 'montant_cdf', 'montant_acompte',
    ];

    protected function casts(): array
    {
        return [
            'montant' => 'decimal:2',
            'taux_change' => 'decimal:4',
            'montant_cdf' => 'decimal:2',
            'montant_acompte' => 'decimal:2',
        ];
    }

    /** Ce que l'imputation apporte à la facture, dans la devise de celle-ci. */
    public function montantFormate(): string
    {
        return app(DeviseService::class)
            ->formater((float) $this->montant, $this->devise);
    }

    /** Ce qu'elle a prélevé sur l'acompte, dans la devise du versement. */
    public function preleveFormate(): string
    {
        $devise = $this->acompte?->devise ?? $this->devise;

        return app(DeviseService::class)
            ->formater((float) $this->montant_acompte, $devise);
    }

    public function acompte(): BelongsTo
    {
        return $this->belongsTo(Caution::class, 'caution_id');
    }

    public function facture(): BelongsTo
    {
        return $this->belongsTo(Facture::class);
    }

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
