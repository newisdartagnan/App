<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Message extends Model
{
    use HasUuids;

    protected $fillable = ['expediteur_id', 'destinataire_id', 'contenu', 'lu_at', 'supprime', 'masque_destinataire'];

    protected function casts(): array
    {
        return ['lu_at' => 'datetime', 'supprime' => 'boolean', 'masque_destinataire' => 'boolean'];
    }

    public function expediteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'expediteur_id');
    }

    public function destinataire(): BelongsTo
    {
        return $this->belongsTo(User::class, 'destinataire_id');
    }

    /** Messages visibles par $moi dans la conversation avec $autre. */
    public static function entre(string $moi, string $autre)
    {
        return static::query()->where(fn ($q) => $q
            ->where(fn ($w) => $w->where('expediteur_id', $moi)->where('destinataire_id', $autre))
            ->orWhere(fn ($w) => $w->where('expediteur_id', $autre)->where('destinataire_id', $moi)->where('masque_destinataire', false)));
    }
}
