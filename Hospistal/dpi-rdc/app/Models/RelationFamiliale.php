<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Lien de parenté entre deux dossiers. « lien » dit ce qu'est le proche pour
 * le patient ; la relation inverse se lit sans être saisie deux fois.
 */
class RelationFamiliale extends Model
{
    use HasUuids;

    protected $table = 'relations_familiales';

    protected $fillable = ['patient_id', 'proche_id', 'lien', 'precision', 'user_id'];

    public const LIENS = [
        'parent' => 'Parent (père / mère)',
        'enfant' => 'Enfant',
        'frere_soeur' => 'Frère ou sœur',
        'conjoint' => 'Conjoint(e)',
        'autre' => 'Autre lien',
    ];

    public const INVERSES = [
        'parent' => 'enfant', 'enfant' => 'parent', 'frere_soeur' => 'frere_soeur',
        'conjoint' => 'conjoint', 'autre' => 'autre',
    ];

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function proche(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'proche_id');
    }
}
