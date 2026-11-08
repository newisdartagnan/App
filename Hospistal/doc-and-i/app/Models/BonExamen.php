<?php

namespace App\Models;

use App\Enums\StatutBonExamen;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/** Bon d'examen (PRE-04, PRE-05). Écrit par les services du laboratoire seulement ; statut par transitionVers(). */
class BonExamen extends Model
{
    use HasUuids;

    protected $table = 'bons_examen';

    protected $guarded = ['*'];

    protected $hidden = ['code_retrait_empreinte'];

    protected function casts(): array
    {
        return [
            'statut' => StatutBonExamen::class,
            'montant' => 'decimal:2',
            'date_souhaitee' => 'immutable_date',
            'paye_le' => 'immutable_datetime',
            'transmis_le' => 'immutable_datetime',
            'annule_le' => 'immutable_datetime',
        ];
    }

    /** À appeler sous verrou (lockForUpdate), dans une transaction. */
    public function transitionVers(StatutBonExamen $cible): void
    {
        if (! $this->statut->peutPasserA($cible)) {
            throw new LogicException("Bon d'examen {$this->id} : transition interdite de {$this->statut->value} vers {$cible->value}.");
        }

        $this->statut = $cible;
    }

    public function lignes(): HasMany
    {
        return $this->hasMany(LigneBonExamen::class, 'bon_id')->orderBy('rang');
    }

    public function resultats(): HasMany
    {
        return $this->hasMany(ResultatExamen::class, 'bon_id');
    }

    public function rendezVous(): BelongsTo
    {
        return $this->belongsTo(RendezVous::class);
    }

    public function medecin(): BelongsTo
    {
        return $this->belongsTo(Medecin::class);
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'patient_id');
    }

    public function laboratoire(): BelongsTo
    {
        return $this->belongsTo(Partenaire::class, 'laboratoire_id');
    }
}
