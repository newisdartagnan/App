<?php
namespace App\Models;
use App\Models\Concerns\Syncable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
class Prescription extends Model
{
    use HasUuids, Syncable;
    protected $fillable = [
        'consultation_id', 'patient_id', 'prescripteur_id',
        'date_prescription', 'statut', 'observations', 'sync_status',
    ];
    protected function casts(): array
    {
        return ['date_prescription' => 'datetime'];
    }
    public function consultation(): BelongsTo
    {
        return $this->belongsTo(Consultation::class);
    }
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }
    public function prescripteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prescripteur_id');
    }
    public function lignes(): HasMany
    {
        return $this->hasMany(LignePrescription::class);
    }
}