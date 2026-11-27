<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PatientMethodePaiement extends Model
{
    use HasUuids;

    protected $table = 'patient_methodes_paiement';

    protected $fillable = ['patient_id', 'methode_paiement_id', 'identifiant', 'date_debut', 'date_fin', 'user_id'];

    protected function casts(): array
    {
        return ['date_debut' => 'date', 'date_fin' => 'date'];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function methode(): BelongsTo
    {
        return $this->belongsTo(MethodePaiement::class, 'methode_paiement_id');
    }

    public function enVigueur(): bool
    {
        return (! $this->date_debut || $this->date_debut->lte(today()))
            && (! $this->date_fin || $this->date_fin->gte(today()));
    }
}
