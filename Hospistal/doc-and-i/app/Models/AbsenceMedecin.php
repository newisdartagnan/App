<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AbsenceMedecin extends Model
{
    use HasUuids;

    protected $table = 'absences_medecin';

    protected $fillable = ['debut', 'fin', 'motif'];

    protected function casts(): array
    {
        return ['debut' => 'datetime', 'fin' => 'datetime'];
    }

    public function medecin(): BelongsTo
    {
        return $this->belongsTo(Medecin::class);
    }
}
