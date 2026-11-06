<?php

namespace App\Services\Personnel;

use App\Models\AuthentificationPersonnel;
use App\Models\JetonActivation;
use App\Models\User;
use App\Support\CodeQr;
use App\Support\Totp;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Activation d'un compte du personnel (CPT-05, CPT-06) :
 * lien signé à usage unique (72 h) → mot de passe → enrôlement TOTP
 * confirmé par un code valide → codes de récupération.
 *
 * Le lien n'est consommé qu'à la toute fin : un enrôlement abandonné reprend
 * par le même lien, avec un nouveau secret. Tant que l'activation n'est pas
 * terminée, le compte ne peut pas se connecter.
 */
final class ActivationPersonnelService
{
    public function __construct(private readonly DoubleFacteurService $doubleFacteur) {}

    /** Émet un lien d'activation, qui remplace tout lien encore valable du compte. */
    public function emettreLien(User $user): string
    {
        if (! $user->estDuPersonnel()) {
            throw new InvalidArgumentException('Ce compte n\'appartient pas au personnel.');
        }

        if ($user->estDesactive()) {
            throw new InvalidArgumentException('Ce compte est désactivé : le réactiver d\'abord.');
        }

        if (AuthentificationPersonnel::pour($user)?->estActive()) {
            throw new InvalidArgumentException('Ce compte est déjà activé.');
        }

        $clair = Str::random(64);
        $expire = now()->addHours(config('personnel.activation.validite_heures'));

        DB::transaction(function () use ($user, $clair, $expire) {
            JetonActivation::where('user_id', $user->id)
                ->whereNull('utilise_le')
                ->whereNull('invalide_le')
                ->update(['invalide_le' => now()]);

            (new JetonActivation)->forceFill([
                'user_id' => $user->id,
                'empreinte' => self::empreinte($clair),
                'expire_le' => $expire,
            ])->save();
        });

        return URL::temporarySignedRoute('personnel.activation', $expire, ['jeton' => $clair]);
    }

    public function jetonValable(string $clair): ?JetonActivation
    {
        // Un lien émis avant la désactivation du compte ne sert plus.
        return JetonActivation::valables()->where('empreinte', self::empreinte($clair))
            ->whereHas('user', fn ($q) => $q->whereNull('desactive_le'))->first();
    }

    public function jetonParIdentifiant(?string $id): ?JetonActivation
    {
        return $id === null ? null : JetonActivation::valables()->whereKey($id)
            ->whereHas('user', fn ($q) => $q->whereNull('desactive_le'))->first();
    }

    /** Pose le mot de passe et prépare un NOUVEAU secret TOTP, non confirmé. */
    public function definirMotDePasse(JetonActivation $jeton, string $motDePasse): void
    {
        DB::transaction(function () use ($jeton, $motDePasse) {
            $user = User::whereKey($jeton->user_id)->lockForUpdate()->firstOrFail();
            $auth = AuthentificationPersonnel::where('user_id', $user->id)->lockForUpdate()->first()
                ?? (new AuthentificationPersonnel)->forceFill(['user_id' => $user->id]);

            if ($auth->estActive()) {
                throw new InvalidArgumentException('Ce compte est déjà activé.');
            }

            $user->password = $motDePasse;
            $user->save();

            $auth->totp_secret = Totp::genererSecret();
            $auth->totp_dernier_pas = null;
            $auth->save();
        });
    }

    /**
     * Ce qu'il faut pour enrôler l'application : QR code et secret lisible.
     * Null si le mot de passe n'a pas encore été choisi.
     *
     * @return array{qrCode: string, secret: string, email: string}|null
     */
    public function enrolement(JetonActivation $jeton): ?array
    {
        $user = $jeton->user;
        $auth = AuthentificationPersonnel::pour($user);

        if ($user->password === null || $auth?->totp_secret === null || $auth->estActive()) {
            return null;
        }

        return [
            'qrCode' => CodeQr::svgEnDataUri(Totp::uriProvisionnement($auth->totp_secret, config('personnel.totp.emetteur'), $user->email)),
            'secret' => trim(chunk_split($auth->totp_secret, 4, ' ')),
            'email' => $user->email,
        ];
    }

    /**
     * Confirme l'enrôlement par un code valide : compte activé, lien
     * consommé, codes de récupération créés (renvoyés en clair, une fois).
     * Null si le code est faux ou si trop de codes faux ont été essayés.
     *
     * @return list<string>|null
     */
    public function terminer(JetonActivation $jeton, string $code): ?array
    {
        $cle = 'personnel-activation:jeton:'.$jeton->id;

        if (RateLimiter::tooManyAttempts($cle, config('personnel.activation.essais_code_max'))) {
            return null;
        }

        $codes = DB::transaction(function () use ($jeton, $code) {
            $verrouille = JetonActivation::valables()->whereKey($jeton->id)->lockForUpdate()->first();
            $auth = $verrouille ? AuthentificationPersonnel::where('user_id', $verrouille->user_id)->lockForUpdate()->first() : null;

            if ($auth === null || $auth->totp_secret === null || $auth->estActive() || $verrouille->user->password === null) {
                return null;
            }

            $pas = Totp::verifier($auth->totp_secret, $code);

            if ($pas === null) {
                return null;
            }

            $auth->forceFill([
                'totp_dernier_pas' => $pas,
                'active_le' => now(),
                'echecs_consecutifs' => 0,
                'verrouille_jusqu_au' => null,
                'derniere_connexion_le' => now(),
            ])->save();

            $verrouille->utilise_le = now();
            $verrouille->save();

            return $this->doubleFacteur->genererCodesRecuperation($verrouille->user);
        });

        if ($codes === null) {
            RateLimiter::hit($cle, config('personnel.activation.fenetre_essais_minutes') * 60);
        }

        return $codes;
    }

    private static function empreinte(string $clair): string
    {
        return hash('sha256', $clair);
    }
}
