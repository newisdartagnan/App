<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CommandeFournisseur extends Model
{
    use HasUuids;

    protected $table = 'commandes_fournisseur';

    protected $fillable = [
        'numero', 'fournisseur_id', 'officine_id', 'statut', 'date_commande', 'date_livraison_prevue',
        'devise', 'observations', 'cree_par', 'envoyee_par', 'envoyee_at',
    ];

    protected function casts(): array
    {
        return ['date_commande' => 'date', 'date_livraison_prevue' => 'date', 'envoyee_at' => 'datetime'];
    }

    public const STATUTS = [
        'brouillon' => 'Brouillon', 'envoyee' => 'Envoyée au fournisseur',
        'partiellement_recue' => 'Partiellement reçue', 'recue' => 'Reçue', 'annulee' => 'Annulée',
    ];

    public function fournisseur(): BelongsTo
    {
        return $this->belongsTo(Fournisseur::class);
    }

    public function officine(): BelongsTo
    {
        return $this->belongsTo(Officine::class);
    }

    public function lignes(): HasMany
    {
        return $this->hasMany(LigneCommandeFournisseur::class, 'commande_id');
    }

    public function receptions(): HasMany
    {
        return $this->hasMany(ReceptionStock::class, 'commande_id');
    }

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cree_par');
    }

    public function montant(): float
    {
        return (float) $this->lignes->sum(fn ($l) => (float) $l->quantite_commandee * (float) $l->prix_unitaire);
    }

    public function peutRecevoir(): bool
    {
        return in_array($this->statut, ['envoyee', 'partiellement_recue'], true);
    }
}
