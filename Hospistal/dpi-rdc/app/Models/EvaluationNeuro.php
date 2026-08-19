<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Évaluation neurologique selon l'échelle de Glasgow.
 *
 * GPS-Monkole laisse le soignant cocher des cases sans calculer le total ;
 * ici le score est calculé et enregistré, ce qui rend la surveillance
 * comparable d'une évaluation à l'autre et déclenche les alertes de coma.
 */
class EvaluationNeuro extends Model
{
    use HasUuids;

    protected $table = 'evaluations_neuro';

    protected $fillable = [
        'visit_id', 'user_id', 'evalue_a', 'ouverture_yeux',
        'reponse_verbale', 'reponse_motrice', 'score',
        'pupille_droite', 'pupille_gauche', 'observation',
    ];

    protected function casts(): array
    {
        return ['evalue_a' => 'datetime'];
    }

    /** Ouverture des yeux : 4 points au maximum. */
    public const OUVERTURE_YEUX = [
        4 => 'Spontanée',
        3 => 'À la demande verbale',
        2 => 'À la douleur',
        1 => 'Aucune',
    ];

    /** Réponse verbale : 5 points au maximum. */
    public const REPONSE_VERBALE = [
        5 => 'Orientée',
        4 => 'Confuse',
        3 => 'Inappropriée',
        2 => 'Incompréhensible',
        1 => 'Aucune',
    ];

    /** Réponse motrice : 6 points au maximum. */
    public const REPONSE_MOTRICE = [
        6 => 'Obéit aux ordres',
        5 => 'Orientée à la douleur',
        4 => 'Retrait à la douleur',
        3 => 'Flexion (décortication)',
        2 => 'Extension (décérébration)',
        1 => 'Aucune',
    ];

    public const PUPILLES = [
        'reactive' => 'Réactive',
        'paresseuse' => 'Paresseuse',
        'areactive' => 'Aréactive',
        'mydriase' => 'Mydriase',
        'myosis' => 'Myosis',
    ];

    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** Score de Glasgow, de 3 (coma profond) à 15 (conscience normale). */
    public static function calculerScore(int $yeux, int $verbale, int $motrice): int
    {
        return $yeux + $verbale + $motrice;
    }

    /**
     * Gravité d'après le score : ≤ 8 impose la protection des voies
     * aériennes, d'où le seuil retenu pour l'alerte.
     */
    public function gravite(): string
    {
        return match (true) {
            $this->score <= 8 => 'grave',
            $this->score <= 12 => 'modere',
            default => 'leger',
        };
    }

    public function libelleGravite(): string
    {
        return match ($this->gravite()) {
            'grave' => 'Coma — atteinte grave',
            'modere' => 'Atteinte modérée',
            default => 'Atteinte légère',
        };
    }

    /** Message d'alerte à afficher, null si le score est rassurant. */
    public function alerte(): ?string
    {
        if ($this->score <= 8) {
            return 'Glasgow '.$this->score.'/15 — coma : appeler le médecin, '
                .'protéger les voies aériennes et rapprocher la surveillance.';
        }

        if ($this->score <= 12) {
            return 'Glasgow '.$this->score.'/15 — atteinte modérée : surveillance rapprochée.';
        }

        return null;
    }

    public function libelleYeux(): string
    {
        return self::OUVERTURE_YEUX[$this->ouverture_yeux] ?? '—';
    }

    public function libelleVerbale(): string
    {
        return self::REPONSE_VERBALE[$this->reponse_verbale] ?? '—';
    }

    public function libelleMotrice(): string
    {
        return self::REPONSE_MOTRICE[$this->reponse_motrice] ?? '—';
    }
}
