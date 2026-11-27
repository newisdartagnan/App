<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Fiche d'un acte du catalogue : prix normal, urgent et externe, fonction
 * tarifaire (colonne de coefficient des catégories de convention),
 * disponibilité et catégorie.
 */
class ActeCatalogue extends Model
{
    use HasUuids;

    protected $table = 'actes_catalogue';

    protected $fillable = [
        'numero', 'libelle', 'fonction_tarifaire', 'prix_normal', 'prix_urgent', 'prix_externe',
        'devise', 'specialite', 'disponibilite', 'categorie_acte', 'domaine', 'duree_minutes', 'observation',
    ];

    protected function casts(): array
    {
        return ['prix_normal' => 'decimal:2', 'prix_urgent' => 'decimal:2', 'prix_externe' => 'decimal:2'];
    }

    public const DISPONIBILITES = [
        'oui' => 'Oui',
        'non_temporaire' => 'Non (temporaire)',
        'non_definitif' => 'Non (définitif)',
    ];

    public const CATEGORIES = [
        'Intervention chirurgicale majeure',
        'Intervention chirurgicale moyenne',
        'Intervention chirurgicale mineure',
        'Acte obstétrical',
        'Acte de laboratoire',
        'Acte d\'imagerie',
        'Acte de consultation',
        'Soins infirmiers',
        'Hospitalisation',
        'Autre acte',
    ];

    public const DOMAINES = [
        'chirurgie' => 'Bloc opératoire',
        'maternite' => 'Maternité',
        'examen_specialise' => 'Examen spécialisé',
        'dialyse' => 'Dialyse',
    ];

    public function estDisponible(): bool
    {
        return $this->disponibilite === 'oui';
    }

    /**
     * Prix de base selon le contexte : urgent pour un patient des urgences,
     * externe pour un patient venu de l'extérieur, normal sinon. Le prix
     * absent retombe sur le prix normal.
     */
    public function prixDeBase(string $contexte = 'normal'): float
    {
        $prix = match ($contexte) {
            'urgent' => $this->prix_urgent,
            'externe' => $this->prix_externe,
            default => null,
        };

        return (float) ($prix ?? $this->prix_normal);
    }

    /** Le prix de base dans la catégorie de convention donnée. */
    public function prixPourCategorie(?CategorieTarifaire $categorie, string $contexte = 'normal'): float
    {
        return round($this->prixDeBase($contexte) * ($categorie?->coefficient($this->fonction_tarifaire) ?? 1.0), 2);
    }
}
