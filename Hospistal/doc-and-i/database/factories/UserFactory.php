<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Spatie\Permission\Models\Role;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /** Patient au profil complet, numéro vérifié. Données fictives uniquement. */
    public function definition(): array
    {
        return [
            'telephone' => '+2438'.fake()->unique()->numerify('########'),
            'telephone_verifie_le' => now(),
            'nom' => mb_strtoupper(fake()->lastName()),
            'postnom' => null,
            'prenom' => fake()->firstName(),
            'sexe' => fake()->randomElement(['M', 'F']),
            'date_naissance' => fake()->dateTimeBetween('-80 years', '-18 years')->format('Y-m-d'),
            'commune' => 'Gombe',
            'langue' => 'fr',
        ];
    }

    /** Par défaut un patient (rôle créé au besoin) : c'est ce que produit l'inscription par téléphone. */
    public function configure(): static
    {
        return $this->afterCreating(fn (User $user) => $user->assignRole(Role::findOrCreate('patient', 'web')));
    }

    /** Aucun rôle (comptes du personnel, qui reçoivent ensuite le leur ; cas d'erreur). */
    public function sansRole(): static
    {
        // Les rappels afterCreating s'exécutent dans l'ordre : celui-ci passe après configure().
        return $this->afterCreating(fn (User $user) => $user->syncRoles([]));
    }

    /** Juste inscrit : rien d'autre que le numéro. */
    public function sansProfil(): static
    {
        return $this->state(fn () => [
            'nom' => null, 'prenom' => null, 'sexe' => null, 'date_naissance' => null, 'commune' => null,
        ]);
    }
}
