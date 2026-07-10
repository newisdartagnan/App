<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FactureTiersPayant extends Model
{
    use HasUuids;
    protected $table = 'facture_tiers_payant';
    protected $fillable = [
        'facture_id', 'ligne_facture_id', 'assurance_id',
        'acte_couvert', 'taux_applique', 'montant_acte',
        'part_assurance', 'part_patient', 'plafond_atteint', 'devise',
    ];
    protected function casts(): array
    {
        return [
            'acte_couvert' => 'boolean',
            'plafond_atteint' => 'boolean',
        ];
    }
    public function assurance(): BelongsTo { return $this->belongsTo(Assurance::class); }
    public function facture(): BelongsTo { return $this->belongsTo(Facture::class); }
}