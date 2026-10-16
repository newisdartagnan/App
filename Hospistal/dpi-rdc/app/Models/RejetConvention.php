<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ce que l'assureur refuse de payer sur un bordereau, et pourquoi.
 *
 * Un rejet ouvert, contesté ou abandonné sort de ce que l'assureur doit ;
 * levé, il y revient. La suite donnée reste écrite : c'est ce qui permet,
 * au mois suivant, de ne pas refaire la même erreur.
 */
class RejetConvention extends Model
{
    use HasUuids;

    protected $table = 'rejets_convention';

    protected $fillable = [
        'facture_convention_id', 'ligne_facture_convention_id', 'facture_id',
        'motif_categorie', 'motif', 'montant', 'date_rejet', 'statut',
        'suite_donnee', 'traite_le', 'traite_par', 'saisi_par',
    ];

    protected function casts(): array
    {
        return [
            'montant' => 'decimal:2',
            'date_rejet' => 'date',
            'traite_le' => 'datetime',
        ];
    }

    /** Les motifs de rejet que les assureurs opposent le plus souvent. */
    public const MOTIFS = [
        'bon_absent' => 'Bon de prise en charge absent, expiré ou non conforme',
        'hors_garantie' => 'Acte hors garantie ou au-dessus du plafond',
        'pieces_manquantes' => 'Pièces justificatives manquantes',
        'hors_delai' => 'Dépôt hors délai contractuel',
        'erreur_identite' => 'Erreur d\'identité ou de matricule de l\'assuré',
        'tarif_taux' => 'Tarif ou taux de couverture mal appliqué',
        'autre' => 'Autre motif',
    ];

    public const STATUTS = [
        'ouvert' => 'À traiter',
        'conteste' => 'Contesté',
        'leve' => 'Levé — l\'assureur paiera',
        'abandonne' => 'Abandonné',
    ];

    /** Ces rejets ne sont plus attendus de l'assureur. */
    public const DEDUITS = ['ouvert', 'conteste', 'abandonne'];

    public function factureConvention(): BelongsTo
    {
        return $this->belongsTo(FactureConvention::class);
    }

    public function facture(): BelongsTo
    {
        return $this->belongsTo(Facture::class);
    }

    public function ligne(): BelongsTo
    {
        return $this->belongsTo(LigneFactureConvention::class, 'ligne_facture_convention_id');
    }

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'saisi_par');
    }

    public function libelleMotif(): string
    {
        return self::MOTIFS[$this->motif_categorie] ?? $this->motif_categorie;
    }

    public function libelleStatut(): string
    {
        return self::STATUTS[$this->statut] ?? $this->statut;
    }

    public function estEnCours(): bool
    {
        return in_array($this->statut, ['ouvert', 'conteste'], true);
    }
}
