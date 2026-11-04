<?php

namespace App\Models;

use App\Enums\MotifRemboursement;
use App\Enums\StatutRemboursement;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** PAY-07. Créé par RemboursementService::demander() seulement. */
class Remboursement extends Model
{
    use HasUuids;

    protected $table = 'remboursements';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'statut' => StatutRemboursement::class,
            'motif' => MotifRemboursement::class,
            'montant' => 'decimal:2',
            'automatique' => 'boolean',
            'valide_le' => 'immutable_datetime',
            'termine_le' => 'immutable_datetime',
        ];
    }

    /** À appeler sous verrou, dans une transaction. */
    public function transitionVers(StatutRemboursement $cible): void
    {
        if (! $this->statut->peutPasserA($cible)) {
            throw new LogicException("Remboursement {$this->id} : transition interdite de {$this->statut->value} vers {$cible->value}.");
        }

        $this->statut = $cible;
    }

    public function paiement(): BelongsTo
    {
        return $this->belongsTo(Paiement::class);
    }

    public function demandeur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'demande_par');
    }

    public function validateur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'valide_par');
    }
}
