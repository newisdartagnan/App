<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Medicament extends Model
{
    use HasUuids;

    protected $fillable = [
        'establishment_id', 'code_ucd', 'denomination_commune', 'nom_commercial',
        'forme', 'dosage', 'unite_dispensation', 'classe_therapeutique',
        'necessite_ordonnance', 'est_actif',
        'voie_administration', 'conditionnement', 'unites_par_conditionnement',
    ];

    protected function casts(): array
    {
        return [
            'necessite_ordonnance' => 'boolean',
            'est_actif' => 'boolean',
            'unites_par_conditionnement' => 'integer',
        ];
    }

    /** Voies d'administration, telles qu'elles se lisent sur l'ordonnance. */
    public const VOIES = [
        'orale' => 'voie orale',
        'injectable' => 'voie injectable',
        'cutanee' => 'voie cutanée',
        'rectale' => 'voie rectale',
        'vaginale' => 'voie vaginale',
        'oculaire' => 'voie oculaire',
        'auriculaire' => 'voie auriculaire',
        'nasale' => 'voie nasale',
        'inhalee' => 'voie inhalée',
        'autre' => 'autre voie',
    ];

    /** Contenants et nombre d'unités qu'ils renferment. */
    public const CONDITIONNEMENTS = [
        'plaquette' => 'plaquette',
        'boite' => 'boîte',
        'flacon' => 'flacon',
        'ampoule' => 'ampoule',
        'tube' => 'tube',
        'sachet' => 'sachet',
        'unite' => 'unité',
    ];

    /** Unité délivrée, au singulier puis au pluriel, par forme galénique. */
    public const UNITES = [
        'cp' => ['comprimé', 'comprimés'],
        'gel' => ['gélule', 'gélules'],
        'flacon' => ['flacon', 'flacons'],
        'ampoule' => ['ampoule', 'ampoules'],
        'sachet' => ['sachet', 'sachets'],
        'suppo' => ['suppositoire', 'suppositoires'],
        'tube' => ['tube', 'tubes'],
        'unite' => ['unité', 'unités'],
    ];

    /** Conditionnement usuel par forme : contenant et nombre d'unités. */
    public const CONDITIONNEMENT_PAR_FORME = [
        'comprime' => ['plaquette', 10],
        'gelule' => ['plaquette', 10],
        'suppositoire' => ['plaquette', 10],
        'sachet' => ['boite', 10],
        'sirop' => ['flacon', 1],
        'injectable' => ['flacon', 1],
        'collyre' => ['flacon', 1],
        'pommade' => ['tube', 1],
        'creme' => ['tube', 1],
        'autre' => ['unite', 1],
    ];

    public const VOIE_PAR_FORME = [
        'comprime' => 'orale',
        'gelule' => 'orale',
        'sirop' => 'orale',
        'sachet' => 'orale',
        'injectable' => 'injectable',
        'suppositoire' => 'rectale',
        'pommade' => 'cutanee',
        'creme' => 'cutanee',
        'collyre' => 'oculaire',
        'autre' => 'autre',
    ];

    public const UNITES_PAR_FORME = [
        'comprime' => 'cp',
        'gelule' => 'gel',
        'sirop' => 'flacon',
        'sachet' => 'sachet',
        'injectable' => 'flacon',
        'suppositoire' => 'suppo',
        'pommade' => 'tube',
        'creme' => 'tube',
        'collyre' => 'flacon',
        'autre' => 'unite',
    ];

    public function establishment(): BelongsTo
    {
        return $this->belongsTo(Establishment::class);
    }

    /**
     * Stock de dispensation du produit. Plusieurs officines peuvent en
     * détenir : on retient celle qui délivre aux patients (ambulatoire),
     * puis à défaut le premier stock trouvé.
     */
    public function stock(): HasOne
    {
        return $this->hasOne(StockMedicament::class)
            // Priorité au point de délivrance : officine ambulatoire, puis
            // officine de service, et en dernier le dépôt central (réserve).
            ->orderByRaw("COALESCE((
                SELECT CASE o.type WHEN 'ambulatoire' THEN 0 WHEN 'service' THEN 1 ELSE 3 END
                FROM officines o WHERE o.id = stock_medicaments.officine_id
            ), 2)")
            ->orderByDesc('quantite_disponible');
    }

    public function stocks(): HasMany
    {
        return $this->hasMany(StockMedicament::class);
    }

    // ═══════════════════════════════════════════════════════════
    // Libellés destinés au prescripteur
    // ═══════════════════════════════════════════════════════════

    /** Nom et dosage : « Paracétamol 500 mg ». */
    public function designation(): string
    {
        return trim($this->denomination_commune.' '.$this->dosage);
    }

    /** Unité délivrée, accordée : « comprimé » / « comprimés ». */
    public function unite(float $quantite = 1): string
    {
        [$singulier, $pluriel] = self::UNITES[$this->unite_dispensation] ?? self::UNITES['unite'];

        return $quantite > 1 ? $pluriel : $singulier;
    }

    public function libelleVoie(): string
    {
        return self::VOIES[$this->voie_administration] ?? self::VOIES['autre'];
    }

    /**
     * Conditionnement en toutes lettres : « plaquette de 10 comprimés »,
     * « flacon ».
     */
    public function libelleConditionnement(): string
    {
        $contenant = self::CONDITIONNEMENTS[$this->conditionnement] ?? $this->conditionnement;
        $unites = max(1, (int) $this->unites_par_conditionnement);

        if ($unites <= 1) {
            return $contenant;
        }

        return $contenant.' de '.$unites.' '.$this->unite($unites);
    }

    /**
     * Ce que le médecin doit lire dans la liste déroulante, après le stock :
     * « Paracétamol 500 mg (417) — voie orale / plaquette de 10 comprimés ».
     *
     * La voie et le conditionnement sous les yeux, la posologie se pose sans
     * avoir à ouvrir la fiche du produit.
     */
    public function libelleComplet(?float $stock = null): string
    {
        $libelle = $this->designation();

        if ($stock !== null) {
            $libelle .= ' ('.($stock + 0).')';
        }

        return $libelle.' — '.$this->libelleVoie().' / '.$this->libelleConditionnement();
    }

    // ═══════════════════════════════════════════════════════════
    // Du schéma posologique à la quantité délivrée
    // ═══════════════════════════════════════════════════════════

    /**
     * Quantité découlant d'un schéma posologique.
     *
     * Une prise de deux comprimés, trois fois par jour, cinq jours, fait
     * trente comprimés. Le médecin pose sa posologie, le système compte.
     */
    public static function quantiteTheorique(float $dose, int $frequence, int $duree): float
    {
        return round(max(0, $dose) * max(0, $frequence) * max(0, $duree), 2);
    }

    /**
     * Ce qui sortira réellement du tiroir pour une quantité prescrite.
     *
     * Une plaquette ne se coupe pas en deux : quinze comprimés se délivrent
     * en deux plaquettes de dix, soit vingt comprimés. C'est cette quantité
     * majorée qui est dispensée, et donc facturée.
     *
     * @return array{unites: float, conditionnements: int, majoration: float}
     */
    public function conditionnementPour(float $quantitePrescrite): array
    {
        $parBoite = max(1, (int) $this->unites_par_conditionnement);
        $prescrite = max(0.0, $quantitePrescrite);

        $boites = (int) ceil($prescrite / $parBoite);
        $unites = (float) ($boites * $parBoite);

        return [
            'unites' => $unites,
            'conditionnements' => $boites,
            'majoration' => round($unites - $prescrite, 2),
        ];
    }

    /** Le produit se délivre-t-il par contenant entier ? */
    public function seDelivreParConditionnement(): bool
    {
        return (int) $this->unites_par_conditionnement > 1;
    }
}
