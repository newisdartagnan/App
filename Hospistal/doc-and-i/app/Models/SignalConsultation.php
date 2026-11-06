<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Message de mise en relation WebRTC, relayé entre les deux participants puis purgé. */
class SignalConsultation extends Model
{
    public const UPDATED_AT = null;

    public const CREATED_AT = 'cree_le';

    protected $table = 'signaux_consultation';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['charge' => 'array', 'cree_le' => 'immutable_datetime'];
    }
}
