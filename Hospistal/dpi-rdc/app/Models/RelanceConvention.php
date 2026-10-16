<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Une relance faite à l'assureur ou à l'employeur sur un bordereau impayé. */
class RelanceConvention extends Model
{
    use HasUuids;

    protected $table = 'relances_convention';

    protected $fillable = [
        'facture_convention_id', 'date_relance', 'mode', 'commentaire', 'prochaine_action', 'user_id',
    ];

    protected function casts(): array
    {
        return ['date_relance' => 'date', 'prochaine_action' => 'date'];
    }

    /** Relances graduées, de la plus douce à la plus ferme. */
    public const MODES = [
        'telephone' => 'Appel téléphonique',
        'courriel' => 'Courriel',
        'courrier' => 'Courrier',
        'visite' => 'Visite ou réunion',
        'mise_en_demeure' => 'Mise en demeure',
    ];

    public function factureConvention(): BelongsTo
    {
        return $this->belongsTo(FactureConvention::class);
    }

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function libelleMode(): string
    {
        return self::MODES[$this->mode] ?? $this->mode;
    }
}
