<?php

namespace App\Models;

use App\Enums\MoyenPaiement;
use App\Enums\StatutPaiement;
use App\Support\Montant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

/**
 * Un paiement (PAY-02 à PAY-04). Aucun attribut n'est « fillable » : tout
 * passe par les services du lot paiement, et le statut ne change que par
 * transitionVers(), sous verrou.
 */
class Paiement extends Model
{
    use HasUuids;

    protected $table = 'paiements';

    protected $guarded = ['*'];

    /** Numéro du payeur : jamais sérialisé (pages, journaux, JSON). */
    protected $hidden = ['telephone_payeur'];

    protected function casts(): array
    {
        return [
            'statut' => StatutPaiement::class,
            'moyen' => MoyenPaiement::class,
            'montant' => 'decimal:2',
            'montant_reference' => 'decimal:2',
            'montant_rembourse' => 'decimal:2',
            'confirme_le' => 'immutable_datetime',
            'echoue_le' => 'immutable_datetime',
        ];
    }

    /** Seul chemin pour changer le statut. À appeler sous verrou (lockForUpdate), dans une transaction. */
    public function transitionVers(StatutPaiement $cible): void
    {
        if (! $this->statut->peutPasserA($cible)) {
            throw new LogicException("Paiement {$this->id} : transition interdite de {$this->statut->value} vers {$cible->value}.");
        }

        $this->statut = $cible;
    }

    /** Ce qui reste remboursable, en centimes. */
    public function resteRemboursableEnCentimes(): int
    {
        return Montant::enCentimes((string) $this->montant) - Montant::enCentimes((string) $this->montant_rembourse);
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'patient_id');
    }

    public function rendezVous(): BelongsTo
    {
        return $this->belongsTo(RendezVous::class);
    }

    public function tauxChange(): BelongsTo
    {
        return $this->belongsTo(TauxChange::class);
    }

    public function remboursements(): HasMany
    {
        return $this->hasMany(Remboursement::class);
    }

    public function facture(): HasOne
    {
        return $this->hasOne(Facture::class)->where('type', 'facture');
    }
}
