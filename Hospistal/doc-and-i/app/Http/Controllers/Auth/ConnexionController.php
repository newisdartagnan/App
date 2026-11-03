<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\CodeConnexionService;
use App\Support\Telephone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** CPT-01 : inscription et connexion par numéro de téléphone et code SMS. */
class ConnexionController extends Controller
{
    public function __construct(private readonly CodeConnexionService $codes) {}

    public function create(): Response
    {
        return Inertia::render('Connexion/Telephone');
    }

    public function demander(Request $request): RedirectResponse
    {
        $request->validate(['telephone' => ['required', 'string', 'max:30']]);

        $telephone = Telephone::normaliser($request->input('telephone'));

        if ($telephone === null) {
            throw ValidationException::withMessages([
                'telephone' => 'Ce numéro n\'est pas un numéro mobile de RDC (exemple : 081 234 56 78).',
            ]);
        }

        $attente = $this->codes->demander($telephone, $request->ip());

        if ($attente !== null) {
            throw ValidationException::withMessages([
                'telephone' => 'Trop de demandes de code. Réessayez dans '.max(1, (int) ceil($attente / 60)).' minute(s).',
            ]);
        }

        $request->session()->put('connexion.telephone', $telephone);

        return redirect()->route('connexion.code');
    }

    /** Nouveau code au même numéro, gardé en session : le patient ne le retape pas. */
    public function renvoyer(Request $request): RedirectResponse
    {
        $telephone = $request->session()->get('connexion.telephone');

        if ($telephone === null) {
            return redirect()->route('connexion');
        }

        $attente = $this->codes->demander($telephone, $request->ip());

        if ($attente !== null) {
            throw ValidationException::withMessages([
                'telephone' => 'Trop de demandes de code. Réessayez dans '.max(1, (int) ceil($attente / 60)).' minute(s).',
            ]);
        }

        return redirect()->route('connexion.code')->with('succes', 'Un nouveau code vient de partir.');
    }

    public function code(Request $request): Response|RedirectResponse
    {
        $telephone = $request->session()->get('connexion.telephone');

        if ($telephone === null) {
            return redirect()->route('connexion');
        }

        return Inertia::render('Connexion/Code', [
            'telephone' => Telephone::masquer($telephone),
            'validiteMinutes' => config('doc-and-i.code_connexion.validite_minutes'),
        ]);
    }

    public function verifier(Request $request): RedirectResponse
    {
        $telephone = $request->session()->get('connexion.telephone');

        if ($telephone === null) {
            return redirect()->route('connexion');
        }

        $request->validate(['code' => ['required', 'string', 'max:10']]);

        $code = preg_replace('/\D/', '', (string) $request->input('code'));

        // Une seule transaction : si la création du compte ou de son rôle
        // échoue, le code n'est pas consommé et aucun compte sans rôle ne reste.
        $patient = DB::transaction(function () use ($telephone, $code) {
            if (! $this->codes->verifier($telephone, $code)) {
                return null;
            }

            // Le téléphone n'est pas « fillable » (aucun formulaire ne doit le
            // changer) : il n'est posé qu'ici, une fois le code vérifié.
            $patient = User::where('telephone', $telephone)->lockForUpdate()->first()
                ?? (new User)->forceFill(['telephone' => $telephone]);

            // Un compte du personnel ne se connecte jamais par code SMS seul :
            // ce serait contourner sa double authentification (CPT-06).
            if ($patient->exists && $patient->estDuPersonnel()) {
                return false;
            }
            $patient->telephone_verifie_le ??= now();
            $patient->save();

            if ($patient->roles()->doesntExist()) {
                $patient->assignRole('patient');
            }

            return $patient;
        });

        if ($patient === false) {
            throw ValidationException::withMessages([
                'code' => 'Ce numéro appartient à un compte du personnel : connectez-vous par l\'espace du personnel.',
            ]);
        }

        if ($patient === null) {
            throw ValidationException::withMessages([
                'code' => $this->codes->estBloque($telephone)
                    ? 'Trop de codes incorrects pour ce numéro. Réessayez demain ou contactez le support.'
                    : 'Code incorrect ou expiré. Vérifiez le SMS, ou demandez un nouveau code.',
            ]);
        }

        $request->session()->forget('connexion.telephone');
        Auth::login($patient);
        // Nouvel identifiant de session : une session dérobée avant la
        // connexion ne donne pas accès au compte.
        $request->session()->regenerate();

        return redirect()->intended(route('espace'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('accueil');
    }
}
