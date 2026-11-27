<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Fournisseur extends Model
{
    use HasUuids;

    protected $fillable = ['nom', 'contact', 'telephone', 'email', 'adresse', 'identifiant_fiscal', 'delai_livraison_jours', 'est_actif'];

    protected function casts(): array
    {
        return ['est_actif' => 'boolean'];
    }

    public function commandes(): HasMany
    {
        return $this->hasMany(CommandeFournisseur::class);
    }

    public function receptions(): HasMany
    {
        return $this->hasMany(ReceptionStock::class);
    }
}
