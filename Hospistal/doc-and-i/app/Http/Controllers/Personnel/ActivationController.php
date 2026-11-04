<?php

namespace App\Http\Controllers\Personnel;

use App\Http\Controllers\Controller;
use App\Http\Middleware\PersonnelAuthentifie;
use App\Models\JetonActivation;
use App\Rules\MotDePasseRobuste;
use App\Services\Personnel\ActivationPersonnelService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/** CPT-05 / CPT-06 : activation du compte par le lien reçu, mot de passe, enrôlement TOTP. */
class ActivationController extends Controller
{
    private const CLE_JETON = 'personnel.activation.jeton';

    private const CLE_CODES = 'personnel.codes_recuperation';

    public function __construct(private readonly ActivationPersonnelService $activation) {}

    /** Lien signé : le jeton passe en session et quitte l'adresse (historique, en-tête Referer). */
    public function ouvrir(Request $request, string $jeton): Response
    {
        $valable = $this->activation->jetonValable($jeton);

        if ($valable === null) {
            return $this->lienInvalide($request, 410);
        }

        $request->session()->regenerate();
        $request->session()->put(self::CLE_JETON, $valable->id);

        return redirect()->route('personnel.activation.mot-de-passe');
    }

    public function motDePasse(Request $request): Response
    {
        $jeton = $this->jetonEnCours($request);

        if ($jeton === null) {
            return $this->lienInvalide($request, 200);
        }

        return Inertia::render('Personnel/Activation/MotDePasse', [
            'email' => $jeton->user->email,
            'longueurMin' => config('personnel.mot_de_passe.longueur_min'),
        ])->toResponse($request);
    }

    public function enregistrerMotDePasse(Request $request): RedirectResponse
    {
        $jeton = $this->jetonEnCours($request);

        if ($jeton === null) {
            return redirect()->route('personnel.activation.mot-de-passe');
        }

        $request->validate([
            'password' => [
                'required', 'string', 'confirmed',
                'min:'.config('personnel.mot_de_passe.longueur_min'),
                'max:'.config('personnel.mot_de_passe.longueur_max'),
                new MotDePasseRobuste($jeton->user->email),
            ],
        ]);

        $this->activation->definirMotDePasse($jeton, $request->input('password'));

        return redirect()->route('personnel.activation.double-facteur');
    }

    public function doubleFacteur(Request $request): Response
    {
        $jeton = $this->jetonEnCours($request);
        $enrolement = $jeton ? $this->activation->enrolement($jeton) : null;

        if ($enrolement === null) {
            return redirect()->route('personnel.activation.mot-de-passe');
        }

        return $this->sansCache(Inertia::render('Personnel/Activation/DoubleFacteur', $enrolement)->toResponse($request));
    }

    public function confirmerDoubleFacteur(Request $request): RedirectResponse
    {
        $jeton = $this->jetonEnCours($request);

        if ($jeton === null) {
            return redirect()->route('personnel.activation.mot-de-passe');
        }

        $request->validate(['code' => ['required', 'string', 'max:10']]);

        $codes = $this->activation->terminer($jeton, preg_replace('/\s+/', '', (string) $request->input('code')));

        if ($codes === null) {
            throw ValidationException::withMessages([
                'code' => 'Code incorrect. Vérifiez que l\'heure du téléphone est juste, puis saisissez le code affiché.',
            ]);
        }

        // Session neuve : rien de la session d'avant ne survit à la connexion.
        $request->session()->invalidate();
        Auth::login($jeton->user);
        $request->session()->put(PersonnelAuthentifie::CLE_AUTHENTIFIE, $jeton->user_id);
        $request->session()->put(PersonnelAuthentifie::CLE_ACTIVITE, now()->getTimestamp());
        $request->session()->put(self::CLE_CODES, $codes);

        return redirect()->route('personnel.codes-recuperation');
    }

    /** Montrés une seule fois : retirés de la session dès cet affichage. */
    public function codesRecuperation(Request $request): Response
    {
        $codes = $request->session()->pull(self::CLE_CODES);

        if (! is_array($codes)) {
            return redirect()->route('personnel.accueil');
        }

        return $this->sansCache(Inertia::render('Personnel/Activation/CodesRecuperation', ['codes' => $codes])->toResponse($request));
    }

    private function jetonEnCours(Request $request): ?JetonActivation
    {
        return $this->activation->jetonParIdentifiant($request->session()->get(self::CLE_JETON));
    }

    private function lienInvalide(Request $request, int $statut): Response
    {
        return Inertia::render('Personnel/Activation/LienInvalide')->toResponse($request)->setStatusCode($statut);
    }

    /** Secret TOTP et codes de récupération : jamais dans un cache du navigateur ou d'un proxy. */
    private function sansCache(Response $reponse): Response
    {
        $reponse->headers->set('Cache-Control', 'no-store, private');

        return $reponse;
    }
}
