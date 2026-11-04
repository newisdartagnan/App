<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DemandeConsultation extends Model
{
    use HasUuids;

    protected $table = 'demandes_consultation';

    /** patient_id et statut ne sont pas « fillable » : posés par le code, jamais par un formulaire. */
    protected $fillable = ['specialite_id', 'motif', 'symptomes', 'depuis', 'tri_reponses', 'tri_alerte'];

    protected function casts(): array
    {
        return ['tri_reponses' => 'array', 'tri_alerte' => 'boolean'];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'patient_id');
    }

    public function specialite(): BelongsTo
    {
        return $this->belongsTo(Specialite::class);
    }

    public function piecesJointes(): HasMany
    {
        return $this->hasMany(PieceJointeDemande::class, 'demande_id');
    }

    public function rendezVous(): HasMany
    {
        return $this->hasMany(RendezVous::class, 'demande_id');
    }
}
