<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssuranceCouverture extends Model
{
    use HasUuids;
    protected $table = 'assurance_couvertures';
    protected $fillable = [
        'assurance_id', 'type', 'reference_id', 'reference_libelle',
        'couvert', 'taux_specifique',
    ];
    protected function casts(): array
    {
        return ['couvert' => 'boolean'];
    }
    public function assurance(): BelongsTo
    {
        return $this->belongsTo(Assurance::class);
    }
}