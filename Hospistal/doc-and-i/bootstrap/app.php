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
        // Sur toutes les réponses, 404 et /up compris, pas seulement le groupe web.
        $middleware->append(EnTetesSecurite::class);
        $middleware->web(append: [HandleInertiaRequests::class]);

        // Derrière un répartiteur de charge ou un proxy TLS : sans cela,
        // toutes les requêtes porteraient l'adresse du proxy.
        $proxys = array_filter(array_map('trim', explode(',', (string) env('TRUSTED_PROXIES'))));
        if ($proxys !== []) {
            $middleware->trustProxies(at: $proxys);
        }

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
