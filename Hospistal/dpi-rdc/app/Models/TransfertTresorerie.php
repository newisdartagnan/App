<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransfertTresorerie extends Model
{
    use HasUuids;

    protected $table = 'transferts_tresorerie';

    protected $fillable = ['numero', 'date_transfert', 'compte_source_id', 'compte_destination_id', 'montant_cdf', 'reference', 'observations', 'user_id'];

    protected function casts(): array
    {
        return ['date_transfert' => 'date', 'montant_cdf' => 'decimal:2'];
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(CompteTresorerie::class, 'compte_source_id');
    }

    public function destination(): BelongsTo
    {
        return $this->belongsTo(CompteTresorerie::class, 'compte_destination_id');
    }
}
