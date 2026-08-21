<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationInterne extends Model
{
    use HasUuids;

    protected $table = 'notifications_internes';

    protected $fillable = [
        'service', 'type', 'reference_type', 'reference_id', 'code_reference',
        'titre', 'message', 'destinataire_id', 'groupe_destinataire',
        'priorite', 'lu', 'read_at', 'archive',
    ];

    protected function casts(): array
    {
        return [
            'lu' => 'boolean',
            'archive' => 'boolean',
            'read_at' => 'datetime',
        ];
    }

    public function destinataire(): BelongsTo
    {
        return $this->belongsTo(User::class, 'destinataire_id');
    }

    /**
     * Visibilité : l'administration voit tout, chacun voit les siennes et
     * celles adressées à son rôle (modèle CSK getNotifications).
     */
    public function scopePourUtilisateur(Builder $query, User $user): Builder
    {
        if ($user->hasAnyRole(['super_admin', 'directeur'])) {
            return $query;
        }

        $roles = $user->getRoleNames()->all();

        return $query->where(function (Builder $q) use ($user, $roles) {
            $q->where('destinataire_id', $user->id)
                ->orWhereIn('groupe_destinataire', array_merge($roles, ['tous']));
        });
    }

    public function scopeNonLues(Builder $query): Builder
    {
        return $query->where('lu', false);
    }

    public function scopeActives(Builder $query): Builder
    {
        return $query->where('archive', false);
    }

    /**
     * Page concernée par la notification.
     */
    /**
     * Où mène la notification.
     *
     * Un résultat d'examen mène au document PDF, et non à l'écran du plateau
     * technique : le médecin qui a prescrit n'a pas forcément accès au
     * laboratoire ni à l'imagerie, et ce qu'il attend c'est son compte rendu.
     * Les demandes de travail, elles, mènent bien à l'écran de production.
     */
    public function lien(): ?string
    {
        if ($this->reference_type === 'examen') {
            return $this->type === 'resultat_pret'
                ? route('examens.pdf', $this->reference_id)
                : route('labo.show', $this->reference_id);
        }

        return match ($this->reference_type) {
            'prescription' => route('pharmacie.prescription', $this->reference_id),
            default => null,
        };
    }

    /** Le lien ouvre-t-il un document plutôt qu'un écran de l'application ? */
    public function lienEstDocument(): bool
    {
        return $this->reference_type === 'examen' && $this->type === 'resultat_pret';
    }
}
