<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PieceJointeDemande extends Model
{
    use HasUuids;

    protected $table = 'pieces_jointes_demande';

    protected $fillable = ['chemin', 'nom_original', 'type_mime', 'taille_octets'];

    protected $hidden = ['chemin'];

    public function demande(): BelongsTo
    {
        return $this->belongsTo(DemandeConsultation::class, 'demande_id');
    }
}
