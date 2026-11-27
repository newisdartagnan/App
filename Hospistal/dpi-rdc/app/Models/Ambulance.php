<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Ambulance extends Model
{
    use HasUuids;

    protected $fillable = ['establishment_id', 'libelle', 'immatriculation', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function sorties(): HasMany
    {
        return $this->hasMany(SortieAmbulance::class);
    }
}
