<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, HasUuids, Notifiable;

    /**
     * Le téléphone n'est pas modifiable par formulaire : il identifie le
     * compte et n'est posé qu'après vérification du code.
     */
    protected $fillable = [
        'nom', 'postnom', 'prenom', 'sexe', 'date_naissance', 'commune', 'langue',
        'contact_urgence_nom', 'contact_urgence_telephone',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'telephone_verifie_le' => 'datetime',
            'desactive_le' => 'datetime',
            'date_naissance' => 'date',
            'password' => 'hashed',
        ];
    }

    public function medecin(): HasOne
    {
        return $this->hasOne(Medecin::class);
    }

    /** Rôles du personnel : ils se connectent par courriel, mot de passe et double authentification (CPT-06), jamais par code SMS seul. */
    /** Compte du personnel désactivé par l'administration : plus de connexion, historique gardé. */
    public function estDesactive(): bool
    {
        return $this->desactive_le !== null;
    }

    public function estDuPersonnel(): bool
    {
        return $this->roles()->whereIn('name', array_diff(config('doc-and-i.roles'), ['patient']))->exists();
    }

    public function consentements(): HasMany
    {
        return $this->hasMany(Consentement::class);
    }

    /** Ce qu'il faut savoir du patient pour le soigner et le joindre (CPT-02). */
    public function profilComplet(): bool
    {
        return filled($this->nom) && filled($this->prenom)
            && in_array($this->sexe, ['M', 'F'], true)
            && $this->date_naissance !== null;
    }

    /** Le consentement en vigueur : version courante, non retiré. */
    public function consentementActif(): ?Consentement
    {
        return $this->consentements()
            ->where('version', config('doc-and-i.consentement.version'))
            ->whereNull('retire_le')
            ->latest('accepte_le')
            ->first();
    }

    public function nomComplet(): string
    {
        return trim(mb_strtoupper((string) $this->nom).' '.$this->postnom.' '.$this->prenom);
    }
}
