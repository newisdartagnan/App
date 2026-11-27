<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Une dépense : saisie, approuvée par qui en a le droit, puis payée depuis
 * un compte de trésorerie. Rejetée, elle reste au registre avec son motif.
 */
class Depense extends Model
{
    use HasUuids;

    protected $fillable = [
        'numero', 'date_depense', 'rubrique_id', 'compte_tresorerie_id', 'service_id', 'fournisseur_id', 'reception_id',
        'beneficiaire', 'libelle', 'montant', 'devise', 'taux_change', 'montant_cdf', 'mode_paiement', 'reference_piece',
        'piece_path', 'statut', 'motif_rejet', 'saisie_par', 'approuvee_par', 'approuvee_at', 'payee_par', 'payee_at',
    ];

    protected function casts(): array
    {
        return [
            'date_depense' => 'date', 'montant' => 'decimal:2', 'montant_cdf' => 'decimal:2',
            'approuvee_at' => 'datetime', 'payee_at' => 'datetime',
        ];
    }

    public const STATUTS = ['en_attente' => 'En attente d\'approbation', 'approuvee' => 'Approuvée — à payer', 'payee' => 'Payée', 'rejetee' => 'Rejetée'];

    public function rubrique(): BelongsTo
    {
        return $this->belongsTo(RubriqueDepense::class, 'rubrique_id');
    }

    public function compte(): BelongsTo
    {
        return $this->belongsTo(CompteTresorerie::class, 'compte_tresorerie_id');
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function fournisseur(): BelongsTo
    {
        return $this->belongsTo(Fournisseur::class);
    }

    public function reception(): BelongsTo
    {
        return $this->belongsTo(ReceptionStock::class, 'reception_id');
    }

    public function saisiePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'saisie_par');
    }

    public function approuveePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approuvee_par');
    }
}
