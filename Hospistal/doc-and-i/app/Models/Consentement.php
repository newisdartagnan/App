<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Consentement extends Model
{
    use HasUuids;

    protected $table = 'consentements';

    protected $fillable = ['user_id', 'version', 'accepte_le', 'ip', 'agent_utilisateur', 'retire_le'];

    protected function casts(): array
    {
        return [
            'accepte_le' => 'datetime',
            'retire_le' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
