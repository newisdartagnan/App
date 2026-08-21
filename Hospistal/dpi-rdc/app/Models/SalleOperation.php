<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Salle d'opération. L'horaire du bloc se lit salle par salle : deux
 * interventions ne peuvent y être programmées au même moment.
 */
class SalleOperation extends Model
{
    use HasUuids;

    protected $table = 'salles_operation';

    protected $fillable = ['establishment_id', 'code', 'nom', 'specialite', 'equipement', 'est_actif'];

    protected function casts(): array
    {
        return ['est_actif' => 'boolean'];
    }

    /** Dotation de départ d'un bloc. */
    public const CATALOGUE = [
        ['SOP-1', 'Salle 1', 'Chirurgie générale'],
        ['SOP-2', 'Salle 2', 'Chirurgie obstétricale'],
        ['SOP-3', 'Salle 3', 'Urgences / septique'],
    ];

    public static function installerPour(Establishment $etablissement): void
    {
        foreach (self::CATALOGUE as [$code, $nom, $specialite]) {
            self::firstOrCreate(
                ['establishment_id' => $etablissement->id, 'code' => $code],
                ['nom' => $nom, 'specialite' => $specialite, 'est_actif' => true]
            );
        }
    }

    public function establishment(): BelongsTo
    {
        return $this->belongsTo(Establishment::class);
    }

    public function interventions(): HasMany
    {
        return $this->hasMany(ActeClinique::class, 'salle_id');
    }

    /**
     * Interventions programmées dans cette salle sur un créneau donné,
     * en excluant éventuellement celle qu'on est en train de déplacer.
     */
    public function occupationEntre(Carbon $debut, Carbon $fin, ?string $sauf = null)
    {
        return $this->interventions()
            ->whereIn('statut', ['planifie', 'realise'])
            ->when($sauf, fn ($q) => $q->whereKeyNot($sauf))
            ->whereNotNull('date_prevue')
            ->get()
            ->filter(function (ActeClinique $acte) use ($debut, $fin) {
                $depart = $acte->date_prevue;
                $arrivee = $depart->copy()->addMinutes($acte->duree_minutes ?: 60);

                // Deux créneaux se chevauchent dès que l'un commence avant
                // que l'autre ne finisse, dans les deux sens.
                return $depart->lt($fin) && $arrivee->gt($debut);
            });
    }
}
