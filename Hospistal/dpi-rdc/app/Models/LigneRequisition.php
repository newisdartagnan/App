<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LigneRequisition extends Model
{
    use HasUuids;

    protected $table = 'lignes_requisition';

    protected $fillable = ['requisition_id', 'medicament_id', 'quantite_demandee', 'quantite_servie'];

    protected function casts(): array
    {
        return ['quantite_demandee' => 'decimal:2', 'quantite_servie' => 'decimal:2'];
    }

    public function requisition(): BelongsTo
    {
        return $this->belongsTo(Requisition::class);
    }

    public function medicament(): BelongsTo
    {
        return $this->belongsTo(Medicament::class);
    }

    public function reste(): float
    {
        return max(0, (float) $this->quantite_demandee - (float) $this->quantite_servie);
    }
}
