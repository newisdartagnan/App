<?php

namespace App\Models;

use App\Enums\StatutConsultation;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/** Salle de téléconsultation d'un rendez-vous (TLC-01). Créée par AccesConsultation seulement. */
class Consultation extends Model
{
    use HasUuids;

    protected $table = 'consultations';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'statut' => StatutConsultation::class,
            'patient_rejoint_le' => 'immutable_datetime',
            'medecin_rejoint_le' => 'immutable_datetime',
            'debut_le' => 'immutable_datetime',
            'fin_le' => 'immutable_datetime',
            'reconnexions' => 'integer',
            'presence_patient_le' => 'immutable_datetime',
            'presence_medecin_le' => 'immutable_datetime',
            'ensemble_le' => 'immutable_datetime',
            'mesure_ensemble_le' => 'immutable_datetime',
            'secondes_ensemble' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // Salle close (terminée, absence) : les signaux WebRTC relayés ne
        // servent plus, ils sont purgés dans la même transaction (TLC-01).
        static::updated(function (Consultation $consultation) {
            if ($consultation->wasChanged('statut') && ! $consultation->statut->estOuverte()) {
                SignalConsultation::where('consultation_id', $consultation->id)->delete();
            }
        });
    }

    /** À appeler sous verrou (lockForUpdate), dans une transaction. */
    public function transitionVers(StatutConsultation $cible): void
    {
        if (! $this->statut->peutPasserA($cible)) {
            throw new LogicException("Consultation {$this->id} : transition interdite de {$this->statut->value} vers {$cible->value}.");
        }

        $this->statut = $cible;
    }

    public function rendezVous(): BelongsTo
    {
        return $this->belongsTo(RendezVous::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(MessageConsultation::class)->orderBy('cree_le');
    }
}
