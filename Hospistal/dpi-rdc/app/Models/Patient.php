<?php

namespace App\Models;

use App\Models\Concerns\Syncable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Patient extends Model
{
    use HasUuids, SoftDeletes, Syncable;

    protected $fillable = [
        'establishment_id', 'dossier_number', 'global_patient_id',
        'nom', 'prenom', 'nom_soundex', 'prenom_soundex',
        'date_naissance', 'lieu_naissance', 'sexe', 'nationalite',
        'telephone', 'adresse', 'province', 'territoire',
        'profession', 'situation_matrimoniale', 'niveau_instruction',
        'contact_urgence_nom', 'contact_urgence_telephone', 'contact_urgence_lien',
        'type_prise_en_charge', 'assurance_nom', 'assurance_numero',
        'duplicate_of', 'duplicate_confidence', 'merge_status',
        'sync_status', 'sync_hash',
    ];

    protected function casts(): array
    {
        return [
            'date_naissance' => 'date',
            'duplicate_confidence' => 'decimal:2',
            'telephone' => 'encrypted',
            'adresse' => 'encrypted',
            'contact_urgence_telephone' => 'encrypted',
        ];
    }

    public function establishment(): BelongsTo
    {
        return $this->belongsTo(Establishment::class);
    }

    public function visits(): HasMany
    {
        return $this->hasMany(Visit::class);
    }

    public function getNomCompletAttribute(): string
    {
        return "{$this->nom} {$this->prenom}";
    }

    protected function getSyncPriority(): int
    {
        return 8;
    }
}
