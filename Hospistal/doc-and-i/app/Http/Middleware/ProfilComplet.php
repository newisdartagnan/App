<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Pas de parcours de soin sans nom, sexe et date de naissance (CPT-02). */
class ProfilComplet
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user() && ! $request->user()->profilComplet()) {
            return redirect()->route('profil.modifier');
        }

        return $next($request);
    }
}
