<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LignePrescription extends Model
{
    use HasUuids;

    protected $table = 'lignes_prescription';

    protected $fillable = [
        'prescription_id', 'medicament_id', 'dose', 'frequence',
        'duree_jours', 'voie_administration', 'instructions',
        'quantite_totale', 'quantite_dispensee', 'est_substituable',
        'est_externe', 'libelle_externe', 'quantite_facturee', 'conditionnements',
    ];

    protected function casts(): array
    {
        return [
            'est_substituable' => 'boolean',
            'est_externe' => 'boolean',
            'quantite_totale' => 'decimal:2',
            'quantite_dispensee' => 'decimal:2',
            'quantite_facturee' => 'decimal:2',
            'conditionnements' => 'integer',
        ];
    }

    public function prescription(): BelongsTo
    {
        return $this->belongsTo(Prescription::class);
    }

    public function medicament(): BelongsTo
    {
        return $this->belongsTo(Medicament::class);
    }

    public function dispensations(): HasMany
    {
        return $this->hasMany(Dispensation::class, 'ligne_prescription_id');
    }

    /**
     * Ce qu'il reste à sortir du tiroir : la quantité conditionnée moins ce
     * qui a déjà été servi.
     */
    public function quantiteRestante(): float
    {
        return max(0, $this->quantiteADelivrer() - (float) $this->quantite_dispensee);
    }

    /**
     * Nom du produit prescrit, qu'il vienne du dépôt ou de l'extérieur.
     */
    public function designation(): string
    {
        return $this->est_externe
            ? (string) $this->libelle_externe
            : ($this->medicament?->designation() ?? '—');
    }

    /**
     * Posologie en une phrase : « 1 comprimé, 3 fois par jour, 5 jours ».
     */
    public function posologie(): string
    {
        $morceaux = array_filter([
            $this->dose,
            $this->frequence,
            $this->duree_jours ? $this->duree_jours.' jour'.($this->duree_jours > 1 ? 's' : '') : null,
        ]);

        return implode(', ', $morceaux);
    }

    /**
     * Ce qui sera réellement délivré : la quantité prescrite majorée au
     * conditionnement entier. Une ligne externe ne se délivre pas ici.
     */
    public function quantiteADelivrer(): float
    {
        if ($this->est_externe) {
            return 0.0;
        }

        return (float) ($this->quantite_facturee ?: $this->quantite_totale);
    }

    /**
     * La délivrance a-t-elle été majorée par le conditionnement ?
     * Quinze comprimés se servent en deux plaquettes de dix, soit vingt.
     */
    public function estMajoree(): bool
    {
        return ! $this->est_externe
            && (float) $this->quantite_facturee > (float) $this->quantite_totale;
    }

    /** Conditionnement délivré en toutes lettres : « 2 plaquettes de 10 comprimés ». */
    public function libelleConditionnement(): ?string
    {
        if ($this->est_externe || ! $this->medicament || ! $this->conditionnements) {
            return null;
        }

        $contenant = Medicament::CONDITIONNEMENTS[$this->medicament->conditionnement]
            ?? $this->medicament->conditionnement;

        if (! $this->medicament->seDelivreParConditionnement()) {
            return $this->conditionnements.' '.$contenant.($this->conditionnements > 1 ? 's' : '');
        }

        return $this->conditionnements.' '.$contenant.($this->conditionnements > 1 ? 's' : '')
            .' de '.$this->medicament->unites_par_conditionnement
            .' '.$this->medicament->unite($this->medicament->unites_par_conditionnement);
    }
}
