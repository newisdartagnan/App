<?php

namespace App\Models;

use Database\Factories\AuthentificationPersonnelFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Double authentification d'un membre du personnel (CPT-06). Rien n'est
 * « fillable » : tout passe par les services d'activation et de connexion.
 */
class AuthentificationPersonnel extends Model
{
    /** @use HasFactory<AuthentificationPersonnelFactory> */
    use HasFactory, HasUuids;

    protected $table = 'authentifications_personnel';

    protected $guarded = ['*'];

    protected $hidden = ['totp_secret'];

    protected function casts(): array
    {
        return [
            // Chiffré par la clé de l'application : jamais en clair dans la base.
            'totp_secret' => 'encrypted',
            'totp_dernier_pas' => 'integer',
            'active_le' => 'datetime',
            'echecs_consecutifs' => 'integer',
            'verrouille_jusqu_au' => 'datetime',
            'derniere_connexion_le' => 'datetime',
        ];
    }

    public static function pour(User $user): ?self
    {
        return static::where('user_id', $user->id)->first();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function estActive(): bool
    {
        return $this->active_le !== null && $this->totp_secret !== null;
    }

    public function estVerrouille(): bool
    {
        return $this->verrouille_jusqu_au !== null && $this->verrouille_jusqu_au->isFuture();
    }
}
