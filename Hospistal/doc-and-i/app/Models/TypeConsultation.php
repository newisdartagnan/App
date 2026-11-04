<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TypeConsultation extends Model
{
    use HasUuids;

    protected $table = 'types_consultation';

    protected $fillable = ['specialite_id', 'nom', 'duree_minutes', 'prix', 'devise', 'actif'];

    protected function casts(): array
    {
        return ['duree_minutes' => 'integer', 'prix' => 'decimal:2', 'actif' => 'boolean'];
    }

    public function specialite(): BelongsTo
    {
        return $this->belongsTo(Specialite::class);
    }
}
