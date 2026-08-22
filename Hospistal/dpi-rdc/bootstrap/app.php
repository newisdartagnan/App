<?php

use App\Jobs\SyncToCentral;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withSchedule(function (Schedule $schedule): void {
        $schedule->job(new SyncToCentral)->everyFifteenMinutes();
        $schedule->command('dpi:cloturer-visites')->hourly();

        // Le stock de sang du réseau : au quart d'heure, parce qu'entre deux
        // passages on décide d'envoyer une ambulance sur cette ligne-là.
        $schedule->command('dpi:sang-reseau')->everyFifteenMinutes()->withoutOverlapping();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (TokenMismatchException $e, Request $request) {
            if ($request->is('livewire/*') || $request->headers->has('X-Livewire')) {
                return response('', 419);
            }
        });

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
