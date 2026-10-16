<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class LigneFacture extends Model
{
    use HasUuids;

    protected $table = 'lignes_facture';

    public $timestamps = false;

    protected $fillable = [
        'facture_id', 'type', 'libelle', 'reference_id',
        'quantite', 'prix_unitaire', 'remise_ligne', 'total_ligne',
    ];

    protected function casts(): array
    {
        return [
            'quantite' => 'decimal:2',
            'prix_unitaire' => 'decimal:2',
            'remise_ligne' => 'decimal:2',
            'total_ligne' => 'decimal:2',
        ];
    }

    /**
     * Une ligne vit avec sa facture : elle s'ajoute tant que la facture se
     * construit, puis ne bouge plus. Retirer une prestation passe par un
     * avoir, qui garde la trace de ce qui a été retiré et pourquoi.
     */
    protected static function booted(): void
    {
        static::creating(function (LigneFacture $ligne) {
            $facture = Facture::find($ligne->facture_id);

            if ($facture && ! $facture->accepteNouvellesLignes()) {
                throw new LogicException(
                    "La facture {$facture->numero_facture} a déjà reçu un règlement ou un avoir : "
                    .'on ne lui ajoute plus de ligne. Émettez une nouvelle facture.'
                );
            }
        });

        static::updating(fn (LigneFacture $ligne) => $ligne->refuserSiFactureEmise('modifiée'));
        static::deleting(fn (LigneFacture $ligne) => $ligne->refuserSiFactureEmise('supprimée'));
    }

    private function refuserSiFactureEmise(string $verbe): void
    {
        $facture = Facture::find($this->facture_id);

        if ($facture && $facture->statut !== 'brouillon') {
            throw new LogicException(
                "La facture {$facture->numero_facture} est émise : sa ligne « {$this->libelle} » "
                ."ne peut être {$verbe}. Établissez un avoir."
            );
        }
    }

    public function facture(): BelongsTo
    {
        return $this->belongsTo(Facture::class);
    }
}
