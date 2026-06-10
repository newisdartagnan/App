<?php
namespace App\Models;
use App\Models\Concerns\Syncable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
    ];
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