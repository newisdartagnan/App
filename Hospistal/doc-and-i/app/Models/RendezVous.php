<?php

namespace App\Models;

use App\Enums\StatutRendezVous;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Un rendez-vous. Aucun attribut n'est « fillable » : tout passe par le
 * service de réservation, et le statut ne change que par transitionVers().
 */
class RendezVous extends Model
{
    use HasUuids;

    protected $table = 'rendez_vous';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'statut' => StatutRendezVous::class,
            'debut' => 'immutable_datetime',
            'fin' => 'immutable_datetime',
            'expire_le' => 'immutable_datetime',
            'annule_le' => 'immutable_datetime',
            'rappel_24h_envoye_le' => 'immutable_datetime',
            'rappel_1h_envoye_le' => 'immutable_datetime',
            'prix' => 'decimal:2',
            'reports' => 'integer',
            'taux_remboursement' => 'integer',
        ];
    }

    /**
     * Seul chemin pour changer le statut : refuse toute transition interdite
     * (StatutRendezVous::transitionsAutorisees). À appeler sous verrou
     * (lockForUpdate) dans une transaction.
     */
    public function transitionVers(StatutRendezVous $cible): void
    {
        if (! $this->statut->peutPasserA($cible)) {
            throw new LogicException("Rendez-vous {$this->id} : transition interdite de {$this->statut->value} vers {$cible->value}.");
        }

        $this->statut = $cible;
    }

    /**
     * RG-03, seul chemin d'un rendez-vous expiré vers « confirmé » : un
     * paiement encaissé après la libération du créneau, quand ce créneau est
     * encore libre. Le tableau des transitions garde Expire comme état final ;
     * cette reprise n'est appelée que par ReservationService::confirmerApresPaiement,
     * sous verrou du médecin, après vérification du créneau.
     */
    public function reprendreApresPaiementTardif(): void
    {
        if ($this->statut !== StatutRendezVous::Expire) {
            throw new LogicException("Rendez-vous {$this->id} : seule une attente expirée se reprend après paiement ({$this->statut->value}).");
        }

        $this->statut = StatutRendezVous::Confirme;
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'patient_id');
    }

    public function medecin(): BelongsTo
    {
        return $this->belongsTo(Medecin::class);
    }

    public function demande(): BelongsTo
    {
        return $this->belongsTo(DemandeConsultation::class, 'demande_id');
    }

    public function typeConsultation(): BelongsTo
    {
        return $this->belongsTo(TypeConsultation::class);
    }
}
