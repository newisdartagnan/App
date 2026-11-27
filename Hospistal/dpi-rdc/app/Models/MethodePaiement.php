<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Moyen de régler les factures (« paiement direct », « abonné mutuelle X »…),
 * partagé entre patients : celle créée pour l'un se réutilise pour l'autre.
 */
class MethodePaiement extends Model
{
    use HasUuids;

    protected $table = 'methodes_paiement';

    protected $fillable = ['libelle', 'type', 'assurance_id', 'est_actif'];

    protected function casts(): array
    {
        return ['est_actif' => 'boolean'];
    }

    public const TYPES = [
        'direct' => 'Paiement direct',
        'mutuelle' => 'Mutuelle de santé',
        'assurance' => 'Assurance',
        'employeur' => 'Employeur / convention',
        'abonnement' => 'Abonnement',
        'indigent' => 'Prise en charge indigent',
        'autre' => 'Autre',
    ];

    public function assurance(): BelongsTo
    {
        return $this->belongsTo(Assurance::class);
    }

    public function liens(): HasMany
    {
        return $this->hasMany(PatientMethodePaiement::class);
    }
}
