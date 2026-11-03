<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Specialite extends Model
{
    use HasUuids;

    protected $table = 'specialites';

    protected $fillable = ['nom', 'slug', 'ordre', 'active'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'ordre' => 'integer'];
    }

    public function typesConsultation(): HasMany
    {
        return $this->hasMany(TypeConsultation::class);
    }

    public function medecins(): HasMany
    {
        return $this->hasMany(Medecin::class);
    }
}
