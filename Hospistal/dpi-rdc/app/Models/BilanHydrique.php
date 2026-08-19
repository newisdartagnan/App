<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Balance entrées / sorties d'un patient hospitalisé, par tranche horaire.
 * Surveillance essentielle en réanimation et en post-opératoire.
 */
class BilanHydrique extends Model
{
    use HasUuids;

    protected $table = 'bilans_hydriques';

    protected $fillable = [
        'visit_id', 'user_id', 'jour', 'tranche',
        'perfusion', 'apport_iv', 'transfusion', 'per_os', 'autres_entrees',
        'urines', 'vomissements', 'drains', 'selles', 'autres_sorties',
        'observation',
    ];

    protected function casts(): array
    {
        return ['jour' => 'date'];
    }

    public const TRANCHES = [
        'matin' => 'Matin (6 h – 14 h)',
        'apres_midi' => 'Après-midi (14 h – 22 h)',
        'nuit' => 'Nuit (22 h – 6 h)',
    ];

    public const ENTREES = [
        'perfusion' => 'Perfusion',
        'apport_iv' => 'Apport thérapeutique IV',
        'transfusion' => 'Transfusion',
        'per_os' => 'Per os / SNG',
        'autres_entrees' => 'Autres entrées',
    ];

    public const SORTIES = [
        'urines' => 'Urines',
        'vomissements' => 'Vomissements',
        'drains' => 'Drains / aspiration',
        'selles' => 'Selles',
        'autres_sorties' => 'Autres sorties',
    ];

    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function totalEntrees(): int
    {
        return (int) collect(array_keys(self::ENTREES))->sum(fn ($c) => $this->{$c});
    }

    public function totalSorties(): int
    {
        return (int) collect(array_keys(self::SORTIES))->sum(fn ($c) => $this->{$c});
    }

    /** Positive : le patient retient ; négative : il perd. */
    public function balance(): int
    {
        return $this->totalEntrees() - $this->totalSorties();
    }

    public function libelleTranche(): string
    {
        return self::TRANCHES[$this->tranche] ?? $this->tranche;
    }
}
