<?php

namespace App\Services;

use App\Models\CodeConnexion;
use App\Sms\EnvoyeurSms;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Connexion par téléphone (CPT-01) : un code à usage unique envoyé par SMS.
 *
 * Règles :
 * - le code n'est jamais stocké en clair, seulement son empreinte HMAC ;
 * - il expire après 10 minutes et ne sert qu'une fois ;
 * - 5 essais faux l'invalident, même si le bon arrive ensuite ;
 * - une nouvelle demande invalide le code précédent ;
 * - les demandes sont limitées par numéro et par adresse IP, pour qu'on ne
 *   puisse ni inonder un patient de SMS ni vider le crédit d'envoi.
 */
final class CodeConnexionService
{
    public function __construct(private readonly EnvoyeurSms $sms) {}

    /**
     * Envoie un code au numéro (déjà normalisé). Renvoie le nombre de secondes
     * à attendre si la limite est atteinte, null si le code est parti.
     */
    public function demander(string $telephone, ?string $ip): ?int
    {
        $regles = config('doc-and-i.code_connexion');
        $cleNumero = 'code-connexion:numero:'.$telephone;
        $cleIp = 'code-connexion:ip:'.($ip ?? 'inconnue');

        foreach ([[$cleNumero, $regles['demandes_par_numero']], [$cleIp, $regles['demandes_par_ip']]] as [$cle, $max]) {
            if (RateLimiter::tooManyAttempts($cle, $max)) {
                return RateLimiter::availableIn($cle);
            }
        }

        RateLimiter::hit($cleNumero, $regles['fenetre_numero_minutes'] * 60);
        RateLimiter::hit($cleIp, $regles['fenetre_ip_minutes'] * 60);

        $code = str_pad((string) random_int(0, 10 ** $regles['chiffres'] - 1), $regles['chiffres'], '0', STR_PAD_LEFT);

        DB::transaction(function () use ($telephone, $code, $ip, $regles) {
            CodeConnexion::where('telephone', $telephone)
                ->whereNull('utilise_le')
                ->whereNull('invalide_le')
                ->update(['invalide_le' => now()]);

            CodeConnexion::create([
                'telephone' => $telephone,
                'empreinte' => $this->empreinte($telephone, $code),
                'expire_le' => now()->addMinutes($regles['validite_minutes']),
                'ip' => $ip,
            ]);
        });

        $this->sms->envoyer($telephone, sprintf(
            'Doc&I : votre code de connexion est %s. Il expire dans %d minutes. Ne le communiquez a personne.',
            $code,
            $regles['validite_minutes']
        ));

        return null;
    }

    /** Vrai si le code est le bon, encore valable et jamais utilisé ; il est alors consommé. */
    public function verifier(string $telephone, string $code): bool
    {
        $essaisMax = config('doc-and-i.code_connexion.essais_max');

        return DB::transaction(function () use ($telephone, $code, $essaisMax) {
            // Verrou : deux envois simultanés du même code ne connectent qu'une fois.
            $actif = CodeConnexion::where('telephone', $telephone)
                ->whereNull('utilise_le')
                ->whereNull('invalide_le')
                ->where('expire_le', '>', now())
                ->latest()
                ->lockForUpdate()
                ->first();

            if ($actif === null) {
                return false;
            }

            $actif->essais++;

            if (hash_equals($actif->empreinte, $this->empreinte($telephone, $code))) {
                $actif->utilise_le = now();
                $actif->save();

                return true;
            }

            if ($actif->essais >= $essaisMax) {
                $actif->invalide_le = now();
            }

            $actif->save();

            return false;
        });
    }

    private function empreinte(string $telephone, string $code): string
    {
        return hash_hmac('sha256', $telephone.'|'.$code, (string) config('app.key'));
    }
}
