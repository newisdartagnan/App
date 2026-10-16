<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Facture adressée à une société ou une assurance : elle regroupe, sur une
 * période, la part prise en charge de toutes les factures patients couvertes.
 */
class FactureConvention extends Model
{
    use HasUuids;

    protected $table = 'factures_convention';

    protected $fillable = [
        'numero', 'assurance_id', 'emise_par', 'periode_debut', 'periode_fin',
        'mode', 'devise', 'taux_change', 'montant_total', 'montant_regle',
        'statut', 'date_reglement', 'observation', 'date_depot', 'accuse_reception',
    ];

    protected function casts(): array
    {
        return [
            'periode_debut' => 'date',
            'periode_fin' => 'date',
            'montant_total' => 'decimal:2',
            'montant_regle' => 'decimal:2',
            'taux_change' => 'decimal:4',
            'date_reglement' => 'datetime',
            'date_depot' => 'date',
        ];
    }

    /** Devises acceptées pour une convention (ambassades, organismes). */
    public const DEVISES = ['CDF' => 'Franc congolais', 'USD' => 'Dollar américain', 'EUR' => 'Euro'];

    public function assurance(): BelongsTo
    {
        return $this->belongsTo(Assurance::class);
    }

    public function emisePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'emise_par');
    }

    public function lignes(): HasMany
    {
        return $this->hasMany(LigneFactureConvention::class, 'facture_convention_id');
    }

    public function reglements(): HasMany
    {
        return $this->hasMany(ReglementConvention::class, 'facture_convention_id');
    }

    /** Avoirs passés sur des factures que ce bordereau réclamait. */
    public function avoirs(): HasMany
    {
        return $this->hasMany(Avoir::class, 'facture_convention_id');
    }

    /**
     * Part assureur reprise par ces avoirs, dans la devise du bordereau.
     * Les avoirs sont en francs comme les factures qu'ils corrigent ; le
     * taux du bordereau les convertit, comme il a converti son total.
     */
    public function montantAvoirs(): float
    {
        $cdf = (float) $this->avoirs()->sum('montant_assurance');

        return round($cdf / max((float) $this->taux_change, 0.0001), 2);
    }

    public function rejets(): HasMany
    {
        return $this->hasMany(RejetConvention::class, 'facture_convention_id')->orderBy('date_rejet');
    }

    public function relances(): HasMany
    {
        return $this->hasMany(RelanceConvention::class, 'facture_convention_id');
    }

    /** Ce que l'assureur refuse — rejets à traiter, contestés ou abandonnés. */
    public function montantRejete(): float
    {
        return round((float) $this->rejets()->whereIn('statut', RejetConvention::DEDUITS)->sum('montant'), 2);
    }

    /**
     * Ce que l'assureur doit vraiment : le bordereau, moins les avoirs et
     * moins ce qu'il a rejeté. Un rejet levé y revient.
     */
    public function montantNet(): float
    {
        return max(0, round((float) $this->montant_total - $this->montantAvoirs() - $this->montantRejete(), 2));
    }

    /**
     * Date limite de paiement : le délai du contrat court depuis le dépôt
     * chez l'assureur, à défaut depuis l'émission.
     */
    public function echeance(): Carbon
    {
        $depart = $this->date_depot ?? $this->created_at ?? now();

        return Carbon::parse($depart)->startOfDay()
            ->addDays((int) ($this->assurance?->delai_reglement_jours ?? 30));
    }

    public function resteDu(): float
    {
        return max(0, round($this->montantNet() - (float) $this->montant_regle, 2));
    }

    public function estSoldee(): bool
    {
        return $this->resteDu() <= 0.01;
    }

    public static function genererNumero(): string
    {
        $prefix = 'FCV-'.now()->format('Y').'-';
        $last = static::where('numero', 'like', $prefix.'%')
            ->orderByDesc('numero')->value('numero');

        return $prefix.str_pad((string) ($last ? (int) substr($last, -6) + 1 : 1), 6, '0', STR_PAD_LEFT);
    }
}
