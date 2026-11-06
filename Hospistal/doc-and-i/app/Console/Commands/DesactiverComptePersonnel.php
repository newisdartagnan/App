<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Désactive un compte du personnel depuis le serveur : seul chemin pour les
 * comptes que l'écran ne gère pas (administrateurs, auditeur). Les
 * médecins se désactivent depuis l'écran, qui traite leurs rendez-vous.
 */
class DesactiverComptePersonnel extends Command
{
    protected $signature = 'personnel:desactiver {email : Courriel du compte} {--motif= : Motif (obligatoire)}';

    protected $description = 'Désactive un compte du personnel (sessions fermées, liens d\'activation invalidés)';

    public function handle(): int
    {
        $motif = trim((string) $this->option('motif'));
        $user = User::where('email', mb_strtolower(trim($this->argument('email'))))->first();

        if ($motif === '' || $user === null || ! $user->estDuPersonnel()) {
            $this->error($motif === '' ? 'Le motif est obligatoire (--motif=…).' : 'Aucun compte du personnel avec ce courriel.');

            return self::FAILURE;
        }

        if ($user->hasRole('medecin')) {
            $this->error('Un médecin se désactive depuis l\'écran d\'administration, qui traite ses rendez-vous.');

            return self::FAILURE;
        }

        DB::transaction(function () use ($user, $motif) {
            $u = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $u->forceFill([
                'desactive_le' => $u->desactive_le ?? now(),
                'motif_desactivation' => mb_substr($motif, 0, 255),
                'version_session' => (int) $u->version_session + 1,
            ])->save();
            DB::table('jetons_activation_personnel')->where('user_id', $u->id)
                ->whereNull('utilise_le')->whereNull('invalide_le')->update(['invalide_le' => now()]);
        });

        $this->info('Compte désactivé : '.$user->email.'.');

        return self::SUCCESS;
    }
}
