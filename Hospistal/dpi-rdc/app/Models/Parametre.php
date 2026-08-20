<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Réglage d'établissement modifiable depuis l'application, sans passer par
 * le code : taux de change, seuils, préférences de facturation.
 */
class Parametre extends Model
{
    use HasUuids;

    protected $table = 'parametres';

    protected $fillable = ['establishment_id', 'cle', 'valeur', 'user_id'];

    protected function casts(): array
    {
        return ['valeur' => 'array'];
    }

    public function establishment(): BelongsTo
    {
        return $this->belongsTo(Establishment::class);
    }

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
