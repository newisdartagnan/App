<?php

namespace App\Models;

use App\Models\Concerns\Syncable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un épisode de prise en charge nutritionnelle.
 *
 * Il dure des semaines et survit aux visites : l'enfant revient tous les
 * mardis se faire peser, reçoit ses sachets, et on ne le décharge que
 * lorsqu'il a repris. Ce n'est donc ni une visite ni une consultation, mais
 * un suivi à part, exactement comme le canevas le compte — des entrées d'un
 * côté, des issues de l'autre, chacune ventilée par sexe et par tranche.
 *
 * Trois unités, qui ne soignent pas les mêmes enfants :
 *
 * L'UNTA prend la malnutrition sévère sans complication, en ambulatoire.
 * L'UNTI prend la même chose avec complication, au lit, à l'hôpital.
 * L'UNS prend la malnutrition modérée et les groupes à risque — femmes
 * enceintes et allaitantes, personnes vivant avec le VIH, tuberculeux.
 *
 * Source : canevas mensuels du SNIS du 12 octobre 2024 — CS § 11.1 à 11.5,
 * HGR et HST § 7.1 et 7.2.
 */
class PriseEnChargeNutritionnelle extends Model
{
    use HasUuids, Syncable;

    protected $table = 'prises_en_charge_nutritionnelles';

    protected $fillable = [
        'patient_id', 'establishment_id', 'visit_id', 'user_id',
        'unite', 'date_admission', 'critere_admission', 'avec_complication',
        'mesure_id', 'groupe_specifique',
        'date_sortie', 'issue', 'sortie_par', 'observation',
    ];

    protected function casts(): array
    {
        return [
            'date_admission' => 'date',
            'date_sortie' => 'date',
            'avec_complication' => 'boolean',
        ];
    }

    public const UNITES = [
        'unta' => [
            'sigle' => 'UNTA',
            'nom' => 'Unité nutritionnelle thérapeutique ambulatoire',
            'pourquoi' => 'Malnutrition aiguë sévère sans complication — l\'enfant rentre chez lui avec ses sachets et revient chaque semaine.',
        ],
        'unti' => [
            'sigle' => 'UNTI',
            'nom' => 'Unité nutritionnelle thérapeutique intensive',
            'pourquoi' => 'Malnutrition aiguë sévère avec complication — l\'enfant est hospitalisé.',
        ],
        'uns' => [
            'sigle' => 'UNS',
            'nom' => 'Unité nutritionnelle supplémentaire',
            'pourquoi' => 'Malnutrition aiguë modérée, et les groupes à risque suivis à part.',
        ],
    ];

    /**
     * Les motifs d'entrée, tels que le canevas les compte.
     *
     * Ce ne sont pas des diagnostics mais des lignes de formulaire : il
     * fallait qu'ils portent les mêmes mots, sinon le report à la main
     * recommence.
     */
    public const CRITERES = [
        'pt_pb' => 'Nouvelle admission — PT < -3 DS ou PB < 115 mm',
        'oedemes' => 'Nouvelle admission — œdèmes bilatéraux',
        'rechute' => 'Nouvelle admission — rechute',
        'autre' => 'Autre entrée',
        'mam_depistee' => 'MAM dépistée à l\'UNS',
        'mam_transferee' => 'MAM transférée de l\'UNTA ou de l\'UNTI',
    ];

    /** Les motifs que le canevas réserve à l'unité de supplémentation. */
    public const CRITERES_UNS = ['mam_depistee', 'mam_transferee'];

    /**
     * Les issues, unité par unité.
     *
     * Elles ne sont pas les mêmes : l'UNTA réfère vers le haut quand
     * l'enfant se complique, l'UNTI contre-réfère vers le bas quand il va
     * mieux. Confondre les deux ferait un rapport faux dans les deux sens.
     */
    public const ISSUES = [
        'unta' => [
            'gueri' => 'Guéri',
            'non_repondant' => 'Non répondant',
            'deces' => 'Décès',
            'abandon' => 'Abandon',
            'refere_unti' => 'Référé à l\'UNTI',
            'transfere_unta' => 'Transféré vers un autre UNTA',
        ],
        'unti' => [
            'gueri' => 'Guéri',
            'non_repondant' => 'Non répondant',
            'deces' => 'Décès',
            'abandon' => 'Abandon',
            'refere_autre_unti' => 'Référé à un autre UNTI',
            'contre_refere_unta' => 'Contre-référé vers l\'UNTA',
        ],
        'uns' => [
            'gueri' => 'Déchargé guéri',
            'refere_unta' => 'Référé à l\'UNTA',
            'deces' => 'Décès',
            'abandon' => 'Abandon',
        ],
    ];

    /** Les groupes que l'UNS suit à part. */
    public const GROUPES_SPECIFIQUES = [
        'femme_enceinte' => 'Femme enceinte malnutrie (PB < 230 mm)',
        'femme_allaitante' => 'Femme allaitante malnutrie (PB < 230 mm)',
        'pvvih' => 'Personne vivant avec le VIH malnutrie (IMC < 18,5)',
        'tuberculeux' => 'Tuberculeux malnutri (IMC < 18,5)',
    ];

    /**
     * Les tranches d'âge du canevas nutritionnel.
     *
     * Elles ne sont pas celles du reste du rapport : la nutrition compte à
     * partir de six mois — avant, c'est l'allaitement — et coupe à deux ans
     * révolus. On les respecte telles quelles.
     */
    public const TRANCHES = [
        'six_23_mois' => ['libelle' => '6 – 23 mois', 'min' => 6, 'max' => 23],
        'vingt_quatre_59_mois' => ['libelle' => '24 – 59 mois', 'min' => 24, 'max' => 59],
        'cinq_ans_plus' => ['libelle' => '5 ans et plus', 'min' => 60, 'max' => null],
    ];

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }

    public function mesure(): BelongsTo
    {
        return $this->belongsTo(MesureAnthropometrique::class, 'mesure_id');
    }

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function sortiePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sortie_par');
    }

    public function estEnCours(): bool
    {
        return $this->date_sortie === null;
    }

    public function sigle(): string
    {
        return self::UNITES[$this->unite]['sigle'] ?? strtoupper((string) $this->unite);
    }

    public function libelleCritere(): string
    {
        return self::CRITERES[$this->critere_admission] ?? 'Entrée non précisée';
    }

    public function libelleIssue(): string
    {
        return self::ISSUES[$this->unite][$this->issue] ?? 'Suivi en cours';
    }

    /**
     * La tranche d'âge du canevas à la date d'admission.
     *
     * Un enfant admis à vingt-trois mois et déchargé à vingt-cinq reste
     * compté dans sa tranche d'entrée : sinon les entrées et les issues du
     * mois ne se recoupent plus.
     */
    public function tranche(): ?string
    {
        $naissance = $this->patient?->date_naissance;

        if (! $naissance) {
            return null;
        }

        $mois = $naissance->diffInMonths($this->date_admission);

        foreach (self::TRANCHES as $cle => $tranche) {
            if ($mois >= $tranche['min'] && ($tranche['max'] === null || $mois <= $tranche['max'])) {
                return $cle;
            }
        }

        // Moins de six mois : le canevas nutritionnel ne les compte pas.
        return null;
    }

    protected function getSyncPriority(): int
    {
        return 6;
    }
}
