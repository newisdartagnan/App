<?php

namespace App\Models;

use App\Models\Concerns\Syncable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExamenLaboratoire extends Model
{
    use HasUuids, Syncable;

    protected $table = 'examens_laboratoire';

    protected $fillable = [
        'numero_bon', 'visit_id', 'patient_id', 'prescripteur_id', 'laborantin_id', 'facture_id',
        'date_prescription', 'date_prelevement', 'date_resultat',
        'statut', 'domaine', 'urgence', 'observations_cliniques', 'observations_laborantin', 'conclusion', 'sync_status',
    ];

    protected function casts(): array
    {
        return [
            'date_prescription' => 'datetime',
            'date_prelevement' => 'datetime',
            'date_resultat' => 'datetime',
            'urgence' => 'boolean',
        ];
    }

    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function prescripteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prescripteur_id');
    }

    public function laborantin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'laborantin_id');
    }

    public function resultats(): HasMany
    {
        return $this->hasMany(ResultatExamen::class, 'examen_id');
    }

    public function facture(): BelongsTo
    {
        return $this->belongsTo(Facture::class);
    }

    public function montantTotal(): float
    {
        return (float) $this->resultats()->join('types_examens', 'types_examens.id', '=', 'resultats_examens.type_examen_id')
            ->sum('types_examens.prix');
    }
}
