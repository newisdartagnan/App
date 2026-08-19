<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Alimentation entérale par sonde. Le résidu gastrique aspiré avant le repas
 * conditionne la poursuite du gavage : au-delà du seuil, on suspend.
 */
class SoinGavage extends Model
{
    use HasUuids;

    protected $table = 'soins_gavage';

    protected $fillable = [
        'visit_id', 'user_id', 'realise_a', 'sonde', 'residu_gastrique',
        'type_aliment', 'quantite_aliment', 'quantite_eliminee',
        'tolerance', 'observation',
    ];

    protected function casts(): array
    {
        return ['realise_a' => 'datetime'];
    }

    public const SONDES = [
        'naso_gastrique' => 'Sonde naso-gastrique',
        'oro_gastrique' => 'Sonde oro-gastrique',
        'gastrostomie' => 'Gastrostomie',
        'jejunostomie' => 'Jéjunostomie',
    ];

    public const TOLERANCES = [
        'bonne' => 'Bonne',
        'ballonnement' => 'Ballonnement',
        'vomissements' => 'Vomissements',
        'diarrhee' => 'Diarrhée',
        'residu_eleve' => 'Résidu élevé',
    ];

    /**
     * Au-delà de 250 mL de résidu gastrique aspiré, la recommandation
     * usuelle est de suspendre le gavage et de prévenir le médecin.
     */
    public const SEUIL_RESIDU_ML = 250;

    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function libelleSonde(): string
    {
        return self::SONDES[$this->sonde] ?? $this->sonde;
    }

    public function libelleTolerance(): string
    {
        return self::TOLERANCES[$this->tolerance] ?? $this->tolerance;
    }

    /** Quantité réellement absorbée par le patient. */
    public function quantiteRetenue(): int
    {
        return max(0, (int) $this->quantite_aliment - (int) $this->quantite_eliminee);
    }

    public function residuEleve(): bool
    {
        return (int) $this->residu_gastrique >= self::SEUIL_RESIDU_ML;
    }

    /** Message d'alerte à afficher au soignant, null si tout va bien. */
    public function alerte(): ?string
    {
        if ($this->residuEleve()) {
            return 'Résidu gastrique de '.$this->residu_gastrique
                .' mL (seuil '.self::SEUIL_RESIDU_ML.' mL) — suspendre le gavage et prévenir le médecin.';
        }

        if (in_array($this->tolerance, ['vomissements', 'residu_eleve'], true)) {
            return 'Mauvaise tolérance du gavage ('.$this->libelleTolerance().') — réévaluer le débit.';
        }

        return null;
    }
}
