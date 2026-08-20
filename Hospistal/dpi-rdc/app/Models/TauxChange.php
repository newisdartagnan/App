<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Révision d'un taux de change.
 *
 * Les écritures monétaires figent chacune leur propre taux : réviser le
 * change ne réécrit donc jamais le passé. Cette table sert au contrôle —
 * savoir qui a passé le dollar de 2 300 à 2 500, quand, et pourquoi.
 */
class TauxChange extends Model
{
    use HasUuids;

    protected $table = 'taux_changes';

    protected $fillable = [
        'establishment_id', 'devise', 'taux_cdf', 'taux_precedent',
        'user_id', 'motif', 'applique_a',
    ];

    protected function casts(): array
    {
        return [
            'taux_cdf' => 'decimal:4',
            'taux_precedent' => 'decimal:4',
            'applique_a' => 'datetime',
        ];
    }

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** Variation par rapport au taux précédent, en pourcentage. */
    public function variation(): ?float
    {
        $precedent = (float) $this->taux_precedent;

        if ($precedent <= 0) {
            return null;
        }

        return round((((float) $this->taux_cdf - $precedent) / $precedent) * 100, 2);
    }

    public function sens(): string
    {
        $variation = $this->variation();

        return match (true) {
            $variation === null => 'initial',
            $variation > 0 => 'hausse',
            $variation < 0 => 'baisse',
            default => 'stable',
        };
    }
}
