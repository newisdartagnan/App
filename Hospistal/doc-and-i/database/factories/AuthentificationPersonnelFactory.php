<?php

namespace Database\Factories;

use App\Models\AuthentificationPersonnel;
use App\Models\User;
use App\Support\Totp;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AuthentificationPersonnel>
 */
class AuthentificationPersonnelFactory extends Factory
{
    /** Activation terminée : second facteur confirmé. */
    public function definition(): array
    {
        return [
            'user_id' => fn () => User::factory()->sansRole(),
            'totp_secret' => Totp::genererSecret(),
            'active_le' => now(),
        ];
    }

    /** Mot de passe choisi, second facteur pas encore confirmé. */
    public function enCoursDActivation(): static
    {
        return $this->state(fn () => ['active_le' => null]);
    }
}
