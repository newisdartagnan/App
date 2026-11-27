<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Caisse, compte bancaire ou portefeuille mobile money de l'établissement. */
class CompteTresorerie extends Model
{
    use HasUuids;

    protected $table = 'comptes_tresorerie';

    protected $fillable = ['nom', 'type', 'devise', 'numero', 'modes_paiement', 'solde_initial', 'date_solde_initial', 'est_actif'];

    protected function casts(): array
    {
        return ['modes_paiement' => 'array', 'solde_initial' => 'decimal:2', 'date_solde_initial' => 'date', 'est_actif' => 'boolean'];
    }

    public const TYPES = ['caisse' => 'Caisse', 'banque' => 'Banque', 'mobile_money' => 'Mobile Money'];

    public const MODES = ['especes' => 'Espèces', 'mobile_money' => 'Mobile Money', 'virement' => 'Virement', 'cheque' => 'Chèque'];
}
