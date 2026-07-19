<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExamenFichier extends Model
{
    use HasUuids;

    protected $table = 'examen_fichiers';

    protected $fillable = [
        'examen_id', 'nom_original', 'chemin', 'type', 'description', 'ajoute_par',
    ];

    public function examen(): BelongsTo
    {
        return $this->belongsTo(ExamenLaboratoire::class, 'examen_id');
    }
}
