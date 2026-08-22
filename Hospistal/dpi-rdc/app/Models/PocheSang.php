<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Une poche de sang au réfrigérateur.
 *
 * Deux règles la gouvernent, et aucune ne souffre d'exception : elle ne sort
 * pas de quarantaine tant que son dépistage n'est pas complet et négatif, et
 * elle n'est délivrée qu'à un receveur dont le groupe l'accepte.
 */
class PocheSang extends Model
{
    use HasUuids;

    protected $table = 'poches_sang';

    protected $fillable = [
        'establishment_id', 'donneur_id', 'numero', 'groupe_sanguin', 'type_produit',
        'volume_ml', 'date_prelevement', 'date_peremption', 'statut', 'emplacement',
        'depistage_vih', 'depistage_hepatite_b', 'depistage_hepatite_c',
        'depistage_syphilis', 'depistage_paludisme', 'date_depistage', 'depiste_par',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'date_prelevement' => 'date',
            'date_peremption' => 'date',
            'date_depistage' => 'date',
            'depistage_vih' => 'boolean',
            'depistage_hepatite_b' => 'boolean',
            'depistage_hepatite_c' => 'boolean',
            'depistage_syphilis' => 'boolean',
            'depistage_paludisme' => 'boolean',
        ];
    }

    /** Les huit groupes du système ABO-Rhésus. */
    public const GROUPES = ['O-', 'O+', 'A-', 'A+', 'B-', 'B+', 'AB-', 'AB+'];

    public const PRODUITS = [
        'sang_total' => 'Sang total',
        'concentre_globulaire' => 'Concentré de globules rouges',
        'plasma_frais' => 'Plasma frais congelé',
        'plaquettes' => 'Concentré plaquettaire',
        'cryoprecipite' => 'Cryoprécipité',
    ];

    public const STATUTS = [
        'quarantaine' => 'En quarantaine',
        'disponible' => 'Disponible',
        'reservee' => 'Réservée',
        'transfusee' => 'Transfusée',
        'perimee' => 'Périmée',
        'detruite' => 'Détruite',
    ];

    /**
     * Durée de conservation, en jours, selon le produit.
     *
     * Le plasma congelé se garde un an ; les plaquettes cinq jours à peine.
     */
    public const CONSERVATION_JOURS = [
        'sang_total' => 35,
        'concentre_globulaire' => 42,
        'plasma_frais' => 365,
        'plaquettes' => 5,
        'cryoprecipite' => 365,
    ];

    /**
     * Correspondance entre les produits de la banque et le vocabulaire de la
     * feuille de transfusion, qui porte déjà les règles de compatibilité.
     */
    public const PRODUIT_TRANSFUSION = [
        'sang_total' => 'sang_total',
        'concentre_globulaire' => 'cgr',
        'plasma_frais' => 'pfc',
        'plaquettes' => 'cp',
        // Le cryoprécipité est un dérivé du plasma : mêmes règles.
        'cryoprecipite' => 'pfc',
    ];

    public function establishment(): BelongsTo
    {
        return $this->belongsTo(Establishment::class);
    }

    public function donneur(): BelongsTo
    {
        return $this->belongsTo(DonneurSang::class, 'donneur_id');
    }

    public function depistePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'depiste_par');
    }

    // ═══════════════════════════════════════════════════════════
    // Compatibilité
    // ═══════════════════════════════════════════════════════════

    /**
     * Groupes donneurs acceptés par un receveur, pour un produit donné.
     *
     * La règle vit sur la feuille de transfusion, où elle est déjà écrite —
     * globulaire dans un sens, plasmatique dans l'autre. On ne la recopie pas
     * ici : deux tables de compatibilité finiraient par diverger, et celle qui
     * a tort tue.
     *
     * @return array<int, string>
     */
    public static function groupesCompatiblesPour(?string $groupeReceveur, string $typeProduit = 'sang_total'): array
    {
        // Sans groupe connu, seul le donneur universel est envisageable.
        if (! in_array($groupeReceveur, self::GROUPES, true)) {
            return ['O-'];
        }

        $produit = self::PRODUIT_TRANSFUSION[$typeProduit] ?? 'sang_total';

        return array_values(array_filter(
            self::GROUPES,
            fn (string $donneur) => Transfusion::estCompatible($produit, $donneur, $groupeReceveur)
        ));
    }

    /** Cette poche peut-elle être transfusée à ce receveur ? */
    public function estCompatibleAvec(?string $groupeReceveur): bool
    {
        return in_array(
            $this->groupe_sanguin,
            self::groupesCompatiblesPour($groupeReceveur, $this->type_produit),
            true
        );
    }

    /**
     * Ce que coûte l'unité délivrée.
     *
     * Le don est bénévole, la poche ne l'est pas : prélèvement, cinq
     * dépistages, poche et chaîne du froid sont à la charge de l'hôpital.
     */
    public function tarif(): float
    {
        $tarifs = config('dpi.sang.tarifs', []);

        return (float) ($tarifs[$this->type_produit] ?? ($tarifs['sang_total'] ?? 0));
    }

    /** Receveurs auxquels cette poche peut être donnée. */
    public function receveursPossibles(): array
    {
        return array_values(array_filter(
            self::GROUPES,
            fn (string $receveur) => $this->estCompatibleAvec($receveur)
        ));
    }

    // ═══════════════════════════════════════════════════════════
    // Dépistage et disponibilité
    // ═══════════════════════════════════════════════════════════

    /** Le dépistage est-il complet, tous marqueurs renseignés ? */
    public function depistageComplet(): bool
    {
        foreach ($this->marqueurs() as $resultat) {
            if ($resultat === null) {
                return false;
            }
        }

        return true;
    }

    /**
     * Marqueurs positifs : chacun interdit la transfusion et écarte le donneur.
     *
     * @return array<int, string>
     */
    public function marqueursPositifs(): array
    {
        return collect($this->marqueurs())
            ->filter(fn ($resultat) => $resultat === true)
            ->keys()
            ->all();
    }

    /** @return array<string, ?bool> */
    private function marqueurs(): array
    {
        return [
            'VIH' => $this->depistage_vih,
            'Hépatite B' => $this->depistage_hepatite_b,
            'Hépatite C' => $this->depistage_hepatite_c,
            'Syphilis' => $this->depistage_syphilis,
            'Paludisme' => $this->depistage_paludisme,
        ];
    }

    public function estPerimee(): bool
    {
        return $this->date_peremption->isPast();
    }

    /** Une poche prête à partir : dépistée, négative, non périmée, en rayon. */
    public function estDelivrable(): bool
    {
        return $this->statut === 'disponible'
            && $this->depistageComplet()
            && $this->marqueursPositifs() === []
            && ! $this->estPerimee();
    }

    /**
     * Pourquoi cette poche ne peut pas partir. Retourne null si elle le peut.
     */
    public function motifIndisponibilite(): ?string
    {
        if ($this->estPerimee()) {
            return 'Poche périmée le '.$this->date_peremption->format('d/m/Y').'.';
        }

        if (! $this->depistageComplet()) {
            return 'Dépistage incomplet : la poche reste en quarantaine.';
        }

        if ($positifs = $this->marqueursPositifs()) {
            return 'Dépistage positif ('.implode(', ', $positifs).') — poche à détruire.';
        }

        if ($this->statut !== 'disponible') {
            return 'Poche '.mb_strtolower(self::STATUTS[$this->statut] ?? $this->statut).'.';
        }

        return null;
    }

    public function joursAvantPeremption(): int
    {
        return (int) now()->startOfDay()->diffInDays($this->date_peremption, false);
    }

    public function libelleProduit(): string
    {
        return self::PRODUITS[$this->type_produit] ?? $this->type_produit;
    }

    public function libelleStatut(): string
    {
        return self::STATUTS[$this->statut] ?? $this->statut;
    }

    // ═══════════════════════════════════════════════════════════
    // Requêtes courantes
    // ═══════════════════════════════════════════════════════════

    public function scopeDelivrables(Builder $query): Builder
    {
        return $query->where('statut', 'disponible')
            ->whereDate('date_peremption', '>=', now()->toDateString())
            ->where('depistage_vih', false)
            ->where('depistage_hepatite_b', false)
            ->where('depistage_hepatite_c', false)
            ->where('depistage_syphilis', false)
            ->where('depistage_paludisme', false);
    }

    /** Poches transfusables à un receveur de ce groupe, les plus anciennes d'abord. */
    public function scopeCompatiblesAvec(Builder $query, ?string $groupeReceveur, string $typeProduit = 'sang_total'): Builder
    {
        return $query->delivrables()
            ->where('type_produit', $typeProduit)
            ->whereIn('groupe_sanguin', self::groupesCompatiblesPour($groupeReceveur, $typeProduit))
            // On sort d'abord ce qui périme le plus tôt : le stock ne se
            // gaspille pas.
            ->orderBy('date_peremption');
    }
}
