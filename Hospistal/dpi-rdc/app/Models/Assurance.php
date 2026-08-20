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
    ];

    protected function casts(): array
    {
        return [
            'est_actif' => 'boolean',
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
