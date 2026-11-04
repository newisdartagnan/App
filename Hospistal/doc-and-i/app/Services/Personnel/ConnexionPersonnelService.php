<?php

namespace App\Services\Personnel;

use App\Models\AuthentificationPersonnel;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Connexion du personnel (CPT-06) : courriel + mot de passe, PUIS code TOTP
 * ou code de récupération. Un SMS ne suffit jamais.
 *
 * Règles :
 * - même message que le courriel existe ou non, même durée (un calcul bcrypt
 *   est toujours fait) : pas d'énumération des comptes ;
 * - limite par courriel (existant ou non) en plus de la limite par IP des routes ;
 * - verrouillage du compte après des échecs consécutifs, mot de passe et
 *   second facteur confondus ;
 * - un code TOTP ne sert qu'une fois (dernier pas accepté gardé en base).
 */
final class ConnexionPersonnelService
{
    public const ERREUR_IDENTIFIANTS = 'Courriel ou mot de passe incorrect.';

    public const ERREUR_CODE = 'Code incorrect, expiré ou déjà utilisé.';

    public const ERREUR_ACTIVATION = 'Activation non terminée : ouvrez le lien d\'activation reçu, ou demandez-en un nouveau à l\'administrateur.';

    /** Empreinte bcrypt (coût 12) d'un mot de passe aléatoire jeté : sert à égaliser la durée. */
    private const EMPREINTE_FACTICE = '$2y$12$izKVYKCmOb9434PiZr9CFukyXAZKvvAqdnSoVA1NAtw2k/eo4grW6';

    public function __construct(private readonly DoubleFacteurService $doubleFacteur) {}

    /**
     * Première étape. Renvoie le compte si le mot de passe est bon ; le
     * second facteur reste à vérifier, le compte n'est PAS encore connecté.
     *
     * @throws ValidationException
     */
    public function verifierIdentifiants(string $email, string $motDePasse): User
    {
        $email = self::normaliserEmail($email);
        $cle = self::cleCourriel($email);

        if (RateLimiter::tooManyAttempts($cle, config('personnel.connexion.echecs_avant_verrouillage'))) {
            $this->refuserTropDeTentatives(RateLimiter::availableIn($cle), 'email');
        }

        $user = User::where('email', $email)->first();
        $user = $user?->estDuPersonnel() ? $user : null;
        $auth = $user ? AuthentificationPersonnel::pour($user) : null;

        $bon = Hash::check($motDePasse, $user?->password ?: self::EMPREINTE_FACTICE) && $user?->password !== null;

        if ($auth?->estVerrouille()) {
            $this->refuserTropDeTentatives(now()->diffInSeconds($auth->verrouille_jusqu_au), 'email');
        }

        if (! $bon) {
            RateLimiter::hit($cle, config('personnel.connexion.verrouillage_minutes') * 60);
            if ($auth !== null) {
                $this->enregistrerEchec($auth);
            }

            throw ValidationException::withMessages(['email' => self::ERREUR_IDENTIFIANTS]);
        }

        if ($auth === null || ! $auth->estActive()) {
            throw ValidationException::withMessages(['email' => self::ERREUR_ACTIVATION]);
        }

        return $user;
    }

    /**
     * Seconde étape : code TOTP à 6 chiffres, ou code de récupération (consommé).
     *
     * @throws ValidationException
     */
    public function verifierSecondFacteur(User $user, string $saisie): void
    {
        $auth = AuthentificationPersonnel::pour($user);

        if ($auth === null || ! $auth->estActive()) {
            throw ValidationException::withMessages(['code' => self::ERREUR_CODE]);
        }

        if ($auth->estVerrouille()) {
            $this->refuserTropDeTentatives(now()->diffInSeconds($auth->verrouille_jusqu_au), 'code');
        }

        $chiffres = preg_replace('/\s+/', '', $saisie);
        $bon = preg_match('/^\d{6}$/', $chiffres) === 1
            ? $this->doubleFacteur->verifierTotp($auth, $chiffres)
            : $this->doubleFacteur->consommerCodeRecuperation($user, $saisie);

        if (! $bon) {
            if ($this->enregistrerEchec($auth)) {
                $this->refuserTropDeTentatives(config('personnel.connexion.verrouillage_minutes') * 60, 'code');
            }

            throw ValidationException::withMessages(['code' => self::ERREUR_CODE]);
        }

        $auth->forceFill([
            'echecs_consecutifs' => 0,
            'verrouille_jusqu_au' => null,
            'derniere_connexion_le' => now(),
        ])->save();
        RateLimiter::clear(self::cleCourriel(self::normaliserEmail((string) $user->email)));
    }

    public static function normaliserEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    /** Le courriel n'apparaît pas en clair dans les clés du cache. */
    private static function cleCourriel(string $email): string
    {
        return 'personnel-connexion:courriel:'.hash('sha256', $email);
    }

    /** Compte un échec ; vrai si le compte vient d'être verrouillé. */
    private function enregistrerEchec(AuthentificationPersonnel $auth): bool
    {
        return DB::transaction(function () use ($auth) {
            $verrouillee = AuthentificationPersonnel::whereKey($auth->id)->lockForUpdate()->firstOrFail();
            $verrouillee->echecs_consecutifs++;

            $verrouiller = $verrouillee->echecs_consecutifs >= config('personnel.connexion.echecs_avant_verrouillage');
            if ($verrouiller) {
                $verrouillee->echecs_consecutifs = 0;
                $verrouillee->verrouille_jusqu_au = now()->addMinutes(config('personnel.connexion.verrouillage_minutes'));
                // Identifiant seulement : ni courriel, ni mot de passe dans le journal.
                Log::warning('Compte du personnel verrouillé après des échecs de connexion répétés.', ['user_id' => $verrouillee->user_id]);
            }

            $verrouillee->save();
            $auth->setRawAttributes($verrouillee->getAttributes(), true);

            return $verrouiller;
        });
    }

    /** @throws ValidationException */
    private function refuserTropDeTentatives(int|float $secondes, string $champ): never
    {
        throw ValidationException::withMessages([
            $champ => 'Trop de tentatives. Réessayez dans '.max(1, (int) ceil($secondes / 60)).' minute(s).',
        ]);
    }
}
