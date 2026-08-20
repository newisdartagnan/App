<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Part d'un acompte affectée à une facture précise. La trace est conservée
 * ligne à ligne : à la sortie on sait exactement ce que l'avance a couvert
 * et ce qui reste à rendre au patient.
 */
class ImputationAcompte extends Model
{
    use HasUuids;

    protected $table = 'imputations_acompte';

    protected $fillable = ['caution_id', 'facture_id', 'user_id', 'montant'];

    protected function casts(): array
    {
        return ['montant' => 'decimal:2'];
    }

    public function acompte(): BelongsTo
    {
        return $this->belongsTo(Caution::class, 'caution_id');
    }

    public function facture(): BelongsTo
    {
        return $this->belongsTo(Facture::class);
    }

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
