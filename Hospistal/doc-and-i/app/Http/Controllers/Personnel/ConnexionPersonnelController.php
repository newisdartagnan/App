<?php

namespace App\Http\Controllers\Personnel;

use App\Http\Controllers\Controller;
use App\Http\Middleware\PersonnelAuthentifie;
use App\Models\User;
use App\Services\Personnel\ConnexionPersonnelService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/** CPT-06 : connexion du personnel en deux étapes, puis déconnexion. */
class ConnexionPersonnelController extends Controller
{
    /** Compte dont le mot de passe est vérifié, en attente du second facteur. */
    private const CLE_EN_ATTENTE = 'personnel.connexion.en_attente';

    public function __construct(private readonly ConnexionPersonnelService $connexion) {}

    public function create(): Response
    {
        return Inertia::render('Personnel/Connexion/Identifiants');
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'email' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
        ]);

        $user = $this->connexion->verifierIdentifiants($request->input('email'), $request->input('password'));

        $request->session()->put(self::CLE_EN_ATTENTE, [
            'user_id' => $user->id,
            'expire' => now()->addMinutes(config('personnel.connexion.etape_code_minutes'))->getTimestamp(),
        ]);

        return redirect()->route('personnel.connexion.code');
    }

    public function code(Request $request): Response|RedirectResponse
    {
        if ($this->compteEnAttente($request) === null) {
            return redirect()->route('personnel.connexion');
        }

        return Inertia::render('Personnel/Connexion/Code');
    }

    public function verifierCode(Request $request): RedirectResponse
    {
        $user = $this->compteEnAttente($request);

        if ($user === null) {
            return redirect()->route('personnel.connexion')
                ->withErrors(['email' => 'Délai dépassé : saisissez de nouveau votre courriel et votre mot de passe.']);
        }

        $request->validate(['code' => ['required', 'string', 'max:20']]);

        $this->connexion->verifierSecondFacteur($user, $request->input('code'));

        $request->session()->forget(self::CLE_EN_ATTENTE);
        Auth::login($user);
        // Nouvel identifiant de session : une session dérobée avant la
        // connexion ne donne pas accès au compte.
        $request->session()->regenerate();
        $request->session()->put(PersonnelAuthentifie::CLE_AUTHENTIFIE, $user->id);
        $request->session()->put(PersonnelAuthentifie::CLE_ACTIVITE, now()->getTimestamp());

        // Retour à la page demandée, seulement si elle est dans l'espace du personnel.
        $demandee = $request->session()->pull('url.intended');
        $espace = url('/personnel');

        return redirect()->to(
            is_string($demandee) && ($demandee === $espace || str_starts_with($demandee, $espace.'/'))
                ? $demandee
                : route('personnel.accueil')
        );
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        // Après la session neuve : la page suivante efface l'historique chiffré.
        Inertia::clearHistory();

        return redirect()->route('personnel.connexion');
    }

    private function compteEnAttente(Request $request): ?User
    {
        $attente = $request->session()->get(self::CLE_EN_ATTENTE);

        if (! is_array($attente) || ($attente['expire'] ?? 0) < now()->getTimestamp()) {
            $request->session()->forget(self::CLE_EN_ATTENTE);

            return null;
        }

        return User::find($attente['user_id']);
    }
}
