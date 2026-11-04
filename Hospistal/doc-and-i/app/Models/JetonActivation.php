<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Lien d'activation d'un compte du personnel (CPT-05) : empreinte seulement, usage unique. */
class JetonActivation extends Model
{
    use HasUuids;

    protected $table = 'jetons_activation_personnel';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['expire_le' => 'datetime', 'utilise_le' => 'datetime', 'invalide_le' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Jamais servi, jamais remplacé, pas expiré. */
    public function scopeValables(Builder $requete): Builder
    {
        return $requete->whereNull('utilise_le')->whereNull('invalide_le')->where('expire_le', '>', now());
    }
}
