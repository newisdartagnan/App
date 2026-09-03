<?php

namespace App\Models;

use App\Models\Concerns\Syncable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Consultation extends Model
{
    use HasUuids, Syncable;

    protected $fillable = [
        'visit_id', 'user_id', 'validated_by', 'date_consultation', 'type',
        'histoire_maladie', 'antecedents_personnels', 'antecedents_familiaux',
        'antecedents_chirurgicaux', 'allergies', 'traitements_en_cours',
        'examen_general', 'examen_physique', 'signes_vitaux',
        'hypotheses_diagnostiques', 'diagnostics', 'conclusion',
        'conduite_a_tenir', 'observations', 'statut',
        'finalise_at', 'valide_at', 'sync_status',
        'orientation', 'service_oriente_id',
    ];

    /**
     * Ce que devient le patient au sortir du cabinet.
     *
     * « En attente de résultats » est une décision comme une autre — c'est
     * même la plus fréquente : on ne tranche pas avant d'avoir la goutte
     * épaisse. Elle doit pouvoir s'écrire, quitte à être reprise ensuite.
     */
    public const ORIENTATIONS = [
        'domicile' => 'Retour à domicile',
        'attente_examens' => 'En attente de résultats d\'examens',
        'surveillance' => 'Mise en observation',
        'hospitalisation' => 'Hospitalisation',
        'bloc' => 'Bloc opératoire',
        'reference' => 'Référé vers un autre établissement',
        'deces' => 'Décès',
    ];

    public function libelleOrientation(): ?string
    {
        return self::ORIENTATIONS[$this->orientation] ?? $this->orientation;
    }

    /** L'orientation appelle-t-elle un lit ? */
    public function demandeUnLit(): bool
    {
        return in_array($this->orientation, ['hospitalisation', 'surveillance'], true);
    }

    public function serviceOriente(): BelongsTo
    {
        return $this->belongsTo(Service::class, 'service_oriente_id');
    }

    protected function casts(): array
    {
        return [
            'date_consultation' => 'datetime',
            'traitements_en_cours' => 'array',
            'examen_physique' => 'array',
            'signes_vitaux' => 'array',
            'diagnostics' => 'array',
            'finalise_at' => 'datetime',
            'valide_at' => 'datetime',
        ];
    }

    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }

    public function prescriptions(): HasMany
    {
        return $this->hasMany(Prescription::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function validatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'validated_by');
    }

    protected function getSyncPriority(): int
    {
        return 9;
    }
}
