<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Personnel\ActivationPersonnelService;
use Illuminate\Console\Command;
use InvalidArgumentException;

/** CPT-05 : nouveau lien d'activation (le précédent a expiré ou s'est perdu) ; l'ancien ne vaut plus rien. */
class LienActivation extends Command
{
    protected $signature = 'personnel:lien-activation {email : Courriel du membre du personnel}';

    protected $description = 'Émet un nouveau lien d\'activation pour un compte du personnel pas encore activé';

    public function handle(ActivationPersonnelService $activation): int
    {
        $user = User::where('email', mb_strtolower(trim($this->argument('email'))))->first();

        if ($user === null) {
            $this->error('Aucun compte n\'a ce courriel.');

            return self::FAILURE;
        }

        try {
            $lien = $activation->emettreLien($user);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line('Nouveau lien d\'activation, à usage unique, valable '.config('personnel.activation.validite_heures').' heures. Le précédent ne fonctionne plus :');
        $this->line($lien);

        return self::SUCCESS;
    }
}
