<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Mot de passe du personnel (CPT-06). Password::uncompromised() interroge un
 * service en ligne (Have I Been Pwned) : ce n'est pas fiable depuis Kinshasa
 * et cela ferait sortir une partie de l'empreinte du mot de passe. On s'en
 * tient donc à des règles locales : longueur (dans le validateur), et refus
 * des mots de passe trop courants ou construits sur le courriel.
 */
final class MotDePasseRobuste implements ValidationRule
{
    /** Mots qui, une fois chiffres et signes retirés, rendent le mot de passe devinable. */
    private const MOTS_COURANTS = [
        'motdepasse', 'password', 'passw', 'azerty', 'azertyuiop', 'qwerty', 'qwertyuiop',
        'abcdef', 'abcdefgh', 'abc', 'docandi', 'docetmoi', 'kinshasa', 'congo', 'rdc', 'bienvenue',
        'welcome', 'medecin', 'docteur', 'doctor', 'admin', 'administrateur', 'utilisateur',
        'soleil', 'jetaime', 'iloveyou', 'secret', 'changeme', 'letmein', 'monkey', 'dragon',
        'football', 'baseball', 'master', 'hopital', 'sante', 'clinique',
    ];

    public function __construct(private readonly ?string $email = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $valeur = mb_strtolower((string) $value);
        $lettres = preg_replace('/[^a-z]/', '', $valeur);

        $faible = $lettres === ''
            || in_array($lettres, self::MOTS_COURANTS, true)
            || count(array_unique(mb_str_split($valeur))) < 5;

        if ($this->email !== null && $this->email !== '') {
            $email = mb_strtolower($this->email);
            $local = strstr($email, '@', true) ?: $email;
            $faible = $faible || str_contains($valeur, $email)
                || (mb_strlen($local) >= 4 && str_contains($valeur, $local));
        }

        if ($faible) {
            $fail('Ce mot de passe est trop courant ou trop facile à deviner : choisissez une phrase à vous, de plusieurs mots.');
        }
    }
}
