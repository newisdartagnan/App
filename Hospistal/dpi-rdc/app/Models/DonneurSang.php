<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Donneur de sang.
 *
 * C'est le vrai stock de l'hôpital : le réfrigérateur se vide en une nuit,
 * le fichier des donneurs, lui, permet d'appeler quelqu'un à trois heures
 * du matin. On tient donc à jour qui peut donner, de quel groupe, et quand
 * il a donné pour la dernière fois.
 */
class DonneurSang extends Model
{
    use HasUuids;

    protected $table = 'donneurs_sang';

    protected $fillable = [
        'establishment_id', 'patient_id', 'code', 'nom', 'postnom', 'prenom',
        'sexe', 'date_naissance', 'groupe_sanguin', 'telephone', 'adresse',
        'type_donneur', 'dernier_don', 'nombre_dons', 'est_eligible',
        'motif_exclusion', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'date_naissance' => 'date',
            'dernier_don' => 'date',
            'est_eligible' => 'boolean',
        ];
    }

    public const TYPES = [
        'benevole' => 'Bénévole',
        'familial' => 'Familial (proche du patient)',
        'remunere' => 'Rémunéré',
        'autologue' => 'Autologue (pour lui-même)',
    ];

    /**
     * Délai minimal entre deux dons de sang total : huit semaines pour un
     * homme, douze pour une femme, dont les réserves en fer se reconstituent
     * plus lentement.
     */
    public const DELAI_HOMME_JOURS = 56;

    public const DELAI_FEMME_JOURS = 84;

    public function establishment(): BelongsTo
    {
        return $this->belongsTo(Establishment::class);
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function poches(): HasMany
    {
        return $this->hasMany(PocheSang::class, 'donneur_id');
    }

    public function nomComplet(): string
    {
        return trim(mb_strtoupper($this->nom).' '.$this->postnom.' '.$this->prenom);
    }

    public function libelleType(): string
    {
        return self::TYPES[$this->type_donneur] ?? $this->type_donneur;
    }

    private function delaiJours(): int
    {
        return $this->sexe === 'F' ? self::DELAI_FEMME_JOURS : self::DELAI_HOMME_JOURS;
    }

    /** Date à partir de laquelle ce donneur pourra redonner. */
    public function prochainDonPossible(): ?Carbon
    {
        return $this->dernier_don?->copy()->addDays($this->delaiJours());
    }

    /**
     * Peut-on l'appeler ce soir ?
     *
     * Il faut qu'il n'ait pas été écarté et que le délai depuis son dernier
     * don soit écoulé — sans quoi on le fatiguerait pour rien.
     */
    public function peutDonnerMaintenant(): bool
    {
        if (! $this->est_eligible) {
            return false;
        }

        $prochain = $this->prochainDonPossible();

        return $prochain === null || $prochain->isPast() || $prochain->isToday();
    }

    /** Pourquoi il ne peut pas donner, s'il ne peut pas. */
    public function motifIndisponibilite(): ?string
    {
        if (! $this->est_eligible) {
            return 'Donneur écarté'.($this->motif_exclusion ? ' : '.$this->motif_exclusion : '.');
        }

        $prochain = $this->prochainDonPossible();

        if ($prochain && $prochain->isFuture()) {
            return 'Délai non écoulé — pourra redonner le '.$prochain->format('d/m/Y').'.';
        }

        return null;
    }

    /** Donneurs joignables pour un receveur de ce groupe. */
    public function scopeCompatiblesAvec(Builder $query, ?string $groupeReceveur): Builder
    {
        return $query->where('est_eligible', true)
            ->whereIn('groupe_sanguin', PocheSang::groupesCompatiblesPour($groupeReceveur));
    }
}
