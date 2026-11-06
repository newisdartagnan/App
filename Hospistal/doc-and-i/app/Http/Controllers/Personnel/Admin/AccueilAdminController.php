<?php

namespace App\Http\Controllers\Personnel\Admin;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

/** Accueil de l'espace administrateur ; devient le tableau de bord (ADM-02). */
class AccueilAdminController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('Personnel/Admin/Accueil');
    }
}
