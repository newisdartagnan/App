<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/** Ordonnance électronique (PRE-01, PRE-03) : numérotée, signée à l'émission, non modifiable. */
class Ordonnance extends Model
{
    use HasUuids;

    protected $table = 'ordonnances';

    protected $guarded = ['*'];

    /** Le code du QR code ne s'affiche qu'au patient et sur le PDF : jamais sérialisé ailleurs. */
    protected $hidden = ['code_verification'];

    protected function casts(): array
    {
        return ['signee_le' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Une ordonnance ne se modifie pas : en émettre une nouvelle.'));
        static::deleting(fn () => throw new LogicException('Une ordonnance ne se supprime pas.'));
    }

    public function lignes(): HasMany
    {
        return $this->hasMany(LigneOrdonnance::class)->orderBy('rang');
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
}
