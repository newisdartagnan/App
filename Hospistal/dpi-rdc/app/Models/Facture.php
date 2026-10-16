<?php

namespace App\Models;

use App\Models\Concerns\Syncable;
use App\Services\DeviseService;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class Facture extends Model
{
    use HasUuids, Syncable;

    protected $fillable = [
        'patient_id', 'visit_id', 'prescription_id', 'avoir_origine_id', 'establishment_id', 'numero_facture',
        'date_facture', 'statut', 'type_prise_en_charge',
        'devise', 'taux_change', 'assurance_nom', 'assurance_numero', 'bon_prise_en_charge_id',
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

    /**
     * Ce qu'une facture émise engage et qui ne bouge plus.
     *
     * Le patient a emporté son exemplaire, l'assureur a peut-être reçu le
     * sien : modifier ces champs ferait mentir l'un des deux papiers. Une
     * erreur se corrige par un avoir, puis, s'il le faut, par une nouvelle
     * facture. Restent libres ce qui suit la vie de la pièce — statut,
     * acomptes imputés, observations, synchronisation.
     */
    public const CHAMPS_SCELLES = [
        'patient_id', 'visit_id', 'prescription_id', 'establishment_id',
        'numero_facture', 'date_facture', 'type_prise_en_charge',
        'devise', 'taux_change', 'assurance_nom', 'assurance_numero', 'bon_prise_en_charge_id',
        'assurance_part', 'patient_part', 'remise', 'total_ht', 'total_ttc',
    ];

    protected static function booted(): void
    {
        static::updating(function (Facture $facture) {
            // La facture se construit dans la transaction qui la crée : ses
            // totaux y reçoivent la part de l'assureur. Un brouillon n'a pas
            // encore été remis à personne.
            if ($facture->wasRecentlyCreated || $facture->getOriginal('statut') === 'brouillon') {
                return;
            }

            $touches = array_values(array_intersect(array_keys($facture->getDirty()), self::CHAMPS_SCELLES));

            // Le lien vers l'avoir dont elle prend la suite se pose une fois.
            if ($facture->isDirty('avoir_origine_id') && $facture->getOriginal('avoir_origine_id') !== null) {
                $touches[] = 'avoir_origine_id';
            }

            if ($touches !== []) {
                throw new LogicException(
                    "La facture {$facture->getOriginal('numero_facture')} est émise : "
                    .implode(', ', $touches).' ne se modifie plus. Établissez un avoir.'
                );
            }

            // Une facture annulée l'est pour de bon : on ne la rouvre pas.
            if ($facture->getOriginal('statut') === 'annulee' && $facture->isDirty('statut')) {
                throw new LogicException("La facture {$facture->getOriginal('numero_facture')} est annulée.");
            }
        });

        static::deleting(function (Facture $facture) {
            if ($facture->getOriginal('statut') !== 'brouillon') {
                throw new LogicException(
                    "La facture {$facture->numero_facture} est émise : elle ne se supprime pas. Établissez un avoir."
                );
            }
        });
    }

    /**
     * La facture accepte-t-elle encore une ligne ? Seulement tant qu'elle
     * n'a rien reçu ni rien perdu : dès qu'un paiement, un acompte ou un
     * avoir s'y rattache, son total est devenu une référence.
     */
    public function accepteNouvellesLignes(): bool
    {
        if ($this->statut === 'brouillon') {
            return true;
        }

        if ($this->statut === 'annulee') {
            return false;
        }

        return ! $this->paiements()->exists()
            && ! $this->imputations()->exists()
            && ! $this->avoirs()->exists();
    }

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

    /** Le bon de prise en charge sur lequel l'assureur a été facturé. */
    public function bonPriseEnCharge(): BelongsTo
    {
        return $this->belongsTo(BonPriseEnCharge::class);
    }

    public function avoirs(): HasMany
    {
        return $this->hasMany(Avoir::class)->orderBy('date_avoir');
    }

    /** L'avoir qui a annulé la facture que celle-ci remplace. */
    public function avoirOrigine(): BelongsTo
    {
        return $this->belongsTo(Avoir::class, 'avoir_origine_id');
    }

    public function estAnnulee(): bool
    {
        return $this->statut === 'annulee';
    }

    /** Total repris par les avoirs, part patient et part assurance confondues. */
    public function montantAvoirs(): float
    {
        return (float) $this->avoirs()->sum('montant_total');
    }

    /** Ce que la facture réclame encore, une fois les avoirs passés. */
    public function totalNet(): float
    {
        return max(0, round((float) $this->total_ttc - $this->montantAvoirs(), 2));
    }

    /** Part du patient après avoirs. */
    public function partPatientNette(): float
    {
        return max(0, round((float) $this->patient_part - (float) $this->avoirs()->sum('montant_patient'), 2));
    }

    /** Part de l'assureur après avoirs : c'est elle qu'on lui réclame. */
    public function partAssuranceNette(): float
    {
        return max(0, round((float) $this->assurance_part - (float) $this->avoirs()->sum('montant_assurance'), 2));
    }

    /**
     * Ce que le patient a versé et que la facture garde : ses paiements et
     * ses acomptes, moins le trop-perçu que les avoirs lui ont rendu en
     * crédit.
     */
    public function montantRetenu(): float
    {
        return (float) $this->acompte_impute
            + $this->montantPaye()
            - (float) $this->avoirs()->sum('credit_patient');
    }

    /**
     * Prise en charge telle qu'elle doit apparaître sur le document remis
     * au patient : pour un assuré c'est le nom de sa société ou de sa
     * mutuelle qui compte, pas le mot « assurance ».
     */
    public function libellePriseEnCharge(): string
    {
        if ($this->type_prise_en_charge === 'assurance') {
            // La pièce recopie le nom à son émission, la fiche le porte
            // aussi — mais c'est le contrat qui fait foi. Quand les deux
            // copies manquent (reprise de données, contrat enregistré
            // directement), il faut aller le lire là plutôt que d'annoncer
            // « Assurance » tout court : c'est cette ligne que le tiers
            // payant recopie sur son bordereau.
            $contrat = $this->patient?->assuranceEnVigueur();

            $nom = $this->assurance_nom
                ?: $this->patient?->assurance_nom
                ?: $contrat?->assurance?->nom;

            if (filled($nom)) {
                $numero = $this->assurance_numero
                    ?: $this->patient?->assurance_numero
                    ?: $contrat?->numero_police;

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
     * Reste dû au guichet : la part patient après avoirs, moins ce que le
     * patient a déjà versé — paiements et acomptes imputés.
     */
    public function soldeRestant(): float
    {
        if ($this->estAnnulee()) {
            return 0.0;
        }

        return max(0, round($this->partPatientNette() - $this->montantRetenu(), 2));
    }

    public function estSoldee(): bool
    {
        return $this->montantRetenu() >= $this->partPatientNette() - 0.005;
    }
}
