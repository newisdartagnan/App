<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Triage d'urgence structuré : le niveau est calculé à partir des critères
 * cliniques cochés, jamais choisi subjectivement par le soignant.
 */
class TriageUrgence extends Model
{
    use HasUuids;

    protected $table = 'triages_urgence';

    protected $fillable = [
        'visit_id', 'user_id', 'niveau', 'delai_cible_minutes',
        'criteres', 'criteres_declencheurs', 'atr', 'observation', 'triage_at',
    ];

    protected function casts(): array
    {
        return [
            'criteres' => 'array',
            'criteres_declencheurs' => 'array',
            'atr' => 'boolean',
            'triage_at' => 'datetime',
        ];
    }

    /** Niveaux de l'échelle : libellé, délai cible et couleur. */
    public const NIVEAUX = [
        1 => ['libelle' => 'Réanimation', 'delai' => 0, 'couleur' => 'red', 'description' => 'Prise en charge immédiate'],
        2 => ['libelle' => 'Très urgent', 'delai' => 15, 'couleur' => 'orange', 'description' => 'Prise en charge dans les 15 min'],
        3 => ['libelle' => 'Urgent', 'delai' => 30, 'couleur' => 'yellow', 'description' => 'Prise en charge dans les 30 min'],
        4 => ['libelle' => 'Moins urgent', 'delai' => 60, 'couleur' => 'green', 'description' => 'Prise en charge dans les 60 min'],
        5 => ['libelle' => 'Non urgent', 'delai' => 120, 'couleur' => 'blue', 'description' => 'Prise en charge dans les 120 min'],
    ];

    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function libelleNiveau(): string
    {
        return self::NIVEAUX[$this->niveau]['libelle'] ?? '—';
    }

    public function descriptionNiveau(): string
    {
        return self::NIVEAUX[$this->niveau]['description'] ?? '';
    }

    public function couleurNiveau(): string
    {
        return self::NIVEAUX[$this->niveau]['couleur'] ?? 'gray';
    }

    /** Heure limite de prise en charge visée. */
    public function echeance(): \Illuminate\Support\Carbon
    {
        return $this->triage_at->copy()->addMinutes($this->delai_cible_minutes);
    }

    public function enRetard(): bool
    {
        return $this->delai_cible_minutes > 0 && now()->greaterThan($this->echeance());
    }
}
