<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, HasUuids, Notifiable;

    protected $fillable = [
        'establishment_id', 'matricule', 'nom', 'prenom', 'email',
        'telephone', 'password', 'specialite', 'theme', 'is_active',
        'last_login_at', 'offline_token', 'offline_token_expires_at', 'photo_path',
    ];

    protected $hidden = [
        'password', 'remember_token', 'offline_token',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
            'offline_token_expires_at' => 'datetime',
        ];
    }

    public function establishment(): BelongsTo
    {
        return $this->belongsTo(Establishment::class);
    }

    public function disponibilites(): HasMany
    {
        return $this->hasMany(DisponibiliteMedecin::class);
    }

    public function absences(): HasMany
    {
        return $this->hasMany(AbsenceMedecin::class);
    }

    /** Adresse de la photo de profil, ou null (l'initiale s'affiche alors). */
    public function photoUrl(): ?string
    {
        return $this->photo_path ? route('compte.photo', $this) : null;
    }

    public function initiales(): string
    {
        return mb_strtoupper(mb_substr((string) $this->nom, 0, 1).mb_substr((string) $this->prenom, 0, 1));
    }

    public function getNomCompletAttribute(): string
    {
        return "{$this->nom} {$this->prenom}";
    }

    /**
     * Nom court des rôles, pour l'en-tête et les listes.
     *
     * Le paramétrage en donne la version longue, avec ses attributions ;
     * ici on n'a la place que du métier.
     */
    public const NOMS_ROLES = [
        'super_admin' => 'Administrateur',
        'directeur' => 'Directeur',
        'medecin' => 'Médecin',
        'infirmier_chef' => 'Infirmier chef',
        'infirmier' => 'Infirmier',
        'laborantin' => 'Laborantin',
        'radiologue' => 'Radiologue',
        'pharmacien' => 'Pharmacien',
        'caissier' => 'Caissier',
        'chef_facturation' => 'Chef de facturation',
        'agent_admin' => 'Agent administratif',
    ];

    /** Rôles de l'utilisateur, en clair. */
    public function libelleRoles(): string
    {
        $roles = $this->roles
            ->map(fn ($role) => self::NOMS_ROLES[$role->name] ?? ($role->libelle ?: $role->name))
            ->implode(', ');

        return $roles ?: 'Sans rôle attribué';
    }
}
