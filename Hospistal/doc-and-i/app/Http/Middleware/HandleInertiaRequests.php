<?php

namespace App\Http\Middleware;

use App\Models\User;
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
    /** Libellé du rôle pour le bandeau, et l'espace correspondant (patient, medecin, caisse, admin). */
    private const ROLES = [
        'medecin' => ['Médecin', 'medecin'],
        'caisse' => ['Caisse', 'caisse'],
        'admin_fonctionnel' => ['Administration', 'admin'],
        'admin_technique' => ['Administration technique', 'personnel'],
        'coordinateur' => ['Coordination', 'personnel'],
        'laboratoire' => ['Laboratoire', 'personnel'],
        'support' => ['Support', 'personnel'],
        'auditeur' => ['Audit', 'personnel'],
        'patient' => ['Patient', 'patient'],
    ];

    /** @return array{role: ?string, espace: ?string} */
    private static function roleAffiche(User $user): array
    {
        $roles = $user->getRoleNames();
        foreach (self::ROLES as $role => [$libelle, $espace]) {
            if ($roles->contains($role)) {
                return ['role' => $libelle, 'espace' => $espace];
            }
        }

        return ['role' => null, 'espace' => null];
    }

    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'application' => config('app.name'),
            // Le strict nécessaire pour l'en-tête : jamais le dossier médical.
            'utilisateur' => fn () => $request->user() ? [
                'prenom' => $request->user()->prenom,
                'nom' => $request->user()->nom,
                // Bandeau : rôle affiché et espace dont le menu s'affiche.
                ...self::roleAffiche($request->user()),
            ] : null,
            'flash' => [
                'succes' => fn () => $request->session()->get('succes'),
            ],
        ];
    }
}
