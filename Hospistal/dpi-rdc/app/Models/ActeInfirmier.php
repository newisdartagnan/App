<?php

namespace App\Models;

use App\Models\Concerns\Syncable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un acte infirmier réalisé, avec son auteur et son heure.
 *
 * Un acte non écrit est un acte qu'on refait ou qu'on oublie : à la relève,
 * l'équipe suivante ne sait pas si la deuxième injection a été faite.
 */
class ActeInfirmier extends Model
{
    use HasUuids, Syncable;

    protected $table = 'actes_infirmiers';

    protected $fillable = [
        'visit_id', 'patient_id', 'user_id', 'type', 'libelle',
        'precisions', 'prescription_id', 'realise_a', 'observation', 'sync_status',
    ];

    protected function casts(): array
    {
        return ['realise_a' => 'datetime'];
    }

    /**
     * Ce que fait une équipe infirmière, et qu'il faut pouvoir écrire vite.
     *
     * La liste tient à ce qui se pratique réellement dans un hôpital général
     * de la RDC. Elle est courte à dessein : un catalogue de deux cents
     * lignes ne se parcourt pas à trois heures du matin.
     *
     * @var array<string, array{libelle: string, aide: string}>
     */
    public const TYPES = [
        'injection_im' => [
            'libelle' => 'Injection intramusculaire',
            'aide' => 'Produit, dose, site — fessier, deltoïde',
        ],
        'injection_iv' => [
            'libelle' => 'Injection intraveineuse',
            'aide' => 'Produit, dose, voie',
        ],
        'perfusion_pose' => [
            'libelle' => 'Pose de perfusion',
            'aide' => 'Soluté, volume, débit, site du cathéter',
        ],
        'perfusion_surveillance' => [
            'libelle' => 'Surveillance ou changement de perfusion',
            'aide' => 'Ce qui a été changé, état du point de ponction',
        ],
        'prise_orale' => [
            'libelle' => 'Administration par voie orale',
            'aide' => 'Produit et dose réellement pris',
        ],
        'sondage_vesical' => [
            'libelle' => 'Sondage vésical',
            'aide' => 'Calibre, aspect des urines, volume',
        ],
        'sonde_gastrique' => [
            'libelle' => 'Pose de sonde gastrique',
            'aide' => 'Calibre, repère, tolérance',
        ],
        'oxygenotherapie' => [
            'libelle' => 'Oxygénothérapie',
            'aide' => 'Débit en litres par minute, mode — lunettes, masque',
        ],
        'aspiration' => [
            'libelle' => 'Aspiration',
            'aide' => 'Aspect et abondance des sécrétions',
        ],
        'prelevement' => [
            'libelle' => 'Prélèvement',
            'aide' => 'Nature du prélèvement, tube, destination',
        ],
        'constantes' => [
            'libelle' => 'Prise des constantes',
            'aide' => 'À détailler dans les signes vitaux du dossier',
        ],
        'nursing' => [
            'libelle' => 'Soins de nursing',
            'aide' => 'Toilette, change, prévention des escarres, mobilisation',
        ],
        'education' => [
            'libelle' => 'Éducation du patient ou de la famille',
            'aide' => 'Ce qui a été expliqué, et à qui',
        ],
        'autre' => [
            'libelle' => 'Autre acte',
            'aide' => 'À décrire dans les précisions',
        ],
    ];

    /** Les actes qui découlent en général d'une prescription. */
    public const SUR_PRESCRIPTION = [
        'injection_im', 'injection_iv', 'perfusion_pose', 'prise_orale', 'oxygenotherapie',
    ];

    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /** Celui qui l'a fait — pas celui qui l'a prescrit. */
    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function prescription(): BelongsTo
    {
        return $this->belongsTo(Prescription::class);
    }

    public function libelleType(): string
    {
        return self::TYPES[$this->type]['libelle'] ?? $this->libelle;
    }

    /** Un acte prescrit qu'on exécute sans avoir désigné l'ordonnance. */
    public function attendUneOrdonnance(): bool
    {
        return in_array($this->type, self::SUR_PRESCRIPTION, true)
            && $this->prescription_id === null;
    }
}
