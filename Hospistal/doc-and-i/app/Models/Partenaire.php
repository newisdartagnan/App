<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Laboratoire ou pharmacie partenaire (ADM-01), géré par l'administration. */
class Partenaire extends Model
{
    use HasUuids;

    public const TYPES = ['laboratoire', 'pharmacie'];

    protected $table = 'partenaires';

    protected $fillable = ['type', 'nom', 'commune', 'adresse', 'telephone', 'email', 'actif'];

    protected function casts(): array
    {
        return ['actif' => 'boolean'];
    }
}
