<?php

use App\Http\Middleware\ConsentementAJour;
use App\Http\Middleware\EnTetesSecurite;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\ProfilComplet;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            HandleInertiaRequests::class,
            EnTetesSecurite::class,
        ]);

        $middleware->alias([
            'profil.complet' => ProfilComplet::class,
            'consentement' => ConsentementAJour::class,
        ]);

        $middleware->redirectGuestsTo(fn () => route('connexion'));
        $middleware->redirectUsersTo(fn () => route('espace'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
