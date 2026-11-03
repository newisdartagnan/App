<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Pas de parcours de soin sans consentement en vigueur (CPT-04). */
class ConsentementAJour
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user() && $request->user()->consentementActif() === null) {
            return redirect()->guest(route('consentement'));
        }

        return $next($request);
    }
}
