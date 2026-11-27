<?php

namespace App\Models;

use Spatie\Permission\Models\Role as SpatieRole;

/**
 * Rôle (fonction du personnel), avec un libellé et une description lisibles.
 *
 * Les champs sont déclarés explicitement : le modèle d'origine accepte tout
 * (« guarded » vide), et Laravel garde alors en mémoire la liste des colonnes
 * vue au premier appel — une colonne ajoutée ensuite serait ignorée en silence.
 */
class Role extends SpatieRole
{
    protected $fillable = ['name', 'guard_name', 'libelle', 'description'];
}
