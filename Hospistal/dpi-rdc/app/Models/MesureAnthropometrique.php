<?php

namespace App\Models;

use App\Models\Concerns\Syncable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Le poids, la taille, le bras et les œdèmes d'un jour donné.
 *
 * C'est la mesure qui décide de l'admission en nutrition, et c'est la suite
 * des mesures qui dit si l'enfant remonte. Elle se prend au dépistage comme
 * au lit, d'où la visite facultative.
 *
 * Ce que l'application calcule et ce qu'elle ne calcule pas :
 *
 * Le périmètre brachial et les œdèmes se lisent directement — un ruban et
 * deux pouces sur les pieds — et leurs seuils sont ceux du protocole
 * national, stables et sans ambiguïté. L'application les classe donc
 * elle-même.
 *
 * Le rapport poids/taille, lui, se lit dans les tables de référence de
 * l'OMS. Ces tables ne sont pas dans le dépôt, et les reconstituer de
 * mémoire pour gagner une saisie enverrait un jour un enfant dans la
 * mauvaise unité. Le soignant lit donc son z-score sur l'abaque et le
 * saisit ; l'application ne fait qu'en tirer la classification.
 */
class MesureAnthropometrique extends Model
{
    use HasUuids, Syncable;

    protected $table = 'mesures_anthropometriques';

    protected $fillable = [
        'patient_id', 'visit_id', 'user_id', 'mesure_a',
        'poids_kg', 'taille_cm', 'position_taille',
        'perimetre_brachial_mm', 'oedemes', 'z_score_pt', 'imc', 'observation',
    ];

    protected function casts(): array
    {
        return [
            'mesure_a' => 'datetime',
            'poids_kg' => 'decimal:3',
            'taille_cm' => 'decimal:1',
            'z_score_pt' => 'decimal:2',
            'imc' => 'decimal:2',
        ];
    }

    /**
     * Les œdèmes bilatéraux, cotés comme sur la fiche.
     *
     * Ils prennent le pas sur tout : un enfant œdémateux est sévèrement
     * malnutri même si la balance dit le contraire — l'eau pèse.
     */
    public const OEDEMES = [
        'aucun' => 'Aucun',
        'plus' => '+ (pieds)',
        'deux_plus' => '++ (pieds et jambes)',
        'trois_plus' => '+++ (généralisés — urgence)',
    ];

    public const POSITIONS = [
        'couche' => 'Couché (longueur, moins de 2 ans)',
        'debout' => 'Debout (taille, 2 ans et plus)',
    ];

    /** Seuils du périmètre brachial, en millimètres (6 à 59 mois). */
    public const PB_SEVERE = 115;

    public const PB_MODERE = 125;

    /** Seuil du périmètre brachial chez la femme enceinte ou allaitante. */
    public const PB_MATERNEL = 230;

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function aDesOedemes(): bool
    {
        return $this->oedemes !== 'aucun' && $this->oedemes !== null;
    }

    public function libelleOedemes(): string
    {
        return self::OEDEMES[$this->oedemes] ?? 'Aucun';
    }

    /**
     * L'état nutritionnel que cette mesure établit.
     *
     * On retient le plus grave des trois critères : c'est la règle du
     * protocole, et c'est le bon sens — un enfant peut avoir un bras
     * acceptable et des œdèmes qui le mettent en danger le jour même.
     *
     * @return 'severe'|'modere'|'normal'|'inconnu'
     */
    public function etatNutritionnel(): string
    {
        if ($this->aDesOedemes()) {
            return 'severe';
        }

        $pb = $this->perimetre_brachial_mm;
        $z = $this->z_score_pt !== null ? (float) $this->z_score_pt : null;

        if (($pb !== null && $pb < self::PB_SEVERE) || ($z !== null && $z < -3)) {
            return 'severe';
        }

        if (($pb !== null && $pb < self::PB_MODERE) || ($z !== null && $z < -2)) {
            return 'modere';
        }

        // Rien de mesuré ne vaut pas « bien portant » : la fiche reste
        // muette plutôt que rassurante à tort.
        if ($pb === null && $z === null) {
            return 'inconnu';
        }

        return 'normal';
    }

    public const ETATS = [
        'severe' => 'Malnutrition aiguë sévère',
        'modere' => 'Malnutrition aiguë modérée',
        'normal' => 'État nutritionnel normal',
        'inconnu' => 'Non évaluable',
    ];

    public function libelleEtat(): string
    {
        return self::ETATS[$this->etatNutritionnel()];
    }

    /**
     * Ce qui, dans la mesure, justifie l'admission.
     *
     * Le canevas ne compte pas « un enfant sévère » : il compte les entrées
     * « avec PT < -3 DS ou PB < 115 mm » à part de celles « avec œdèmes ».
     * Les œdèmes passent donc devant, comme sur le formulaire.
     *
     * @return null|'pt_pb'|'oedemes'
     */
    public function critereDadmission(): ?string
    {
        if ($this->aDesOedemes()) {
            return 'oedemes';
        }

        $pb = $this->perimetre_brachial_mm;
        $z = $this->z_score_pt !== null ? (float) $this->z_score_pt : null;

        if (($pb !== null && $pb < self::PB_SEVERE) || ($z !== null && $z < -3)) {
            return 'pt_pb';
        }

        return null;
    }

    /** L'indice de masse corporelle, quand poids et taille sont là. */
    public function calculerImc(): ?float
    {
        $poids = (float) $this->poids_kg;
        $taille = (float) $this->taille_cm;

        if ($poids <= 0 || $taille <= 0) {
            return null;
        }

        return round($poids / (($taille / 100) ** 2), 2);
    }

    protected static function booted(): void
    {
        static::saving(function (self $mesure) {
            $mesure->imc = $mesure->calculerImc();
        });
    }

    protected function getSyncPriority(): int
    {
        return 6;
    }
}
