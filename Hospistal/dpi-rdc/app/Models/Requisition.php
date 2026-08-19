<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Demande d'approvisionnement d'une officine auprès du dépôt central.
 */
class Requisition extends Model
{
    use HasUuids;

    protected $fillable = [
        'numero', 'officine_id', 'source_id', 'demandeur_id', 'servie_par',
        'statut', 'motif', 'date_demande', 'date_service',
    ];

    protected function casts(): array
    {
        return ['date_demande' => 'datetime', 'date_service' => 'datetime'];
    }

    public function officine(): BelongsTo
    {
        return $this->belongsTo(Officine::class);
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Officine::class, 'source_id');
    }

    public function demandeur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'demandeur_id');
    }

    public function servePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'servie_par');
    }

    public function lignes(): HasMany
    {
        return $this->hasMany(LigneRequisition::class);
    }

    public function estServie(): bool
    {
        return in_array($this->statut, ['servie', 'partiellement_servie'], true);
    }

    public static function genererNumero(): string
    {
        $prefix = 'REQ-' . now()->format('Y') . '-';
        $last = static::where('numero', 'like', $prefix . '%')
            ->orderByDesc('numero')->value('numero');

        return $prefix . str_pad((string) ($last ? (int) substr($last, -6) + 1 : 1), 6, '0', STR_PAD_LEFT);
    }
}
