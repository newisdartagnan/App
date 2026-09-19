<?php

namespace App\Models;

use App\Models\Concerns\Syncable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Une acceptante, une méthode, un jour.
 *
 * Le canevas ne compte pas des femmes mais des actes : la même cliente qui
 * revient chercher sa plaquette chaque mois compte chaque fois, en
 * renouvellement. C'est voulu — ce qu'on mesure, c'est la continuité de
 * l'approvisionnement, pas la file active.
 *
 * Source : canevas mensuels du SNIS du 12 octobre 2024 — § 3.1 et § 3.2.
 */
class ActePlanificationFamiliale extends Model
{
    use HasUuids, Syncable;

    protected $table = 'actes_planification_familiale';

    protected $fillable = [
        'patient_id', 'establishment_id', 'user_id', 'visit_id',
        'date_acte', 'type_acte', 'methode', 'type_acceptation',
        'canal', 'post_partum', 'observation',
    ];

    protected function casts(): array
    {
        return [
            'date_acte' => 'date',
            'post_partum' => 'boolean',
        ];
    }

    /**
     * Les méthodes du canevas, dans son ordre.
     *
     * Elles portent les mots du formulaire, nom commercial compris : c'est
     * sous ce nom-là qu'on les connaît au dépôt et sur la fiche de stock,
     * et les renommer obligerait à retraduire au moment du report.
     *
     * `masculine` marque les deux méthodes qui concernent un homme : le
     * canevas ventile par sexe, et un préservatif masculin compté chez la
     * femme fausserait la colonne.
     */
    public const METHODES = [
        'dmpa_im' => ['libelle' => 'Inj. Dépo-Provera (DMPA-IM)', 'masculine' => false],
        'dmpa_sc' => ['libelle' => 'Inj. Sayana Press (DMPA-SC)', 'masculine' => false],
        'dmpa_sc_auto' => ['libelle' => 'Inj. Sayana Press (DMPA-SC) — auto-injection', 'masculine' => false],
        'noristerat' => ['libelle' => 'Inj. Noristerat (NET-en)', 'masculine' => false],
        'pilule_progestative' => ['libelle' => 'Plaquette pilule orale progestative', 'masculine' => false],
        'pilule_combinee' => ['libelle' => 'Plaquette pilule combinée (COC)', 'masculine' => false],
        'pilule_urgence' => ['libelle' => 'Pilule d\'urgence', 'masculine' => false],
        'diu_cuivre' => ['libelle' => 'DIU placé 10 ans (DIU en cuivre)', 'masculine' => false],
        'diu_hormonal' => ['libelle' => 'DIU placé 5 ans (DIU hormonal)', 'masculine' => false],
        'preservatif_masculin' => ['libelle' => 'Préservatif masculin', 'masculine' => true],
        'preservatif_feminin' => ['libelle' => 'Préservatif féminin', 'masculine' => false],
        'levoplant' => ['libelle' => 'Levoplant', 'masculine' => false],
        'jadelle' => ['libelle' => 'Jadelle', 'masculine' => false],
        'implanon' => ['libelle' => 'Implanon', 'masculine' => false],
        'collier_cycle' => ['libelle' => 'Collier du cycle', 'masculine' => false],
        'mama' => ['libelle' => 'MAMA (allaitement maternel et aménorrhée)', 'masculine' => false],
        'mao' => ['libelle' => 'MAO (méthode des jours fixes)', 'masculine' => false],
        'vasectomie' => ['libelle' => 'Stérilisation masculine (vasectomie)', 'masculine' => true],
        'ligature' => ['libelle' => 'Stérilisation féminine (ligature des trompes)', 'masculine' => false],
    ];

    /** Les méthodes que le canevas tient pour modernes en post-partum. */
    public const METHODES_MODERNES = [
        'dmpa_im', 'dmpa_sc', 'dmpa_sc_auto', 'noristerat',
        'pilule_progestative', 'pilule_combinee',
        'diu_cuivre', 'diu_hormonal',
        'preservatif_masculin', 'preservatif_feminin',
        'levoplant', 'jadelle', 'implanon',
        'vasectomie', 'ligature',
    ];

    public const TYPES_ACTE = [
        'methode' => 'Méthode remise ou posée',
        'conseil_post_partum' => 'Conseil en PF du post-partum (sans méthode)',
    ];

    public const ACCEPTATIONS = [
        'nouvelle' => 'Nouvelle acceptante',
        'renouvellement' => 'Renouvellement',
    ];

    /**
     * Le canal, tel que le canevas le colonne.
     *
     * DBC : la distribution à base communautaire, celle du relais qui fait
     * la tournée avec sa mallette. Elle ne se confond pas avec l'activité du
     * centre, et c'est la comparaison des deux qui dit si la stratégie
     * avancée touche vraiment du monde.
     */
    public const CANAUX = [
        'ess' => 'ESS — au centre de santé',
        'dbc' => 'DBC — distribution à base communautaire',
    ];

    /**
     * Les tranches d'âge du canevas PF.
     *
     * Elles commencent à dix ans, et la première s'arrête à quatorze : une
     * acceptante de cette tranche appelle autre chose qu'un décompte, et la
     * séparer est le seul moyen de la voir.
     */
    public const TRANCHES = [
        'dix_14' => ['libelle' => '10 – 14 ans', 'min' => 10, 'max' => 14],
        'quinze_19' => ['libelle' => '15 – 19 ans', 'min' => 15, 'max' => 19],
        'vingt_24' => ['libelle' => '20 – 24 ans', 'min' => 20, 'max' => 24],
        'vingt_cinq_plus' => ['libelle' => '25 ans et plus', 'min' => 25, 'max' => null],
    ];

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function libelleMethode(): string
    {
        return self::METHODES[$this->methode]['libelle'] ?? 'Méthode non précisée';
    }

    public function libelleCanal(): string
    {
        return self::CANAUX[$this->canal] ?? $this->canal;
    }

    public function estMethodeModerne(): bool
    {
        return in_array($this->methode, self::METHODES_MODERNES, true);
    }

    /**
     * La tranche d'âge de l'acceptante, le jour de l'acte.
     *
     * Une cliente sans date de naissance ne se range pas au hasard : elle
     * est comptée à part, et l'écran le montre.
     */
    public function tranche(): ?string
    {
        $naissance = $this->patient?->date_naissance;

        if (! $naissance) {
            return null;
        }

        $age = (int) $naissance->diffInYears($this->date_acte);

        foreach (self::TRANCHES as $cle => $tranche) {
            if ($age >= $tranche['min'] && ($tranche['max'] === null || $age <= $tranche['max'])) {
                return $cle;
            }
        }

        // Moins de dix ans : le canevas PF ne commence pas là.
        return null;
    }

    protected function getSyncPriority(): int
    {
        return 6;
    }
}
