<?php
namespace App\Models;
use App\Models\Concerns\Syncable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class Dispensation extends Model
{
    use HasUuids, Syncable;

    // La table dispensations n'a qu'une colonne created_at
    public const UPDATED_AT = null;

    protected $fillable = [
        'ligne_prescription_id', 'pharmacien_id', 'date_dispensation',
        'quantite_dispensee', 'lot', 'prix_applique', 'observations', 'sync_status',
    ];
    protected function casts(): array
    {
        return ['date_dispensation' => 'datetime'];
    }
    public function lignePrescription(): BelongsTo
    {
        return $this->belongsTo(LignePrescription::class, 'ligne_prescription_id');
    }
    public function pharmacien(): BelongsTo
    {
        return $this->belongsTo(User::class, 'pharmacien_id');
    }
}