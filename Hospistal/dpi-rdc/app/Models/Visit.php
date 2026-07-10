<?php
namespace App\Models;
use App\Models\Concerns\Syncable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
class Visit extends Model
{
    use HasUuids, Syncable;
    protected $fillable = [
        'patient_id', 'establishment_id', 'user_id', 'type', 'statut',
        'date_entree', 'date_sortie', 'duree_sejour_jours',
        'service_id', 'lit_id', 'mode_entree', 'provenance', 'mode_sortie', 'transfert_vers',
        'poids_kg', 'taille_cm', 'imc', 'tension_systolique', 'tension_diastolique',
        'temperature', 'frequence_cardiaque', 'frequence_respiratoire', 'saturation_o2', 'glasgow',
        'motif_consultation', 'symptomes_principaux', 'tarif_consultation', 'est_payant', 'sync_status',
    ];
    protected function casts(): array
    {
        return [
            'date_entree' => 'datetime',
            'date_sortie' => 'datetime',
            'est_payant' => 'boolean',
        ];
    }
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
    public function establishment(): BelongsTo
    {
        return $this->belongsTo(Establishment::class);
    }
    public function consultations(): HasMany
    {
        return $this->hasMany(Consultation::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function lit(): BelongsTo
    {
        return $this->belongsTo(Lit::class);
    }

    public function factures(): HasMany
    {
        return $this->hasMany(Facture::class);
    }

    public function examensLaboratoire(): HasMany
    {
        return $this->hasMany(ExamenLaboratoire::class);
    }

    public function actesCliniques(): HasMany
    {
        return $this->hasMany(ActeClinique::class);
    }

    public function estHospitalise(): bool
    {
        return $this->type === 'hospitalisation' && $this->statut === 'en_cours';
    }

    public function joursHospitalisation(): int
    {
        $fin = $this->date_sortie ?? now();
        return max(1, (int) $this->date_entree->diffInDays($fin) + 1);
    }
}