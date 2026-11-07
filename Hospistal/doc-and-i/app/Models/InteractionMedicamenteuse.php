<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Interaction médicamenteuse majeure entre deux DCI (PRE-02). La table est
 * livrée VIDE : son contenu est médical, fourni et validé par le pharmacien
 * du client, jamais inventé par l'application.
 */
class InteractionMedicamenteuse extends Model
{
    use HasUuids;

    protected $table = 'interactions_medicamenteuses';

    protected $guarded = ['*'];
}
