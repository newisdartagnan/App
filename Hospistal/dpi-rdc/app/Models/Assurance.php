<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Assurance extends Model
{
    use HasUuids;

    protected $fillable = [
        'nom', 'code', 'taux_couverture', 'plafond_annuel_usd',
        'plafond_annuel_cdf', 'est_actif', 'notes',
        'delai_reglement_jours', 'mode_reglement', 'periodicite_facturation',
        'ticket_moderateur', 'contact_nom', 'contact_telephone', 'contact_email',
        'contrat_debut', 'contrat_fin', 'exige_bon_prise_en_charge', 'coefficient_tarif',
    ];

    protected function casts(): array
    {
        return [
            'est_actif' => 'boolean',
            'exige_bon_prise_en_charge' => 'boolean',
            'contrat_debut' => 'date',
            'contrat_fin' => 'date',
            'coefficient_tarif' => 'decimal:4',
            'taux_couverture' => 'decimal:2',
            'ticket_moderateur' => 'decimal:2',
        ];
    }

    /** Modes de règlement acceptés d'une société conventionnée. */
    public const MODES_REGLEMENT = [
        'virement' => 'Virement bancaire',
        'cheque' => 'Chèque',
        'especes' => 'Espèces',
        'mobile_money' => 'Mobile money',
        'compensation' => 'Compensation sur prestations',
    ];

    /** Rythme d'émission des factures de convention. */
    public const PERIODICITES = [
        'hebdomadaire' => 'Hebdomadaire',
        'quinzaine' => 'Tous les quinze jours',
        'mensuelle' => 'Mensuelle',
        'trimestrielle' => 'Trimestrielle',
    ];

    public function libelleMode(): string
    {
        return self::MODES_REGLEMENT[$this->mode_reglement] ?? $this->mode_reglement;
    }

    public function libellePeriodicite(): string
    {
        return self::PERIODICITES[$this->periodicite_facturation] ?? $this->periodicite_facturation;
    }

    /**
     * Modalités de règlement en une phrase, telles qu'elles figurent au
     * contrat : « Virement bancaire, facturation mensuelle, à 30 jours ».
     */
    public function modalites(): string
    {
        return $this->libelleMode().', facturation '.mb_strtolower($this->libellePeriodicite())
            .', à '.$this->delai_reglement_jours.' jours';
    }

    /**
     * Date limite de règlement d'une facture émise à cette date, d'après le
     * délai accordé au contrat.
     */
    public function echeancePour(Carbon $emission): Carbon
    {
        return $emission->copy()->addDays((int) $this->delai_reglement_jours);
    }

    public function tarifs(): HasMany
    {
        return $this->hasMany(TarifConvention::class);
    }

    public function bonsPriseEnCharge(): HasMany
    {
        return $this->hasMany(BonPriseEnCharge::class);
    }

    /**
     * Pourquoi le contrat ne couvre pas cette date ; null s'il la couvre.
     * Sans dates saisies, le contrat est réputé en cours.
     */
    public function horsContrat(?Carbon $date = null): ?string
    {
        $date ??= now();

        return match (true) {
            ! $this->est_actif => 'la convention « '.$this->nom.' » est suspendue',
            $this->contrat_debut && $this->contrat_debut->gt($date) => 'la convention « '.$this->nom
                .' » ne prend effet que le '.$this->contrat_debut->format('d/m/Y'),
            $this->contrat_fin && $this->contrat_fin->lt($date->copy()->startOfDay()) => 'la convention « '.$this->nom
                .' » a expiré le '.$this->contrat_fin->format('d/m/Y'),
            default => null,
        };
    }

    /** Le contrat arrive-t-il à échéance dans les jours qui viennent ? */
    public function expireBientot(int $jours = 30): bool
    {
        return $this->contrat_fin !== null
            && $this->contrat_fin->gte(now()->startOfDay())
            && $this->contrat_fin->lte(now()->addDays($jours));
    }

    /**
     * Prix d'une prestation pour les bénéficiaires de cette convention :
     * le prix fixé pour la prestation, à défaut celui de sa catégorie, à
     * défaut le prix public multiplié par le coefficient du contrat.
     */
    public function prixPour(string $type, ?string $referenceId, float $prixPublic, bool $prixDeCategorie = true): float
    {
        $tarifs = $this->relationLoaded('tarifs') ? $this->tarifs : $this->tarifs()->get();

        if ($referenceId && ($tarif = $tarifs->first(fn ($t) => $t->type === $type && $t->reference_id === $referenceId))) {
            return (float) $tarif->prix;
        }

        if ($prixDeCategorie && ($tarif = $tarifs->first(fn ($t) => $t->type === $type && $t->reference_id === null))) {
            return (float) $tarif->prix;
        }

        $coefficient = (float) ($this->coefficient_tarif ?: 1);

        return round($prixPublic * $coefficient, 2);
    }

    /** « Prix public », « Prix public + 20 % », « Prix public − 10 % ». */
    public function libelleCoefficient(): string
    {
        $ecart = round(((float) ($this->coefficient_tarif ?: 1) - 1) * 100, 2);

        if (abs($ecart) < 0.01) {
            return 'Prix public';
        }

        return 'Prix public '.($ecart > 0 ? '+ ' : '− ')
            .rtrim(rtrim(number_format(abs($ecart), 2, ',', ' '), '0'), ',').' %';
    }

    public function couvertures(): HasMany
    {
        return $this->hasMany(AssuranceCouverture::class);
    }

    public function patientAssurances(): HasMany
    {
        return $this->hasMany(PatientAssurance::class);
    }

    public function couvreActe(string $type, ?string $referenceId = null): bool
    {
        // Si aucune règle définie pour ce type → couvert par défaut
        $regles = $this->couvertures()->where('type', $type)->get();
        if ($regles->isEmpty()) {
            return true;
        }
        // Cherche règle spécifique
        if ($referenceId) {
            $specifique = $regles->where('reference_id', $referenceId)->first();
            if ($specifique) {
                return $specifique->couvert;
            }
        }
        // Règle générale pour ce type
        $generale = $regles->whereNull('reference_id')->first();

        return $generale ? $generale->couvert : true;
    }

    public function tauxPourActe(string $type, ?string $referenceId = null): float
    {
        // Un acte exclu du contrat ne se couvre à aucun taux : sans cette
        // porte, une règle d'exclusion retombait sur le taux global et
        // l'assureur se voyait facturer ce qu'il ne prend pas en charge.
        if (! $this->couvreActe($type, $referenceId)) {
            return 0.0;
        }

        $taux = null;

        if ($referenceId) {
            $specifique = $this->couvertures()
                ->where('type', $type)
                ->where('reference_id', $referenceId)
                ->whereNotNull('taux_specifique')
                ->first();
            if ($specifique) {
                $taux = (float) $specifique->taux_specifique;
            }
        }

        if ($taux === null) {
            // Règle générale du type, à défaut le taux global du contrat.
            $general = $this->couvertures()
                ->where('type', $type)
                ->whereNull('reference_id')
                ->whereNotNull('taux_specifique')
                ->first();

            $taux = $general ? (float) $general->taux_specifique : (float) $this->taux_couverture;
        }

        // Le ticket modérateur reste systématiquement à la charge du patient,
        // quel que soit le taux négocié sur l'acte.
        return max(0, $taux - (float) $this->ticket_moderateur);
    }
}
