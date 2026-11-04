<?php

namespace App\Services;

use App\Models\CodeConnexion;
use App\Sms\EnvoyeurSms;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Connexion par téléphone (CPT-01) : un code à usage unique envoyé par SMS.
 *
 * Règles :
 * - le code n'est jamais stocké en clair, seulement son empreinte HMAC ;
 * - il expire après 10 minutes et ne sert qu'une fois ;
 * - 5 essais faux l'invalident, même si le bon arrive ensuite ;
 * - un seul code actif par numéro (index unique partiel en base) : une
 *   nouvelle demande invalide le précédent ;
 * - les codes faux se cumulent par numéro sur 24 h, tous codes confondus :
 *   redemander un code ne redonne pas de nouveaux essais au-delà du plafond ;
 * - les demandes sont limitées par numéro (rafale et jour), par adresse IP
 *   et globalement, pour qu'on ne puisse ni inonder un patient de SMS ni
 *   vider le crédit d'envoi ;
 * - le contrôle de limite et l'enregistrement de la demande sont atomiques :
 *   des requêtes parallèles ne passent pas à travers (revue de sécurité).
 */
final class CodeConnexionService
{
    public function __construct(private readonly EnvoyeurSms $sms) {}

    /**
     * Envoie un code au numéro (déjà normalisé). Renvoie le nombre de secondes
     * à attendre si une limite est atteinte, null si le code est parti.
     */
    public function demander(string $telephone, ?string $ip): ?int
    {
        // Un verrou par numéro sérialise les demandes parallèles : sans lui,
        // quinze requêtes simultanées envoyaient sept codes au lieu de trois.
        return Cache::lock('code-connexion:demande:'.$telephone, 10)
            ->block(5, fn () => $this->demanderSousVerrou($telephone, $ip));
    }

    private function demanderSousVerrou(string $telephone, ?string $ip): ?int
    {
        $r = config('doc-and-i.code_connexion');

        $limites = [
            [$this->cleEchecs($telephone), $r['echecs_par_numero_par_jour'], null],
            ['code-connexion:numero:'.$telephone, $r['demandes_par_numero'], $r['fenetre_numero_minutes'] * 60],
            ['code-connexion:numero-jour:'.$telephone, $r['demandes_par_numero_par_jour'], 86400],
            ['code-connexion:ip:'.self::cleIp($ip), $r['demandes_par_ip'], $r['fenetre_ip_minutes'] * 60],
            ['code-connexion:global', $r['sms_par_heure'], 3600],
        ];

        foreach ($limites as [$cle, $max]) {
            if (RateLimiter::tooManyAttempts($cle, $max)) {
                return RateLimiter::availableIn($cle);
            }
        }

        foreach ($limites as [$cle, , $duree]) {
            if ($duree !== null) {
                RateLimiter::hit($cle, $duree);
            }
        }

        $code = str_pad((string) random_int(0, 10 ** $r['chiffres'] - 1), $r['chiffres'], '0', STR_PAD_LEFT);

        DB::transaction(function () use ($telephone, $code, $ip, $r) {
            // Verrou de transaction PostgreSQL sur le numéro : deuxième
            // barrière, même si le cache n'est pas partagé entre serveurs.
            DB::select('select pg_advisory_xact_lock(hashtext(?))', [$telephone]);

            CodeConnexion::where('telephone', $telephone)
                ->whereNull('utilise_le')
                ->whereNull('invalide_le')
                ->update(['invalide_le' => now()]);

            CodeConnexion::create([
                'telephone' => $telephone,
                'empreinte' => $this->empreinte($telephone, $code),
                'expire_le' => now()->addMinutes($r['validite_minutes']),
                'ip' => $ip,
            ]);
        });

        $this->sms->envoyer($telephone, sprintf(
            'MEDIHOME : votre code de connexion est %s. Il expire dans %d minutes. Ne le communiquez a personne.',
            $code,
            $r['validite_minutes']
        ));

        return null;
    }

    /** Vrai si le code est le bon, encore valable et jamais utilisé ; il est alors consommé. */
    public function verifier(string $telephone, string $code): bool
    {
        if ($this->estBloque($telephone)) {
            return false;
        }

        $essaisMax = config('doc-and-i.code_connexion.essais_max');

        $bon = DB::transaction(function () use ($telephone, $code, $essaisMax) {
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

        if (! $bon) {
            RateLimiter::hit($this->cleEchecs($telephone), 86400);
        }

        return $bon;
    }

    /** Trop de codes faux sur 24 h : ce numéro ne peut plus se connecter ni redemander de code. */
    public function estBloque(string $telephone): bool
    {
        return RateLimiter::tooManyAttempts(
            $this->cleEchecs($telephone),
            config('doc-and-i.code_connexion.echecs_par_numero_par_jour')
        );
    }

    /** IPv4 telle quelle ; IPv6 regroupée par /64 (un abonné dispose de tout le bloc). */
    public static function cleIp(?string $ip): string
    {
        if ($ip === null || $ip === '') {
            return 'inconnue';
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $binaire = inet_pton($ip);

            return bin2hex(substr($binaire, 0, 8)).'::/64';
        }

        return $ip;
    }

    private function cleEchecs(string $telephone): string
    {
        return 'code-connexion:echecs:'.$telephone;
    }

    private function empreinte(string $telephone, string $code): string
    {
        return hash_hmac('sha256', $telephone.'|'.$code, (string) config('app.key'));
    }
}
