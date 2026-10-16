<?php

namespace App\Models;

use App\Models\Concerns\Syncable;
use App\Services\DeviseService;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * Pièce qui annule tout ou partie d'une facture émise.
 *
 * Une facture remise au patient ou envoyée à l'assureur ne se corrige pas
 * en la réécrivant : on lui oppose un avoir, numéroté à son tour, qui dit
 * combien, pourquoi et qui l'a décidé. Comme la facture, l'avoir ne se
 * modifie plus une fois émis — seule sa date de refacturation se complète.
 */
class Avoir extends Model
{
    use HasUuids, Syncable;

    protected $table = 'avoirs';

    protected $fillable = [
        'numero', 'facture_id', 'patient_id', 'establishment_id', 'type',
        'motif_categorie', 'motif', 'devise', 'taux_change',
        'montant_total', 'montant_patient', 'montant_assurance', 'taux_remise', 'credit_patient',
        'caution_id', 'liberer_prestations', 'prestations_liberees',
        'facture_convention_id', 'emis_par', 'date_avoir', 'refacture_le', 'sync_status',
    ];

    protected function casts(): array
    {
        return [
            'date_avoir' => 'datetime',
            'refacture_le' => 'datetime',
            'taux_change' => 'decimal:4',
            'montant_total' => 'decimal:2',
            'montant_patient' => 'decimal:2',
            'montant_assurance' => 'decimal:2',
            'taux_remise' => 'decimal:2',
            'credit_patient' => 'decimal:2',
            'liberer_prestations' => 'boolean',
            'prestations_liberees' => 'array',
        ];
    }

    public const TYPES = [
        'annulation' => 'Annulation totale',
        'partiel' => 'Avoir partiel',
        'remise' => 'Remise accordée',
    ];

    /** Pourquoi on annule : la direction lit ces chiffres chaque mois. */
    public const MOTIFS = [
        'erreur_prise_en_charge' => 'Erreur de prise en charge (assurance, convention, privé)',
        'erreur_tarif' => 'Erreur de tarif ou de quantité',
        'prestation_non_realisee' => 'Prestation non réalisée',
        'doublon' => 'Facture en double',
        'erreur_patient' => 'Facture établie au mauvais patient',
        'geste_commercial' => 'Geste commercial ou social',
        'autre' => 'Autre motif',
    ];

    /**
     * Pourquoi l'hôpital renonce à une part de sa recette. Distincts des
     * motifs d'avoir : une remise n'est pas une erreur, c'est une décision.
     */
    public const MOTIFS_REMISE = [
        'cas_social' => 'Cas social, indigence constatée',
        'personnel' => 'Personnel de l\'hôpital ou ayant droit',
        'geste_commercial' => 'Geste commercial',
        'arrondi' => 'Arrondi de caisse',
        'autre_remise' => 'Autre motif de remise',
    ];

    /** Seuls ces champs se complètent après l'émission. */
    private const COMPLETABLES = ['refacture_le', 'sync_status', 'updated_at'];

    protected static function booted(): void
    {
        static::updating(function (Avoir $avoir) {
            // L'avoir reçoit son crédit dans la transaction qui l'émet.
            if ($avoir->wasRecentlyCreated) {
                return;
            }

            $interdits = array_diff(array_keys($avoir->getDirty()), self::COMPLETABLES);

            if ($interdits !== []) {
                throw new LogicException(
                    'Un avoir émis ne se modifie pas ('.implode(', ', $interdits).').'
                );
            }
        });

        static::deleting(function () {
            throw new LogicException('Un avoir émis ne se supprime pas.');
        });
    }

    public function facture(): BelongsTo
    {
        return $this->belongsTo(Facture::class);
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'emis_par');
    }

    public function lignes(): HasMany
    {
        return $this->hasMany(LigneAvoir::class);
    }

    public function credit(): BelongsTo
    {
        return $this->belongsTo(Caution::class, 'caution_id');
    }

    public function factureConvention(): BelongsTo
    {
        return $this->belongsTo(FactureConvention::class);
    }

    /** Factures émises à nouveau pour remplacer celle-ci. */
    public function facturesDeRemplacement(): HasMany
    {
        return $this->hasMany(Facture::class, 'avoir_origine_id');
    }

    public function libelleType(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }

    public function libelleMotif(): string
    {
        return self::MOTIFS[$this->motif_categorie]
            ?? self::MOTIFS_REMISE[$this->motif_categorie]
            ?? $this->motif_categorie;
    }

    public function estRemise(): bool
    {
        return $this->type === 'remise';
    }

    /** « Remise 10 % » ou « Avoir » : ce qu'on lit sur la ligne de la facture. */
    public function libelleCourt(): string
    {
        if (! $this->estRemise()) {
            return 'Avoir';
        }

        return $this->taux_remise !== null
            ? 'Remise '.rtrim(rtrim(number_format((float) $this->taux_remise, 2, ',', ' '), '0'), ',').' %'
            : 'Remise';
    }

    public function formater(float $montant): string
    {
        return app(DeviseService::class)->formater($montant, $this->devise ?: 'CDF');
    }

    /** Reste-t-il des prestations libérées à refacturer ? */
    public function peutEtreRefacture(): bool
    {
        return $this->liberer_prestations
            && $this->refacture_le === null
            && ! empty($this->prestations_liberees);
    }

    public static function genererNumero(): string
    {
        $prefix = 'AV-'.now()->format('Y').'-';
        $dernier = static::where('numero', 'like', $prefix.'%')
            ->orderByDesc('numero')
            ->value('numero');

        $suite = $dernier ? (int) substr($dernier, -6) + 1 : 1;

        return $prefix.str_pad((string) $suite, 6, '0', STR_PAD_LEFT);
    }
}
