<?php

namespace App\Console\Commands;

use App\Models\User;
use Firebase\JWT\JWT;
use Illuminate\Console\Command;

class GenerateOfflineToken extends Command
{
    protected $signature = 'dpi:offline-token {user : ID ou email utilisateur}';

    protected $description = 'Génère un JWT offline (48h) pour un utilisateur';

    public function handle(): int
    {
        $identifier = $this->argument('user');

        $user = User::where('id', $identifier)
            ->orWhere('email', $identifier)
            ->orWhere('matricule', $identifier)
            ->first();

        if (! $user) {
            $this->error('Utilisateur introuvable.');

            return self::FAILURE;
        }

        $expiresAt = now()->addHours(48);
        $payload = [
            'sub' => $user->id,
            'est' => $user->establishment_id,
            'roles' => $user->getRoleNames()->toArray(),
            'iat' => now()->timestamp,
            'exp' => $expiresAt->timestamp,
        ];

        $token = JWT::encode($payload, config('app.key'), 'HS256');

        $user->update([
            'offline_token' => $token,
            'offline_token_expires_at' => $expiresAt,
        ]);

        $this->info("Token offline généré pour {$user->nom_complet}");
        $this->line("Expire le: {$expiresAt->toDateTimeString()}");
        $this->newLine();
        $this->line($token);

        return self::SUCCESS;
    }
}
