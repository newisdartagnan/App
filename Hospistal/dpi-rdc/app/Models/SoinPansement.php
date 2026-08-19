<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Soin de plaie du dossier infirmier. Chaque réfection note l'état constaté
 * et programme la suivante, pour qu'aucun pansement ne soit oublié.
 */
class SoinPansement extends Model
{
    use HasUuids;

    protected $table = 'soins_pansement';

    protected $fillable = [
        'visit_id', 'user_id', 'realise_a', 'localisation',
        'etat_plaie', 'protocole', 'date_refaire', 'observation',
    ];

    protected function casts(): array
    {
        return ['realise_a' => 'datetime', 'date_refaire' => 'date'];
    }

    /** État de la plaie, du plus favorable au plus préoccupant. */
    public const ETATS = [
        'cicatrisee' => 'Cicatrisée',
        'propre' => 'Propre',
        'bourgeonnante' => 'Bourgeonnante',
        'fibrineuse' => 'Fibrineuse',
        'necrotique' => 'Nécrotique',
        'infectee' => 'Infectée',
    ];

    /** États qui justifient d'alerter le médecin. */
    public const ETATS_PREOCCUPANTS = ['necrotique', 'infectee'];

    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function libelleEtat(): string
    {
        return self::ETATS[$this->etat_plaie] ?? $this->etat_plaie;
    }

    public function estPreoccupant(): bool
    {
        return in_array($this->etat_plaie, self::ETATS_PREOCCUPANTS, true);
    }

    /** Le prochain soin est-il dû (aujourd'hui ou en retard) ? */
    public function refectionDue(): bool
    {
        return $this->date_refaire !== null
            && $this->date_refaire->startOfDay()->lessThanOrEqualTo(now()->startOfDay());
    }

    /** Nombre de jours de retard sur la réfection programmée, 0 si à jour. */
    public function joursRetard(): int
    {
        if ($this->date_refaire === null) {
            return 0;
        }

        return max(0, $this->date_refaire->startOfDay()->diffInDays(now()->startOfDay(), false));
    }
}
