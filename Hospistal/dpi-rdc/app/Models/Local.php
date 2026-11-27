<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Local d'un service (salle, chambre, box) : il porte les lits. */
class Local extends Model
{
    use HasUuids;

    protected $table = 'locaux';

    protected $fillable = ['establishment_id', 'service_id', 'nom', 'type', 'etage', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public const TYPES = [
        'salle' => 'Salle', 'chambre' => 'Chambre', 'box' => 'Box de consultation',
        'bureau' => 'Bureau', 'autre' => 'Autre',
    ];

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function lits(): HasMany
    {
        return $this->hasMany(Lit::class, 'local_id');
    }
}
