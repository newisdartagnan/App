<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

/** Réactive depuis le serveur un compte du personnel désactivé (administrateurs, auditeur). */
class ReactiverComptePersonnel extends Command
{
    protected $signature = 'personnel:reactiver {email : Courriel du compte}';

    protected $description = 'Réactive un compte du personnel désactivé';

    public function handle(): int
    {
        $user = User::where('email', mb_strtolower(trim($this->argument('email'))))->first();

        if ($user === null || ! $user->estDuPersonnel() || ! $user->estDesactive()) {
            $this->error('Aucun compte du personnel désactivé avec ce courriel.');

            return self::FAILURE;
        }

        if ($user->hasRole('medecin')) {
            $this->error('Un médecin se réactive depuis l\'écran d\'administration (l\'Ordre y est vérifié de nouveau).');

            return self::FAILURE;
        }

        $user->forceFill(['desactive_le' => null, 'motif_desactivation' => null])->save();
        $this->info('Compte réactivé : '.$user->email.'. S\'il n\'avait jamais été activé : php artisan personnel:lien-activation '.$user->email);

        return self::SUCCESS;
    }
}
