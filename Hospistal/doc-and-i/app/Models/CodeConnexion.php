<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class CodeConnexion extends Model
{
    use HasUuids;

    protected $table = 'codes_connexion';

    protected $fillable = ['telephone', 'empreinte', 'expire_le', 'essais', 'utilise_le', 'invalide_le', 'ip'];

    protected $hidden = ['empreinte'];

    protected function casts(): array
    {
        return [
            'expire_le' => 'datetime',
            'utilise_le' => 'datetime',
            'invalide_le' => 'datetime',
            'essais' => 'integer',
        ];
    }
}
