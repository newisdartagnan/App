<?php

namespace App\Models;

use App\Services\DeviseService;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Bon de prise en charge : l'accord écrit de l'employeur ou de l'assureur
 * pour un passage, une période, parfois un montant.
 *
 * Beaucoup de conventions ne paient que sur bon. Sans lui, l'assureur
 * rejette la facture — souvent des mois plus tard. Le bon se saisit donc
 * avant la facture, et c'est lui qui ouvre le tiers payant.
 */
class BonPriseEnCharge extends Model
{
    use HasUuids;

    protected $table = 'bons_prise_en_charge';

    protected $fillable = [
        'numero', 'patient_id', 'assurance_id', 'visit_id',
        'date_emission', 'date_expiration', 'montant_autorise', 'devise',
        'montant_consomme', 'objet', 'statut', 'saisi_par', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'date_emission' => 'date',
            'date_expiration' => 'date',
            'montant_autorise' => 'decimal:2',
            'montant_consomme' => 'decimal:2',
        ];
    }

    public const STATUTS = [
        'valide' => 'Valide',
        'annule' => 'Annulé',
    ];

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function assurance(): BelongsTo
    {
        return $this->belongsTo(Assurance::class);
    }

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'saisi_par');
    }

    public function factures(): HasMany
    {
        return $this->hasMany(Facture::class);
    }

    /** Bons qui couvrent cette date, du plus récent au plus ancien. */
    public function scopeValidesLe(Builder $query, CarbonInterface $date): Builder
    {
        return $query->where('statut', 'valide')
            ->whereDate('date_emission', '<=', $date)
            ->whereDate('date_expiration', '>=', $date)
            ->orderByDesc('date_emission');
    }

    /** Ce qui reste du montant accordé ; null quand le bon n'en fixe pas. */
    public function resteAutorise(): ?float
    {
        if ($this->montant_autorise === null) {
            return null;
        }

        return max(0, round((float) $this->montant_autorise - (float) $this->montant_consomme, 2));
    }

    public function estUtilisableLe(CarbonInterface $date): bool
    {
        return $this->statut === 'valide'
            && $this->date_emission->lte($date)
            && $this->date_expiration->gte($date->copy()->startOfDay())
            && ($this->resteAutorise() === null || $this->resteAutorise() > 0);
    }

    /** « Valable » / « Expiré » / « Épuisé » / « Annulé », à la date du jour. */
    public function etat(): string
    {
        return match (true) {
            $this->statut === 'annule' => 'Annulé',
            $this->date_expiration->lt(now()->startOfDay()) => 'Expiré',
            $this->date_emission->gt(now()) => 'Pas encore valable',
            $this->resteAutorise() !== null && $this->resteAutorise() <= 0 => 'Épuisé',
            default => 'Valable',
        };
    }

    public function formater(float $montant): string
    {
        return app(DeviseService::class)->formater($montant, $this->devise ?: 'CDF');
    }
}
