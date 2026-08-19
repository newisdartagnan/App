<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdministrationTraitement extends Model
{
    use HasUuids;

    protected $table = 'administrations_traitement';

    protected $fillable = ['plan_id', 'heure', 'user_id', 'administre_at', 'observation'];

    protected function casts(): array
    {
        return ['administre_at' => 'datetime'];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(PlanAdministration::class, 'plan_id');
    }

    public function soignant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
