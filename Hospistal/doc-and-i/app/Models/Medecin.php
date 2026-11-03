<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Medecin extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'medecins';

    /** ordre_verifie_le n'est pas « fillable » : seul un administrateur le pose, explicitement. */
    protected $fillable = ['specialite_id', 'numero_ordre', 'presentation', 'langues', 'actif'];

    protected function casts(): array
    {
        return ['ordre_verifie_le' => 'datetime', 'langues' => 'array', 'actif' => 'boolean'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function specialite(): BelongsTo
    {
        return $this->belongsTo(Specialite::class);
    }

    public function plages(): HasMany
    {
        return $this->hasMany(PlageDisponibilite::class);
    }

    public function absences(): HasMany
    {
        return $this->hasMany(AbsenceMedecin::class);
    }

    public function rendezVous(): HasMany
    {
        return $this->hasMany(RendezVous::class);
    }

    /** Proposé aux patients : actif ET inscription à l'Ordre vérifiée (CPT-05). */
    public function scopeReservables(Builder $requete): Builder
    {
        return $requete->where('actif', true)->whereNotNull('ordre_verifie_le');
    }

    public function estReservable(): bool
    {
        return $this->actif && $this->ordre_verifie_le !== null;
    }
}
