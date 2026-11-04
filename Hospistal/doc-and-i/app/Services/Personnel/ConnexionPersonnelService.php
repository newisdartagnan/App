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
 * - limite par courriel (existant ou non) en plus de la limite par IP des
 *   routes ; l'essai est RÉSERVÉ (incrément atomique) avant la vérification,
 *   pour que des requêtes parallèles ne dépassent pas la limite ;
 * - verrouillage du compte après des échecs consécutifs, mot de passe et
 *   second facteur confondus ;
 * - verrouillage lu, facteur vérifié et échec compté dans UNE transaction,
 *   sous le verrou de la ligne authentifications_personnel (revue de
 *   sécurité : lus séparément, des codes parallèles passaient le verrouillage) ;
 * - un code TOTP ne sert qu'une fois (dernier pas accepté gardé en base).
 */
final class ConnexionPersonnelService
{
    public const ERREUR_IDENTIFIANTS = 'Courriel ou mot de passe incorrect.';

    public const ERREUR_CODE = 'Code incorrect, expiré ou déjà utilisé.';

    public const ERREUR_ACTIVATION = 'Activation non terminée : ouvrez le lien d\'activation reçu, ou demandez-en un nouveau à l\'administrateur.';

    /** Empreinte bcrypt (coût 12) d'un mot de passe aléatoire jeté : sert à égaliser la durée. */
    private const EMPREINTE_FACTICE = '$2y$12$izKVYKCmOb9434PiZr9CFukyXAZKvvAqdnSoVA1NAtw2k/eo4grW6';

    private const ACCEPTE = 'accepte';

    private const REFUSE = 'refuse';

    private const VERROUILLE = 'verrouille';

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

        // Réserver l'essai AVANT de vérifier : l'incrément est atomique, deux
        // requêtes parallèles ne lisent pas le même compteur.
        if (RateLimiter::increment($cle, config('personnel.connexion.verrouillage_minutes') * 60) > config('personnel.connexion.echecs_avant_verrouillage')) {
            $this->refuserTropDeTentatives(RateLimiter::availableIn($cle), 'email');
        }

        $user = User::where('email', $email)->first();
        $user = $user?->estDuPersonnel() ? $user : null;
        $bonMotDePasse = fn () => Hash::check($motDePasse, $user?->password ?: self::EMPREINTE_FACTICE) && $user?->password !== null;

        [$issue, $auth] = $user === null
            ? [$bonMotDePasse() ? self::ACCEPTE : self::REFUSE, null]
            : DB::transaction(function () use ($user, $bonMotDePasse) {
                $auth = AuthentificationPersonnel::where('user_id', $user->id)->lockForUpdate()->first();
                // Toujours le calcul bcrypt : même durée, verrouillé ou non.
                $bon = $bonMotDePasse();

                if ($auth?->estVerrouille()) {
                    return [self::VERROUILLE, $auth];
                }

                if (! $bon) {
                    return [$auth !== null && $this->compterEchecSousVerrou($auth) ? self::VERROUILLE : self::REFUSE, $auth];
                }

                return [self::ACCEPTE, $auth];
            });

        if ($issue === self::VERROUILLE) {
            $this->refuserTropDeTentatives($this->secondesRestantes($auth), 'email');
        }

        if ($issue === self::REFUSE) {
            throw ValidationException::withMessages(['email' => self::ERREUR_IDENTIFIANTS]);
        }

        // Un mot de passe juste ne consomme pas d'essai : la limite compte les échecs.
        RateLimiter::decrement($cle, config('personnel.connexion.verrouillage_minutes') * 60);

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
        $chiffres = preg_replace('/\s+/', '', $saisie);

        // Les exceptions sont levées APRÈS la transaction : un échec compté
        // ne doit pas être annulé par le retour en arrière.
        [$issue, $auth] = DB::transaction(function () use ($user, $saisie, $chiffres) {
            $auth = AuthentificationPersonnel::where('user_id', $user->id)->lockForUpdate()->first();

            if ($auth === null || ! $auth->estActive()) {
                return [self::REFUSE, $auth];
            }

            if ($auth->estVerrouille()) {
                return [self::VERROUILLE, $auth];
            }

            $bon = preg_match('/^\d{6}$/', $chiffres) === 1
                ? $this->doubleFacteur->verifierTotpSousVerrou($auth, $chiffres)
                : $this->doubleFacteur->consommerCodeRecuperation($user, $saisie);

            if (! $bon) {
                return [$this->compterEchecSousVerrou($auth) ? self::VERROUILLE : self::REFUSE, $auth];
            }

            $auth->forceFill([
                'echecs_consecutifs' => 0,
                'verrouille_jusqu_au' => null,
                'derniere_connexion_le' => now(),
            ])->save();

            return [self::ACCEPTE, $auth];
        });

        if ($issue === self::VERROUILLE) {
            $this->refuserTropDeTentatives($this->secondesRestantes($auth), 'code');
        }

        if ($issue === self::REFUSE) {
            throw ValidationException::withMessages(['code' => self::ERREUR_CODE]);
        }

        RateLimiter::clear(self::cleCourriel(self::normaliserEmail((string) $user->email)));
    }

    public static function normaliserEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    /** Le courriel n'apparaît pas en clair dans les clés du cache. */
    public static function cleCourriel(string $email): string
    {
        return 'personnel-connexion:courriel:'.hash('sha256', self::normaliserEmail($email));
    }

    /**
     * Compte un échec sur une ligne lue SOUS VERROU dans la transaction en
     * cours ; vrai si le compte vient d'être verrouillé.
     */
    private function compterEchecSousVerrou(AuthentificationPersonnel $auth): bool
    {
        $auth->echecs_consecutifs++;

        $verrouiller = $auth->echecs_consecutifs >= config('personnel.connexion.echecs_avant_verrouillage');
        if ($verrouiller) {
            $auth->echecs_consecutifs = 0;
            $auth->verrouille_jusqu_au = now()->addMinutes(config('personnel.connexion.verrouillage_minutes'));
            // Identifiant seulement : ni courriel, ni mot de passe dans le journal.
            Log::warning('Compte du personnel verrouillé après des échecs de connexion répétés.', ['user_id' => $auth->user_id]);
        }

        $auth->save();

        return $verrouiller;
    }

    private function secondesRestantes(?AuthentificationPersonnel $auth): int
    {
        return $auth?->verrouille_jusqu_au ? max(1, (int) ceil(now()->diffInSeconds($auth->verrouille_jusqu_au))) : 60;
    }

    /** @throws ValidationException */
    private function refuserTropDeTentatives(int|float $secondes, string $champ): never
    {
        throw ValidationException::withMessages([
            $champ => 'Trop de tentatives. Réessayez dans '.max(1, (int) ceil($secondes / 60)).' minute(s).',
        ]);
    }
}
