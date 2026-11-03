<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class EspacePatientController extends Controller
{
    public function __invoke(Request $request): Response
    {
        return Inertia::render('Espace/Accueil', [
            'prenom' => $request->user()->prenom,
        ]);
    }
}
