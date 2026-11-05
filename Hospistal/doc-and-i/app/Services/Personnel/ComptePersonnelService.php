<?php

namespace App\Services\Personnel;

use App\Models\Medecin;
use App\Models\Specialite;
use App\Models\User;
use App\Support\Telephone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Création des comptes du personnel par un administrateur (CPT-05). En
 * attendant l'interface d'administration, appelé par les commandes artisan.
 */
final class ComptePersonnelService
{
    /**
     * Crée le compte (rôle medecin, sans mot de passe) et le profil médecin
     * NON vérifié à l'Ordre : il n'est pas proposé aux patients.
     *
     * @param  array{email: ?string, telephone: ?string, nom: ?string, prenom: ?string, specialite: ?string, ordre: ?string}  $donnees
     *
     * @throws ValidationException
     */
    public function creerMedecin(array $donnees): Medecin
    {
        $donnees['ordre'] = trim((string) ($donnees['ordre'] ?? ''));

        $valides = $this->validerIdentite($donnees, [
            'specialite' => ['required', Rule::exists('specialites', 'slug')->where('active', true)],
            'ordre' => ['required', 'string', 'max:50', Rule::unique('medecins', 'numero_ordre')],
        ]);

        return DB::transaction(function () use ($valides) {
            $user = $this->creerUtilisateur($valides, 'medecin');

            $medecin = new Medecin([
                'specialite_id' => Specialite::where('slug', $valides['specialite'])->value('id'),
                'numero_ordre' => $valides['ordre'],
                'actif' => true,
            ]);
            $medecin->user_id = $user->id;
            $medecin->save();

            return $medecin;
        });
    }

    /**
     * Compte d'un autre rôle du personnel (caisse, support, coordination…),
     * sans mot de passe : la personne l'active par son lien. Le médecin a sa
     * propre création (profil, Ordre) ; le patient s'inscrit lui-même.
     *
     * @param  array{email: ?string, telephone: ?string, nom: ?string, prenom: ?string}  $donnees
     *
     * @throws ValidationException
     */
    public function creerCompte(string $role, array $donnees): User
    {
        if (! in_array($role, self::rolesSansProfil(), true)) {
            throw ValidationException::withMessages([
                'role' => 'Rôle inconnu ou à créer autrement : « '.$role.' » (rôles possibles : '.implode(', ', self::rolesSansProfil()).' ; médecin : avec --specialite et --ordre).',
            ]);
        }

        $valides = $this->validerIdentite($donnees, []);

        return DB::transaction(fn () => $this->creerUtilisateur($valides, $role));
    }

    /** Rôles du personnel créés par creerCompte() : tous sauf patient et médecin. */
    public static function rolesSansProfil(): array
    {
        return array_values(array_diff(config('doc-and-i.roles'), ['patient', 'medecin']));
    }

    /** @throws ValidationException */
    private function validerIdentite(array $donnees, array $reglesEnPlus): array
    {
        $donnees['email'] = mb_strtolower(trim((string) ($donnees['email'] ?? '')));
        $donnees['telephone'] = Telephone::normaliser($donnees['telephone'] ?? null) ?? ($donnees['telephone'] ?? null);

        return Validator::make($donnees, [
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'telephone' => ['required', 'regex:/^\+243[89]\d{8}$/', Rule::unique('users', 'telephone')],
            'nom' => ['required', 'string', 'max:100'],
            'prenom' => ['required', 'string', 'max:100'],
        ] + $reglesEnPlus, [
            'telephone.regex' => 'Le téléphone doit être un numéro mobile de RDC.',
            'telephone.unique' => 'Ce numéro appartient déjà à un compte.',
            'email.unique' => 'Ce courriel appartient déjà à un compte.',
            'ordre.unique' => 'Ce numéro d\'Ordre est déjà enregistré.',
            'specialite.exists' => 'Spécialité inconnue ou inactive.',
        ])->validate();
    }

    private function creerUtilisateur(array $valides, string $role): User
    {
        $user = (new User)->forceFill([
            'email' => $valides['email'],
            'telephone' => $valides['telephone'],
            'nom' => mb_strtoupper(trim($valides['nom'])),
            'prenom' => trim($valides['prenom']),
        ]);
        $user->save();
        $user->assignRole($role);

        return $user;
    }

    /** Pose la date de vérification à l'Ordre ; une date déjà posée est gardée. */
    public function verifierOrdre(string $email): Medecin
    {
        $medecin = Medecin::whereHas('user', fn ($q) => $q->where('email', mb_strtolower(trim($email))))->first();

        if ($medecin === null || ! $medecin->user->hasRole('medecin')) {
            throw new InvalidArgumentException('Aucun médecin n\'a ce courriel.');
        }

        $medecin->ordre_verifie_le ??= now();
        $medecin->save();

        return $medecin;
    }
}
