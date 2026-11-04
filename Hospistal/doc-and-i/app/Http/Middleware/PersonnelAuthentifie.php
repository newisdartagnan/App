<?php

namespace App\Http\Middleware;

use App\Models\AuthentificationPersonnel;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Alias « personnel » (CPT-06). Une session du personnel n'est admise que si :
 * - le compte est connecté ET porte un rôle du personnel (sinon 403) ;
 * - son activation est terminée (sinon il ne voit que l'activation) ;
 * - le second facteur a été validé DANS CETTE SESSION, pour CE compte ;
 * - la session n'est pas restée inactive trop longtemps.
 */
class PersonnelAuthentifie
{
    public const CLE_AUTHENTIFIE = 'personnel.authentifie';

    public const CLE_ACTIVITE = 'personnel.derniere_activite';

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return redirect()->guest(route('personnel.connexion'));
        }

        if (! $user->estDuPersonnel()) {
            abort(403);
        }

        if (! AuthentificationPersonnel::pour($user)?->estActive()) {
            $this->fermer($request);

            return redirect()->route('personnel.activation.mot-de-passe');
        }

        if ($request->session()->get(self::CLE_AUTHENTIFIE) !== $user->id) {
            $this->fermer($request);

            return redirect()->guest(route('personnel.connexion'));
        }

        $derniere = $request->session()->get(self::CLE_ACTIVITE);
        if (! is_int($derniere) || now()->getTimestamp() - $derniere > config('personnel.session.inactivite_minutes') * 60) {
            $this->fermer($request);

            return redirect()->guest(route('personnel.connexion'))
                ->with('succes', 'Session fermée après '.config('personnel.session.inactivite_minutes').' minutes d\'inactivité. Reconnectez-vous.');
        }

        $request->session()->put(self::CLE_ACTIVITE, now()->getTimestamp());

        return $next($request);
    }

    private function fermer(Request $request): void
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }
}
