<?php

namespace App\Models;

use App\Models\Concerns\Syncable;
use App\Services\DeviseService;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Facture extends Model
{
    use HasUuids, Syncable;

    protected $fillable = [
        'patient_id', 'visit_id', 'prescription_id', 'establishment_id', 'numero_facture',
        'date_facture', 'statut', 'type_prise_en_charge',
        'devise', 'taux_change', 'assurance_nom', 'assurance_numero',
        'assurance_part', 'patient_part', 'remise', 'acompte_impute',
        'total_ht', 'total_ttc', 'observations', 'sync_status',
    ];

    protected function casts(): array
    {
        return ['date_facture' => 'datetime', 'taux_change' => 'decimal:4'];
    }

    /** Libellés lisibles des modes de prise en charge. */
    public const PRISES_EN_CHARGE = [
        'prive' => 'Privé',
        'assurance' => 'Assurance',
        'indigent' => 'Indigent',
        'fonctionnaire' => 'Fonctionnaire',
        'autre' => 'Autre',
    ];

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }

    public function prescription(): BelongsTo
    {
        return $this->belongsTo(Prescription::class);
    }

    public function lignes(): HasMany
    {
        return $this->hasMany(LigneFacture::class);
    }

    public function paiements(): HasMany
    {
        return $this->hasMany(Paiement::class);
    }

    public function bonsSortie(): HasMany
    {
        return $this->hasMany(BonSortie::class);
    }

    public function lignesTiersPayant(): HasMany
    {
        return $this->hasMany(FactureTiersPayant::class);
    }

    public function imputations(): HasMany
    {
        return $this->hasMany(ImputationAcompte::class);
    }

    /**
     * Prise en charge telle qu'elle doit apparaître sur le document remis
     * au patient : pour un assuré c'est le nom de sa société ou de sa
     * mutuelle qui compte, pas le mot « assurance ».
     */
    public function libellePriseEnCharge(): string
    {
        if ($this->type_prise_en_charge === 'assurance') {
            $nom = $this->assurance_nom
                ?: $this->patient?->assurance_nom;

            if (filled($nom)) {
                $numero = $this->assurance_numero ?: $this->patient?->assurance_numero;

                return filled($numero) ? $nom.' — n° '.$numero : $nom;
            }
        }

        return self::PRISES_EN_CHARGE[$this->type_prise_en_charge]
            ?? ucfirst((string) $this->type_prise_en_charge);
    }

    /** Devise de la facture, francs congolais par défaut. */
    public function deviseFacture(): string
    {
        return $this->devise ?: config('dpi.devise_pivot', 'CDF');
    }

    public function tauxApplique(): float
    {
        return (float) ($this->taux_change ?: 1);
    }

    /** Montant formaté dans la devise de la facture. */
    public function formater(float $montant): string
    {
        return app(DeviseService::class)->formater($montant, $this->deviseFacture());
    }

    /**
     * Total encaissé, ramené dans la devise de la facture : le patient peut
     * avoir réglé une partie en dollars et le reste en francs.
     */
    public function montantPaye(): float
    {
        $devises = app(DeviseService::class);

        $cdf = (float) $this->paiements()->sum('montant_cdf');

        return $devises->depuisCdf($cdf, $this->deviseFacture(), $this->tauxApplique());
    }

    /**
     * Reste dû au guichet : la part patient, moins les acomptes déjà
     * imputés, moins ce qui a été encaissé.
     */
    public function soldeRestant(): float
    {
        return max(0, $this->patient_part - (float) $this->acompte_impute - $this->montantPaye());
    }

    public function estSoldee(): bool
    {
        return $this->montantPaye() + (float) $this->acompte_impute >= $this->patient_part;
    }
}
