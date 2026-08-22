<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Fiche obstétricale d'une grossesse : ouverte à la première consultation
 * prénatale, close à l'accouchement.
 */
class Grossesse extends Model
{
    use HasUuids;

    protected $fillable = [
        'establishment_id', 'patient_id', 'date_dernieres_regles',
        'date_prevue_accouchement', 'gestite', 'parite', 'avortements',
        'enfants_vivants', 'groupe_sanguin', 'antecedents', 'serologies',
        'grossesse_a_risque', 'motif_risque', 'statut', 'date_cloture', 'user_id',
    ];

    protected function casts(): array
    {
        return [
            'date_dernieres_regles' => 'date',
            'date_prevue_accouchement' => 'date',
            'date_cloture' => 'date',
            'serologies' => 'array',
            'grossesse_a_risque' => 'boolean',
        ];
    }

    public const STATUTS = [
        'en_cours' => 'Grossesse en cours',
        'accouchee' => 'Accouchée',
        'interrompue' => 'Interrompue',
    ];

    /** Sérologies suivies en consultation prénatale. */
    public const SEROLOGIES = [
        'vih' => 'VIH',
        'syphilis' => 'Syphilis (TPHA/VDRL)',
        'hepatite_b' => 'Hépatite B (AgHBs)',
        'toxoplasmose' => 'Toxoplasmose',
        'rubeole' => 'Rubéole',
    ];

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function consultations(): HasMany
    {
        return $this->hasMany(ConsultationPrenatale::class)->orderBy('date_consultation');
    }

    public function accouchement(): HasOne
    {
        return $this->hasOne(Accouchement::class);
    }

    /**
     * Date prévue d'accouchement, calculée selon la règle de Naegele :
     * dernières règles + 280 jours.
     */
    public static function calculerDpa(?Carbon $dernieresRegles): ?Carbon
    {
        return $dernieresRegles?->copy()->addDays(280);
    }

    /**
     * Terme en semaines d'aménorrhée à une date donnée.
     *
     * C'est le repère de toute la surveillance : avant 37 semaines
     * l'accouchement est prématuré, après 42 il est dépassé.
     */
    public function termeSemaines(?Carbon $date = null): ?int
    {
        if (! $this->date_dernieres_regles) {
            return null;
        }

        $reference = $date ?? now();

        return (int) floor($this->date_dernieres_regles->diffInDays($reference) / 7);
    }

    /** Formule obstétricale : G3 P2 A0 — trois grossesses, deux accouchements. */
    public function formuleObstetricale(): string
    {
        return 'G'.$this->gestite.' P'.$this->parite.' A'.$this->avortements;
    }

    public function estEnCours(): bool
    {
        return $this->statut === 'en_cours';
    }

    public function libelleStatut(): string
    {
        return self::STATUTS[$this->statut] ?? $this->statut;
    }

    /** Sérologies positives, en toutes lettres. */
    public function serologiesPositives(): array
    {
        return collect($this->serologies ?? [])
            ->filter(fn ($resultat) => $resultat === 'positif')
            ->keys()
            ->map(fn ($cle) => self::SEROLOGIES[$cle] ?? $cle)
            ->all();
    }

    /**
     * Doses de SP recommandées sur une grossesse, à partir du 2e trimestre.
     *
     * Le traitement préventif intermittent du paludisme se donne au moins
     * trois fois : c'est la mesure qui pèse le plus sur le petit poids de
     * naissance en zone d'endémie.
     */
    public const SP_RECOMMANDEES = 3;

    /** Doses d'un schéma complet de vaccin antitétanique. */
    public const VAT_COMPLET = 5;

    /**
     * Paquet préventif reçu au fil des consultations prénatales.
     *
     * La sage-femme cochait fer, SP et moustiquaire à chaque visite sans
     * jamais pouvoir relire ce qui avait déjà été donné : elle redonnait ou
     * elle oubliait. Voici le compte, et ce qui reste dû.
     *
     * @return array<string, mixed>
     */
    public function paquetPreventif(): array
    {
        $cpn = $this->relationLoaded('consultations')
            ? $this->consultations
            : $this->consultations()->get();

        $vat = (int) ($cpn->max('vat_dose') ?? 0);
        $sp = $cpn->where('sulfadoxine_pyrimethamine', true)->count();
        $fer = $cpn->where('fer_folates', true)->count();
        $moustiquaire = $cpn->where('moustiquaire_remise', true)->isNotEmpty();

        return [
            'vat_dose' => $vat,
            'vat_complet' => $vat >= self::VAT_COMPLET,
            'sp_recues' => $sp,
            'sp_restantes' => max(0, self::SP_RECOMMANDEES - $sp),
            'fer_visites' => $fer,
            'moustiquaire' => $moustiquaire,
            'manques' => array_values(array_filter([
                $sp < self::SP_RECOMMANDEES
                    ? (self::SP_RECOMMANDEES - $sp).' dose(s) de SP à donner'
                    : null,
                $fer === 0 ? 'Fer et acide folique jamais délivrés' : null,
                $moustiquaire ? null : 'Moustiquaire imprégnée non remise',
                $vat === 0 ? 'Aucune dose de vaccin antitétanique notée' : null,
            ])),
        ];
    }
}
