<?php

namespace App\Services\Personnel;

use App\Models\AuthentificationPersonnel;
use App\Models\CodeRecuperation;
use App\Models\User;
use App\Support\Totp;
use Illuminate\Support\Facades\DB;

/**
 * Second facteur du personnel (CPT-06) : code TOTP de l'application
 * d'authentification, ou code de récupération à usage unique.
 */
final class DoubleFacteurService
{
    /** Sans 0/O ni 1/I/L : lisible recopié à la main. */
    private const ALPHABET_RECUPERATION = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    /**
     * Vrai si le code TOTP est bon ET plus récent que le dernier accepté.
     * Sous verrou : deux envois simultanés du même code n'en font passer qu'un.
     */
    public function verifierTotp(AuthentificationPersonnel $auth, string $code): bool
    {
        return DB::transaction(function () use ($auth, $code) {
            $verrouillee = AuthentificationPersonnel::whereKey($auth->id)->lockForUpdate()->firstOrFail();

            if ($verrouillee->totp_secret === null) {
                return false;
            }

            $pas = Totp::verifier($verrouillee->totp_secret, $code, $verrouillee->totp_dernier_pas);

            if ($pas === null) {
                return false;
            }

            $verrouillee->totp_dernier_pas = $pas;
            $verrouillee->save();
            $auth->setRawAttributes($verrouillee->getAttributes(), true);

            return true;
        });
    }

    /**
     * Remplace les codes de récupération du compte et renvoie les nouveaux,
     * en clair, pour un affichage unique. Seules leurs empreintes restent.
     *
     * @return list<string>
     */
    public function genererCodesRecuperation(User $user): array
    {
        $codes = [];
        while (count($codes) < config('personnel.codes_recuperation.nombre')) {
            $brut = '';
            for ($i = 0; $i < 10; $i++) {
                $brut .= self::ALPHABET_RECUPERATION[random_int(0, strlen(self::ALPHABET_RECUPERATION) - 1)];
            }
            $codes[$brut] = substr($brut, 0, 5).'-'.substr($brut, 5);
        }

        DB::transaction(function () use ($user, $codes) {
            CodeRecuperation::where('user_id', $user->id)->delete();

            foreach (array_keys($codes) as $brut) {
                (new CodeRecuperation)->forceFill([
                    'user_id' => $user->id,
                    'empreinte' => $this->empreinte($brut),
                ])->save();
            }
        });

        return array_values($codes);
    }

    /** Vrai si le code de récupération est valable ; il est alors consommé. */
    public function consommerCodeRecuperation(User $user, string $saisie): bool
    {
        $brut = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $saisie));

        if (strlen($brut) !== 10) {
            return false;
        }

        return DB::transaction(function () use ($user, $brut) {
            $code = CodeRecuperation::where('user_id', $user->id)
                ->where('empreinte', $this->empreinte($brut))
                ->whereNull('utilise_le')
                ->lockForUpdate()
                ->first();

            if ($code === null) {
                return false;
            }

            $code->utilise_le = now();
            $code->save();

            return true;
        });
    }

    public function codesRestants(User $user): int
    {
        return CodeRecuperation::where('user_id', $user->id)->whereNull('utilise_le')->count();
    }

    /** Les codes ont 50 bits d'aléa : une empreinte HMAC suffit et permet la recherche directe. */
    private function empreinte(string $brut): string
    {
        return hash_hmac('sha256', 'recuperation|'.$brut, (string) config('app.key'));
    }
}
