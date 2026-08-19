<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Note du dossier de séjour : évolution médicale ou transmission infirmière
 * (modèle GPS « Évolutions et diagnostics » / « Transmissions infirmières »).
 */
class NoteEvolution extends Model
{
    use HasUuids;

    protected $table = 'notes_evolution';

    protected $fillable = ['visit_id', 'user_id', 'type', 'etat_general', 'note'];

    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
