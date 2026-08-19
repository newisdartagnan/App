<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Service hôtelier de la chambre d'un patient hospitalisé : ce qui a été
 * fait, quel jour, et ce qui reste à faire.
 */
class TacheMenage extends Model
{
    use HasUuids;

    protected $table = 'taches_menage';

    protected $fillable = [
        'visit_id', 'user_id', 'jour', 'type', 'statut', 'observation',
    ];

    protected function casts(): array
    {
        return ['jour' => 'date'];
    }

    public const TYPES = [
        'nettoyage' => 'Nettoyage de la chambre',
        'change_literie' => 'Change de la literie',
        'desinfection' => 'Désinfection',
        'evacuation_dechets' => 'Évacuation des déchets',
        'sanitaires' => 'Entretien des sanitaires',
    ];

    public const STATUTS = [
        'fait' => 'Fait',
        'refuse' => 'Refusé par le patient',
        'impossible' => 'Impossible (soins en cours)',
    ];

    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function libelleType(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }

    public function libelleStatut(): string
    {
        return self::STATUTS[$this->statut] ?? $this->statut;
    }
}
