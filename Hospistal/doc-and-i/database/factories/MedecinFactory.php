<?php

namespace Database\Factories;

use App\Models\Medecin;
use App\Models\Specialite;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

/**
 * @extends Factory<Medecin>
 */
class MedecinFactory extends Factory
{
    /** Médecin vérifié à l'Ordre, actif, avec un compte au rôle « medecin ». Données fictives. */
    public function definition(): array
    {
        return [
            'user_id' => fn () => tap(
                User::factory()->sansRole()->create([
                    'email' => fake()->unique()->safeEmail(),
                    'password' => 'mot-de-passe-de-test',
                ]),
                fn (User $u) => $u->assignRole(Role::findOrCreate('medecin', 'web'))
            )->id,
            'specialite_id' => fn () => Specialite::firstOrCreate(
                ['slug' => 'medecine-generale'],
                ['nom' => 'Médecine générale', 'ordre' => 1]
            )->id,
            'numero_ordre' => 'CNOM-'.Str::upper(Str::random(8)),
            'presentation' => null,
            'langues' => ['fr', 'ln'],
            'actif' => true,
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (Medecin $medecin) {
            $medecin->ordre_verifie_le ??= now();
        });
    }

    public function nonVerifie(): static
    {
        return $this->afterMaking(fn (Medecin $m) => $m->ordre_verifie_le = null)
            ->afterCreating(fn (Medecin $m) => $m->forceFill(['ordre_verifie_le' => null])->save());
    }
}
