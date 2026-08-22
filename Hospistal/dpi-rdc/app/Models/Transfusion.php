<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Traçabilité transfusionnelle poche par poche.
 *
 * Le contrôle de compatibilité ABO / Rhésus est fait à l'enregistrement :
 * une poche incompatible ne peut pas être posée, c'est la sécurité la plus
 * élémentaire et la plus fréquemment prise en défaut.
 */
class Transfusion extends Model
{
    use HasUuids;

    protected $table = 'transfusions';

    protected $fillable = [
        'visit_id', 'user_id', 'produit', 'groupe_donneur', 'groupe_receveur',
        'numero_poche', 'quantite', 'jour', 'heure_debut', 'heure_fin',
        'incident', 'observation',
        // Raccordement à la banque de sang : la poche délivrée, la demande
        // qui l'a motivée, et le contrôle ultime au lit du malade.
        'poche_id', 'demande_id', 'patient_id',
        'controle_ultime', 'hemoglobine_avant', 'hemoglobine_apres',
        // Hémovigilance : quand la poche a fini de couler, qui l'a constaté,
        // et sur quelle facture elle a été portée.
        'cloturee_le', 'cloturee_par', 'facture_id',
    ];

    protected function casts(): array
    {
        return [
            'jour' => 'date',
            'controle_ultime' => 'boolean',
            'hemoglobine_avant' => 'decimal:1',
            'hemoglobine_apres' => 'decimal:1',
            'cloturee_le' => 'datetime',
        ];
    }

    public const PRODUITS = [
        'cgr' => 'Concentré de globules rouges',
        'sang_total' => 'Sang total',
        'pfc' => 'Plasma frais congelé',
        'cp' => 'Concentré plaquettaire',
    ];

    public const GROUPES = ['O-', 'O+', 'A-', 'A+', 'B-', 'B+', 'AB-', 'AB+'];

    public const INCIDENTS = [
        'aucun' => 'Aucun incident',
        'frisson' => 'Frissons / hyperthermie',
        'urticaire' => 'Urticaire',
        'dyspnee' => 'Dyspnée',
        'hemolyse' => 'Suspicion d\'hémolyse',
        'surcharge' => 'Surcharge volémique',
    ];

    /**
     * Donneurs acceptés pour chaque receveur, en globules rouges.
     * Le plasma suit la règle inverse, traitée dans estCompatible().
     */
    public const COMPATIBILITE_GLOBULAIRE = [
        'O-' => ['O-'],
        'O+' => ['O-', 'O+'],
        'A-' => ['O-', 'A-'],
        'A+' => ['O-', 'O+', 'A-', 'A+'],
        'B-' => ['O-', 'B-'],
        'B+' => ['O-', 'O+', 'B-', 'B+'],
        'AB-' => ['O-', 'A-', 'B-', 'AB-'],
        'AB+' => self::GROUPES,
    ];

    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function poche(): BelongsTo
    {
        return $this->belongsTo(PocheSang::class, 'poche_id');
    }

    public function demande(): BelongsTo
    {
        return $this->belongsTo(DemandeSang::class, 'demande_id');
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function facture(): BelongsTo
    {
        return $this->belongsTo(Facture::class);
    }

    public function cloturePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cloturee_par');
    }

    /**
     * Incidents qui imposent d'arrêter la perfusion sur-le-champ.
     *
     * Frissons et urticaire se traitent la poche en cours ; une suspicion
     * d'hémolyse ou une dyspnée, non — on débranche et on appelle.
     */
    public const INCIDENTS_GRAVES = ['hemolyse', 'dyspnee', 'surcharge'];

    public function incidentEstGrave(): bool
    {
        return in_array($this->incident, self::INCIDENTS_GRAVES, true);
    }

    public function estCloturee(): bool
    {
        return $this->cloturee_le !== null;
    }

    /**
     * Gain d'hémoglobine constaté, en g/dL.
     *
     * Une poche de globules rouges fait normalement monter l'hémoglobine
     * d'environ 1 g/dL chez l'adulte. Un gain nul interroge : soit le malade
     * saigne encore, soit la poche n'a pas été transfusée en entier.
     */
    public function rendement(): ?float
    {
        if ($this->hemoglobine_avant === null || $this->hemoglobine_apres === null) {
            return null;
        }

        return round((float) $this->hemoglobine_apres - (float) $this->hemoglobine_avant, 1);
    }

    /** Le gain d'hémoglobine est-il en deçà de ce qu'une poche doit donner ? */
    public function rendementInsuffisant(): bool
    {
        $rendement = $this->rendement();

        return $rendement !== null
            && in_array($this->produit, ['cgr', 'sang_total'], true)
            && $rendement < 0.5;
    }

    /**
     * Groupes ABO donneurs acceptés pour chaque receveur, en plasma.
     * Le plasma transporte les anticorps du donneur : la compatibilité s'y
     * lit donc à l'envers du globulaire (AB donne à tous, O ne donne qu'au
     * O). Le Rhésus n'entre pas en ligne de compte pour le plasma.
     */
    public const COMPATIBILITE_PLASMATIQUE = [
        'O' => ['O', 'A', 'B', 'AB'],
        'A' => ['A', 'AB'],
        'B' => ['B', 'AB'],
        'AB' => ['AB'],
    ];

    public static function estCompatible(string $produit, string $donneur, string $receveur): bool
    {
        if (! in_array($donneur, self::GROUPES, true) || ! in_array($receveur, self::GROUPES, true)) {
            return false;
        }

        if ($produit === 'pfc') {
            $abo = fn (string $g) => rtrim($g, '+-');

            return in_array($abo($donneur), self::COMPATIBILITE_PLASMATIQUE[$abo($receveur)], true);
        }

        // Les plaquettes suivent la compatibilité globulaire, par prudence.
        return in_array($donneur, self::COMPATIBILITE_GLOBULAIRE[$receveur] ?? [], true);
    }

    public function libelleProduit(): string
    {
        return self::PRODUITS[$this->produit] ?? $this->produit;
    }

    public function libelleIncident(): string
    {
        return self::INCIDENTS[$this->incident] ?? $this->incident;
    }

    public function enCours(): bool
    {
        return $this->heure_fin === null;
    }

    public function avecIncident(): bool
    {
        return $this->incident !== 'aucun';
    }

    /** Durée de pose en minutes, null tant que la poche n'est pas terminée. */
    public function dureeMinutes(): ?int
    {
        if ($this->heure_fin === null) {
            return null;
        }

        $debut = Carbon::parse($this->jour->toDateString().' '.$this->heure_debut);
        $fin = Carbon::parse($this->jour->toDateString().' '.$this->heure_fin);

        if ($fin->lessThan($debut)) {
            $fin->addDay(); // pose à cheval sur minuit
        }

        return (int) $debut->diffInMinutes($fin);
    }
}
