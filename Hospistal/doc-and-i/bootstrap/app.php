<?php

use App\Http\Middleware\ConsentementAJour;
use App\Http\Middleware\EnTetesSecurite;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\PersonnelAuthentifie;
use App\Http\Middleware\ProfilComplet;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Inertia\EncryptHistoryMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Sur toutes les réponses, 404 et /up compris, pas seulement le groupe web.
        $middleware->append(EnTetesSecurite::class);
        // Historique chiffré (Inertia garde les props des pages dans
        // history.state) : après déconnexion, « Précédent » sur un poste partagé
        // ne réaffiche ni données de santé, ni secret TOTP, ni codes de
        // récupération. Toutes les pages : l'activation se fait avant connexion.
        $middleware->web(append: [HandleInertiaRequests::class, EncryptHistoryMiddleware::class]);

        // Derrière un répartiteur de charge ou un proxy TLS : sans cela,
        // toutes les requêtes porteraient l'adresse du proxy.
        $proxys = array_filter(array_map('trim', explode(',', (string) env('TRUSTED_PROXIES'))));
        if ($proxys !== []) {
            $middleware->trustProxies(at: $proxys);
        }

        // PAY-04 : l'agrégateur n'a pas de jeton CSRF ; son rappel est
        // authentifié par sa signature (RappelPaiementController).
        $middleware->validateCsrfTokens(except: ['paiement/rappel/*']);

        $middleware->alias([
            'profil.complet' => ProfilComplet::class,
            'consentement' => ConsentementAJour::class,
            'role' => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'personnel' => PersonnelAuthentifie::class,
        ]);

        $middleware->redirectGuestsTo(fn () => route('connexion'));
        // Un membre du personnel déjà connecté va dans son espace, pas dans celui du patient (403).
        $middleware->redirectUsersTo(fn (Request $requete) => $requete->user()?->estDuPersonnel()
            ? route('personnel.accueil')
            : route('espace'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
