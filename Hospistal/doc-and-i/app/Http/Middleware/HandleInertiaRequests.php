<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'application' => config('app.name'),
            // Le strict nécessaire pour l'en-tête : jamais le dossier médical.
            'utilisateur' => fn () => $request->user() ? [
                'prenom' => $request->user()->prenom,
                'nom' => $request->user()->nom,
            ] : null,
            'flash' => [
                'succes' => fn () => $request->session()->get('succes'),
            ],
        ];
    }
}
