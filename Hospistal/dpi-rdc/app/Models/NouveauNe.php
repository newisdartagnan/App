<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Le nouveau-né inscrit à l'accouchement. Vivant, il reçoit son propre
 * dossier de patient ; mort-né, il reste déclaré ici — la maternité doit
 * pouvoir le compter.
 */
class NouveauNe extends Model
{
    use HasUuids;

    protected $table = 'nouveau_nes';

    protected $fillable = [
        'accouchement_id', 'patient_id', 'rang', 'sexe', 'poids_g', 'taille_cm',
        'perimetre_cranien_cm', 'apgar_1', 'apgar_5', 'apgar_10',
        'statut', 'reanimation', 'mise_au_sein_precoce', 'malformations', 'observations',
    ];

    protected function casts(): array
    {
        return [
            'taille_cm' => 'decimal:1',
            'perimetre_cranien_cm' => 'decimal:1',
            'reanimation' => 'boolean',
            'mise_au_sein_precoce' => 'boolean',
        ];
    }

    public const STATUTS = [
        'vivant' => 'Vivant',
        'mort_ne' => 'Mort-né',
        'decede' => 'Décédé après la naissance',
    ];

    /** Seuil du petit poids de naissance retenu par l'OMS. */
    public const SEUIL_PETIT_POIDS_G = 2500;

    public function accouchement(): BelongsTo
    {
        return $this->belongsTo(Accouchement::class);
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function estVivant(): bool
    {
        return $this->statut === 'vivant';
    }

    public function libelleStatut(): string
    {
        return self::STATUTS[$this->statut] ?? $this->statut;
    }

    public function libelleSexe(): string
    {
        return match ($this->sexe) {
            'F' => 'Fille',
            'M' => 'Garçon',
            default => '—',
        };
    }

    public function estPetitPoids(): bool
    {
        return $this->poids_g !== null && $this->poids_g < self::SEUIL_PETIT_POIDS_G;
    }

    /**
     * Un Apgar inférieur à 7 à cinq minutes signe une souffrance néonatale :
     * l'enfant doit être surveillé, souvent en néonatologie.
     */
    public function souffranceNeonatale(): bool
    {
        return $this->apgar_5 !== null && $this->apgar_5 < 7;
    }

    public function apgar(): string
    {
        $scores = array_filter([
            $this->apgar_1 !== null ? $this->apgar_1.'/10 à 1 min' : null,
            $this->apgar_5 !== null ? $this->apgar_5.'/10 à 5 min' : null,
            $this->apgar_10 !== null ? $this->apgar_10.'/10 à 10 min' : null,
        ]);

        return $scores === [] ? '—' : implode(' · ', $scores);
    }
}
