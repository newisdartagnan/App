<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PatientReferentielMedical extends Model
{
    use HasUuids;

    protected $table = 'patient_referentiel_medical';

    protected $fillable = [
        'patient_id', 'referentiel_id', 'saisi_par', 'severite', 'precision', 'date_constat',
    ];

    protected function casts(): array
    {
        return ['date_constat' => 'date'];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function referentiel(): BelongsTo
    {
        return $this->belongsTo(ReferentielMedical::class, 'referentiel_id');
    }

    public function saisiPar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'saisi_par');
    }
}
