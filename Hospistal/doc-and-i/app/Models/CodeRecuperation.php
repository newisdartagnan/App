<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Code de récupération du second facteur (CPT-06) : empreinte seulement, usage unique. */
class CodeRecuperation extends Model
{
    use HasUuids;

    protected $table = 'codes_recuperation_personnel';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['utilise_le' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
