<?php

namespace App\Http\Controllers\Personnel\Medecin;

use App\Http\Controllers\Controller;
use App\Models\Medecin;
use Illuminate\Http\Request;

/**
 * Base des contrôleurs de l'espace médecin. Les routes exigent déjà le rôle
 * medecin (et la session complète du personnel) ; ici, le profil médecin du
 * compte connecté : toute lecture et écriture part de lui, jamais d'un
 * identifiant de médecin reçu du navigateur.
 */
abstract class ControleurMedecin extends Controller
{
    protected function medecin(Request $request): Medecin
    {
        return $request->user()->medecin ?? abort(403);
    }
}
