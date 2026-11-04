<?php

namespace Tests\Support;

use App\Http\Middleware\PersonnelAuthentifie;
use App\Models\AuthentificationPersonnel;
use App\Models\Medecin;
use App\Models\User;
use App\Support\Totp;
use Spatie\Permission\Models\Role;

/** Comptes du personnel prêts à l'emploi pour les tests (données fictives). */
trait PersonnelDeTest
{
    /** Secret TOTP connu des tests. */
    protected string $secretTotp = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';

    /** Activation terminée : mot de passe (celui de la fabrique) et second facteur confirmé. */
    protected function activer(User $user): AuthentificationPersonnel
    {
        return AuthentificationPersonnel::factory()->create([
            'user_id' => $user->id,
            'totp_secret' => $this->secretTotp,
        ]);
    }

    protected function medecinActive(): Medecin
    {
        $medecin = Medecin::factory()->create();
        $this->activer($medecin->user);

        return $medecin;
    }

    /** Un membre du personnel d'un autre rôle (caisse, support…), activé. */
    protected function personnelActive(string $role): User
    {
        $user = User::factory()->sansRole()->create([
            'email' => fake()->unique()->safeEmail(),
            'password' => 'mot-de-passe-de-test',
        ]);
        $user->assignRole(Role::findOrCreate($role, 'web'));
        $this->activer($user);

        return $user;
    }

    /** Session complète : mot de passe ET second facteur validés dans cette session. */
    protected function commePersonnel(User $user): static
    {
        return $this->actingAs($user)->withSession([
            PersonnelAuthentifie::CLE_AUTHENTIFIE => $user->id,
            PersonnelAuthentifie::CLE_ACTIVITE => now()->getTimestamp(),
        ]);
    }

    protected function codeTotpCourant(): string
    {
        return Totp::code($this->secretTotp, Totp::pas());
    }
}
