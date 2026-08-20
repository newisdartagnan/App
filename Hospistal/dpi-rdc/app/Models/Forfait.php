<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Prix tout compris couvrant un ensemble de prestations.
 *
 * Trois régimes cohabitent dans l'établissement :
 *  - le forfait global, qui couvre tout le séjour d'un seul montant ;
 *  - le forfait partiel, qui ne couvre que certaines catégories, le reste
 *    étant facturé à l'acte ;
 *  - la convention, où c'est la société ou la mutuelle qui prend en charge
 *    selon son taux (règles portées par le modèle Assurance).
 */
class Forfait extends Model
{
    use HasUuids;

    protected $table = 'forfaits';

    protected $fillable = [
        'establishment_id', 'code', 'libelle', 'description', 'portee',
        'montant', 'devise', 'categories_couvertes', 'jours_inclus',
        'assurance_id', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'montant' => 'decimal:2',
            'categories_couvertes' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public const PORTEES = [
        'global' => 'Global — couvre tout le séjour',
        'partiel' => 'Partiel — couvre les catégories cochées',
    ];

    /** Catégories de lignes de facture qu'un forfait peut couvrir. */
    public const CATEGORIES = [
        'consultation' => 'Consultations',
        'hospitalisation' => 'Journées d\'hospitalisation',
        'diete' => 'Diète',
        'examen_labo' => 'Examens de laboratoire',
        'imagerie' => 'Imagerie',
        'medicament' => 'Médicaments',
        'acte_chirurgical' => 'Actes chirurgicaux',
        'dialyse' => 'Séances de dialyse',
        'autre' => 'Autres actes',
    ];

    public function establishment(): BelongsTo
    {
        return $this->belongsTo(Establishment::class);
    }

    public function assurance(): BelongsTo
    {
        return $this->belongsTo(Assurance::class);
    }

    public function estGlobal(): bool
    {
        return $this->portee === 'global';
    }

    /**
     * Ce forfait prend-il en charge une ligne de cette catégorie ?
     * Un forfait global couvre tout, par définition.
     */
    public function couvre(string $categorie): bool
    {
        return $this->estGlobal()
            || in_array($categorie, $this->categories_couvertes ?? [], true);
    }

    /** @return array<int, string> Libellés des catégories couvertes. */
    public function libellesCouverts(): array
    {
        if ($this->estGlobal()) {
            return ['Toutes les prestations du séjour'];
        }

        return collect($this->categories_couvertes ?? [])
            ->map(fn ($c) => self::CATEGORIES[$c] ?? $c)
            ->all();
    }

    public function libellePortee(): string
    {
        return self::PORTEES[$this->portee] ?? $this->portee;
    }

    /**
     * Le forfait couvre-t-il encore ce séjour, ou la durée incluse est-elle
     * dépassée ? Au-delà, le séjour redevient facturé à l'acte.
     */
    public function couvreEncore(Visit $visit): bool
    {
        if ($this->jours_inclus === null) {
            return true;
        }

        return $visit->joursHospitalisation() <= $this->jours_inclus;
    }
}
