<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Plage de présence hebdomadaire d'un médecin. Sert à savoir, à l'accueil,
 * quelles spécialités sont réellement assurées aujourd'hui avant d'envoyer
 * un patient à la caisse pour une consultation que personne ne donnera.
 */
class DisponibiliteMedecin extends Model
{
    use HasUuids;

    protected $table = 'disponibilites_medecin';

    protected $fillable = [
        'user_id', 'jour_semaine', 'heure_debut', 'heure_fin', 'lieu', 'is_active',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public const JOURS = [
        1 => 'Lundi', 2 => 'Mardi', 3 => 'Mercredi', 4 => 'Jeudi',
        5 => 'Vendredi', 6 => 'Samedi', 7 => 'Dimanche',
    ];

    public function medecin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function libelleJour(): string
    {
        return self::JOURS[$this->jour_semaine] ?? (string) $this->jour_semaine;
    }

    public function plage(): string
    {
        return substr((string) $this->heure_debut, 0, 5).' – '.substr((string) $this->heure_fin, 0, 5);
    }

    /** La plage couvre-t-elle cette heure ? */
    public function couvre(string $heure): bool
    {
        return $heure >= substr((string) $this->heure_debut, 0, 5)
            && $heure < substr((string) $this->heure_fin, 0, 5);
    }
}
