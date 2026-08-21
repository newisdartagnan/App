<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActeClinique extends Model
{
    use HasUuids;

    protected $table = 'actes_cliniques';

    protected $attributes = [
        'quantite' => 1,
    ];

    protected $fillable = [
        'visit_id', 'patient_id', 'prescripteur_id', 'operateur_id', 'domaine', 'libelle',
        'prix', 'quantite', 'statut', 'compte_rendu', 'date_realisation', 'facture_id',
        'date_prevue', 'duree_minutes', 'consentement', 'urgence', 'indication',
        'salle_id', 'anesthesiste_id', 'demandeur_id', 'type_anesthesie',
        'diagnostic_preop', 'diagnostic_postop', 'instrumentiste', 'kits', 'incidents',
        'heure_entree_salle', 'heure_sortie_salle',
    ];

    protected function casts(): array
    {
        return [
            'prix' => 'decimal:2',
            'quantite' => 'decimal:2',
            'date_realisation' => 'datetime',
            'date_prevue' => 'datetime',
            'consentement' => 'boolean',
            'urgence' => 'boolean',
            'kits' => 'array',
            'heure_entree_salle' => 'datetime',
            'heure_sortie_salle' => 'datetime',
        ];
    }

    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }

    /** Chirurgien / opérateur qui réalise l'acte. */
    public function operateur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'operateur_id');
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function prescripteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prescripteur_id');
    }

    public function facture(): BelongsTo
    {
        return $this->belongsTo(Facture::class);
    }

    public function montantTotal(): float
    {
        return (float) ($this->prix * ($this->quantite ?? 1));
    }

    // ═══════════════════════════════════════════════════════════
    // Bloc opératoire
    // ═══════════════════════════════════════════════════════════

    /** Types d'anesthésie tenus au registre du bloc. */
    public const ANESTHESIES = [
        'generale' => 'Anesthésie générale',
        'rachianesthesie' => 'Rachianesthésie',
        'peridurale' => 'Péridurale',
        'locoregionale' => 'Anesthésie locorégionale',
        'locale' => 'Anesthésie locale',
        'sedation' => 'Sédation',
        'aucune' => 'Sans anesthésie',
    ];

    public function salle(): BelongsTo
    {
        return $this->belongsTo(SalleOperation::class, 'salle_id');
    }

    public function anesthesiste(): BelongsTo
    {
        return $this->belongsTo(User::class, 'anesthesiste_id');
    }

    /** Médecin qui a demandé l'intervention, s'il n'opère pas lui-même. */
    public function demandeur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'demandeur_id');
    }

    public function libelleAnesthesie(): string
    {
        return self::ANESTHESIES[$this->type_anesthesie] ?? '—';
    }

    /** Fin prévue du créneau, d'après la durée annoncée. */
    public function finPrevue(): ?Carbon
    {
        return $this->date_prevue?->copy()->addMinutes($this->duree_minutes ?: 60);
    }

    /** Temps réellement passé en salle, une fois l'intervention clôturée. */
    public function dureeReelleMinutes(): ?int
    {
        if (! $this->heure_entree_salle || ! $this->heure_sortie_salle) {
            return null;
        }

        return (int) $this->heure_entree_salle->diffInMinutes($this->heure_sortie_salle);
    }

    /** L'intervention attend-elle encore d'être programmée ? */
    public function estEnAttenteDePlanification(): bool
    {
        return $this->statut === 'prescrit';
    }

    public function estPlanifiee(): bool
    {
        return $this->statut === 'planifie' && $this->salle_id && $this->date_prevue;
    }

    /** Kits ouverts, en toutes lettres. */
    public function libelleKits(): string
    {
        $kits = KitOperatoire::whereIn('id', $this->kits ?? [])->pluck('libelle');

        return $kits->isEmpty() ? '—' : $kits->implode(' · ');
    }

    /** Coût des kits ouverts, qui s'ajoute au prix de l'acte. */
    public function coutKits(): float
    {
        return (float) KitOperatoire::whereIn('id', $this->kits ?? [])->sum('prix');
    }
}
