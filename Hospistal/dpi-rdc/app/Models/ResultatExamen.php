<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResultatExamen extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $table = 'resultats_examens';

    protected $fillable = [
        'examen_id', 'type_examen_id', 'valeur_brute', 'valeur_numerique', 'unite',
        'interpretation', 'valeur_reference_min', 'valeur_reference_max',
        'commentaire', 'valide_par', 'valide_at',
    ];

    protected function casts(): array
    {
        return [
            'valeur_numerique' => 'decimal:4',
            'valeur_reference_min' => 'decimal:4',
            'valeur_reference_max' => 'decimal:4',
            'valide_at' => 'datetime',
        ];
    }

    public function examen(): BelongsTo
    {
        return $this->belongsTo(ExamenLaboratoire::class, 'examen_id');
    }

    public function typeExamen(): BelongsTo
    {
        return $this->belongsTo(TypeExamen::class, 'type_examen_id');
    }

    public function validateur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'valide_par');
    }
}
