<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * L'accouchement : ce qui clôt la grossesse et ouvre le dossier de l'enfant.
 */
class Accouchement extends Model
{
    use HasUuids;

    protected $fillable = [
        'grossesse_id', 'patient_id', 'visit_id', 'acte_clinique_id',
        'debut_travail', 'date_accouchement', 'terme_semaines',
        'mode', 'presentation', 'delivrance', 'episiotomie', 'dechirure',
        'saignement_ml', 'transfusion', 'accoucheur_id', 'sage_femme',
        'etat_mere', 'complications', 'observations',
    ];

    protected function casts(): array
    {
        return [
            'debut_travail' => 'datetime',
            'date_accouchement' => 'datetime',
            'episiotomie' => 'boolean',
            'transfusion' => 'boolean',
        ];
    }

    public const MODES = [
        'voie_basse' => 'Voie basse spontanée',
        'ventouse' => 'Extraction par ventouse',
        'forceps' => 'Extraction par forceps',
        'siege' => 'Accouchement par le siège',
        'cesarienne' => 'Césarienne',
    ];

    public const PRESENTATIONS = [
        'cephalique' => 'Céphalique',
        'siege' => 'Siège',
        'transverse' => 'Transverse',
        'face' => 'Face',
        'autre' => 'Autre',
    ];

    public const DELIVRANCES = [
        'naturelle' => 'Naturelle et complète',
        'dirigee' => 'Dirigée',
        'artificielle' => 'Artificielle',
        'revision_uterine' => 'Suivie d\'une révision utérine',
    ];

    public const DECHIRURES = [
        'aucune' => 'Aucune',
        'degre_1' => '1er degré',
        'degre_2' => '2e degré',
        'degre_3' => '3e degré',
        'degre_4' => '4e degré',
    ];

    public const ETATS_MERE = [
        'bon' => 'Bon état général',
        'complique' => 'Suites compliquées',
        'grave' => 'État grave',
        'deces' => 'Décès maternel',
    ];

    /** Seuil au-delà duquel on parle d'hémorragie de la délivrance. */
    public const SEUIL_HEMORRAGIE_ML = 500;

    public function grossesse(): BelongsTo
    {
        return $this->belongsTo(Grossesse::class);
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }

    public function accoucheur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accoucheur_id');
    }

    /** Césarienne : l'intervention correspondante au bloc opératoire. */
    public function acteClinique(): BelongsTo
    {
        return $this->belongsTo(ActeClinique::class, 'acte_clinique_id');
    }

    public function nouveauNes(): HasMany
    {
        return $this->hasMany(NouveauNe::class)->orderBy('rang');
    }

    public function libelleMode(): string
    {
        return self::MODES[$this->mode] ?? $this->mode;
    }

    public function estCesarienne(): bool
    {
        return $this->mode === 'cesarienne';
    }

    /** Durée du travail, quand elle a été relevée. */
    public function dureeTravail(): ?string
    {
        if (! $this->debut_travail) {
            return null;
        }

        $minutes = (int) $this->debut_travail->diffInMinutes($this->date_accouchement);

        return intdiv($minutes, 60).' h '.str_pad((string) ($minutes % 60), 2, '0', STR_PAD_LEFT);
    }

    /**
     * Hémorragie de la délivrance : plus de 500 ml après une voie basse,
     * plus de 1 000 ml après une césarienne.
     */
    public function estHemorragique(): bool
    {
        if ($this->saignement_ml === null) {
            return false;
        }

        $seuil = $this->estCesarienne() ? 1000 : self::SEUIL_HEMORRAGIE_ML;

        return $this->saignement_ml > $seuil;
    }

    public function estPremature(): bool
    {
        return $this->terme_semaines !== null && $this->terme_semaines < 37;
    }

    /** Grossesse multiple : jumeaux, triplés. */
    public function estMultiple(): bool
    {
        return $this->nouveauNes->count() > 1;
    }
}
