<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReceptionStock extends Model
{
    use HasUuids;

    protected $table = 'receptions_stock';

    protected $fillable = [
        'numero', 'commande_id', 'fournisseur_id', 'officine_id', 'origine', 'date_reception',
        'numero_bon_livraison', 'numero_facture_fournisseur', 'devise', 'montant_total', 'montant_paye',
        'observations', 'user_id',
    ];

    protected function casts(): array
    {
        return ['date_reception' => 'date', 'montant_total' => 'decimal:2', 'montant_paye' => 'decimal:2'];
    }

    public const ORIGINES = ['achat' => 'Achat', 'don' => 'Don', 'transfert_externe' => 'Transfert externe', 'retour' => 'Retour'];

    public function lignes(): HasMany
    {
        return $this->hasMany(LigneReceptionStock::class, 'reception_id');
    }

    public function fournisseur(): BelongsTo
    {
        return $this->belongsTo(Fournisseur::class);
    }

    public function commande(): BelongsTo
    {
        return $this->belongsTo(CommandeFournisseur::class, 'commande_id');
    }

    public function officine(): BelongsTo
    {
        return $this->belongsTo(Officine::class);
    }

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function resteAPayer(): float
    {
        return max(0, round((float) $this->montant_total - (float) $this->montant_paye, 2));
    }
}
