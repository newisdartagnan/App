<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Générateur d'hémodialyse. C'est le poste qui limite l'activité de l'unité :
 * deux patients ne peuvent y être branchés au même moment.
 */
class GenerateurDialyse extends Model
{
    use HasUuids;

    protected $table = 'generateurs_dialyse';

    protected $fillable = ['establishment_id', 'code', 'nom', 'marque', 'reserve_hbs', 'est_actif', 'notes'];

    protected function casts(): array
    {
        return ['reserve_hbs' => 'boolean', 'est_actif' => 'boolean'];
    }

    /** Dotation de départ d'une unité de dialyse. */
    public const CATALOGUE = [
        ['GEN-1', 'Générateur 1', false],
        ['GEN-2', 'Générateur 2', false],
        ['GEN-3', 'Générateur 3', false],
        ['GEN-4', 'Générateur 4', false],
        // Un poste dédié aux porteurs de l'antigène HBs : règle d'hygiène.
        ['GEN-HBS', 'Générateur 5 — secteur AgHBs', true],
    ];

    public static function installerPour(Establishment $etablissement): void
    {
        foreach (self::CATALOGUE as [$code, $nom, $hbs]) {
            self::firstOrCreate(
                ['establishment_id' => $etablissement->id, 'code' => $code],
                ['nom' => $nom, 'reserve_hbs' => $hbs, 'est_actif' => true]
            );
        }
    }

    public function establishment(): BelongsTo
    {
        return $this->belongsTo(Establishment::class);
    }

    public function seances(): HasMany
    {
        return $this->hasMany(SeanceDialyse::class, 'generateur_id');
    }

    /**
     * Séances occupant ce générateur sur un créneau, en excluant
     * éventuellement celle qu'on est en train de déplacer.
     */
    public function occupationEntre(Carbon $debut, Carbon $fin, ?string $sauf = null)
    {
        return $this->seances()
            ->whereIn('statut', ['planifiee', 'realisee'])
            ->when($sauf, fn ($q) => $q->whereKeyNot($sauf))
            ->get()
            ->filter(function (SeanceDialyse $seance) use ($debut, $fin) {
                $depart = $seance->date_seance;
                $arrivee = $seance->finPrevue();

                return $depart->lt($fin) && $arrivee->gt($debut);
            });
    }
}
