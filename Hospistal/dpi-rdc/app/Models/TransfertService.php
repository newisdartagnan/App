<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Passage d'un patient d'un service à un autre, à l'intérieur du même
 * séjour.
 *
 * À ne pas confondre avec le transfert de sortie, qui clôt l'admission et
 * envoie le patient dans un autre établissement. Ici le patient reste
 * hospitalisé : la réanimation le confie à la médecine interne, le dossier
 * suit, seuls le service et le lit changent.
 */
class TransfertService extends Model
{
    use HasUuids;

    protected $table = 'transferts_service';

    protected $fillable = [
        'visit_id', 'service_source_id', 'lit_source_id',
        'service_destination_id', 'lit_destination_id',
        'demandeur_id', 'demandeur_nom', 'motif', 'user_id', 'transfere_a',
    ];

    protected function casts(): array
    {
        return ['transfere_a' => 'datetime'];
    }

    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }

    public function serviceSource(): BelongsTo
    {
        return $this->belongsTo(Service::class, 'service_source_id');
    }

    public function serviceDestination(): BelongsTo
    {
        return $this->belongsTo(Service::class, 'service_destination_id');
    }

    public function litSource(): BelongsTo
    {
        return $this->belongsTo(Lit::class, 'lit_source_id');
    }

    public function litDestination(): BelongsTo
    {
        return $this->belongsTo(Lit::class, 'lit_destination_id');
    }

    /** Médecin demandeur ayant un compte, s'il en a un. */
    public function demandeur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'demandeur_id');
    }

    /** Agent qui a enregistré le transfert dans l'application. */
    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function trajet(): string
    {
        return ($this->serviceSource?->nom ?? 'Sans service')
            .' → '.($this->serviceDestination?->nom ?? '—');
    }
}
