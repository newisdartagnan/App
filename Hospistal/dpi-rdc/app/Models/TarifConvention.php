<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Prix négocié par une convention pour une prestation, ou pour toute une
 * catégorie (la journée d'hospitalisation). Il remplace le prix public sur
 * les factures de ses bénéficiaires.
 */
class TarifConvention extends Model
{
    use HasUuids;

    protected $table = 'tarifs_convention';

    protected $fillable = ['assurance_id', 'type', 'reference_id', 'reference_libelle', 'prix'];

    protected function casts(): array
    {
        return ['prix' => 'decimal:2'];
    }

    public function assurance(): BelongsTo
    {
        return $this->belongsTo(Assurance::class);
    }
}
