<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Examen du catalogue (PRE-04), géré par l'administration. Aucun prix par défaut. */
class Examen extends Model
{
    use HasUuids;

    public const CATEGORIES = ['biologie', 'imagerie'];

    protected $table = 'examens';

    protected $fillable = ['code', 'libelle', 'categorie', 'prix', 'devise', 'preparation', 'actif'];

    protected function casts(): array
    {
        return ['prix' => 'decimal:2', 'actif' => 'boolean'];
    }
}
