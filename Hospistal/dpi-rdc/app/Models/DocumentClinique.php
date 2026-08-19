<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Document clinique produit pour un patient : certificat, rapport, courrier.
 * Cycle de vie propre (rédaction → validation → impression), distinct du
 * catalogue des actes techniques.
 */
class DocumentClinique extends Model
{
    use HasUuids;

    protected $table = 'documents_cliniques';

    protected $fillable = [
        'patient_id', 'visit_id', 'auteur_id', 'type', 'titre', 'contenu', 'statut', 'valide_at',
    ];

    protected function casts(): array
    {
        return ['valide_at' => 'datetime'];
    }

    public const TYPES = [
        'certificat_medical' => 'Certificat médical',
        'certificat_aptitude' => "Certificat d'aptitude physique",
        'rapport_medical' => 'Rapport médical',
        'courrier' => 'Courrier / lettre de liaison',
        'protocole_soins' => 'Protocole de soins',
    ];

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'auteur_id');
    }

    public function libelleType(): string
    {
        return self::TYPES[$this->type] ?? ucfirst(str_replace('_', ' ', $this->type));
    }
}
