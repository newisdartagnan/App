<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Une séance de dialyse : quatre heures branchées sur un générateur.
 *
 * Ce qui fait la qualité de la séance tient en peu de chiffres : le poids
 * avant et après, donc l'eau réellement retirée, et la tension aux deux bouts.
 */
class SeanceDialyse extends Model
{
    use HasUuids;

    protected $table = 'seances_dialyse';

    protected $fillable = [
        'establishment_id', 'patient_id', 'visit_id', 'generateur_id', 'acte_clinique_id',
        'date_seance', 'duree_minutes', 'type', 'abord', 'statut',
        'poids_avant_kg', 'poids_apres_kg', 'poids_sec_kg', 'ultrafiltration_ml',
        'ta_avant_systolique', 'ta_avant_diastolique',
        'ta_apres_systolique', 'ta_apres_diastolique',
        'anticoagulation', 'erythropoietine', 'incidents', 'observations',
        'nephrologue_id', 'infirmier_id',
    ];

    protected function casts(): array
    {
        return [
            'date_seance' => 'datetime',
            'poids_avant_kg' => 'decimal:2',
            'poids_apres_kg' => 'decimal:2',
            'poids_sec_kg' => 'decimal:2',
            'erythropoietine' => 'boolean',
        ];
    }

    public const TYPES = [
        'hemodialyse' => 'Hémodialyse',
        'hemodialyse_epo' => 'Hémodialyse avec érythropoïétine',
        'peritoneale' => 'Dialyse péritonéale',
    ];

    public const ABORDS = [
        'fistule' => 'Fistule artério-veineuse',
        'catheter_tunnelise' => 'Cathéter tunnelisé',
        'catheter_jugulaire' => 'Cathéter jugulaire',
        'catheter_femoral' => 'Cathéter fémoral',
        'peritoneal' => 'Cathéter péritonéal',
    ];

    public const STATUTS = [
        'planifiee' => 'Planifiée',
        'realisee' => 'Réalisée',
        'annulee' => 'Annulée',
        'absente' => 'Patient absent',
    ];

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function generateur(): BelongsTo
    {
        return $this->belongsTo(GenerateurDialyse::class, 'generateur_id');
    }

    public function nephrologue(): BelongsTo
    {
        return $this->belongsTo(User::class, 'nephrologue_id');
    }

    public function infirmier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'infirmier_id');
    }

    public function acteClinique(): BelongsTo
    {
        return $this->belongsTo(ActeClinique::class, 'acte_clinique_id');
    }

    public function finPrevue(): Carbon
    {
        return $this->date_seance->copy()->addMinutes($this->duree_minutes ?: 240);
    }

    public function libelleType(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }

    public function libelleAbord(): string
    {
        return self::ABORDS[$this->abord] ?? '—';
    }

    public function libelleStatut(): string
    {
        return self::STATUTS[$this->statut] ?? $this->statut;
    }

    /**
     * Eau retirée pendant la séance, en millilitres.
     *
     * Le poids perdu est la mesure la plus fiable dont dispose l'unité :
     * un kilogramme perdu, c'est un litre d'ultrafiltrat.
     */
    public function perteDePoidsMl(): ?int
    {
        if ($this->poids_avant_kg === null || $this->poids_apres_kg === null) {
            return null;
        }

        return (int) round(((float) $this->poids_avant_kg - (float) $this->poids_apres_kg) * 1000);
    }

    /** Le patient est-il revenu à son poids sec ? */
    public function ecartAuPoidsSecKg(): ?float
    {
        if ($this->poids_sec_kg === null || $this->poids_apres_kg === null) {
            return null;
        }

        return round((float) $this->poids_apres_kg - (float) $this->poids_sec_kg, 2);
    }

    /**
     * Alertes de fin de séance : ce que l'infirmier doit signaler avant de
     * laisser repartir le patient.
     *
     * @return array<int, string>
     */
    public function alertes(): array
    {
        $alertes = [];

        if ($this->ta_apres_systolique !== null && $this->ta_apres_systolique < 90) {
            $alertes[] = 'Hypotension de fin de séance ('.$this->ta_apres_systolique.' mmHg)';
        }

        $ecart = $this->ecartAuPoidsSecKg();
        if ($ecart !== null && $ecart > 1.5) {
            $alertes[] = 'Poids sec non atteint : '.$ecart.' kg au-dessus';
        }

        if (filled($this->incidents)) {
            $alertes[] = 'Incident en séance : '.$this->incidents;
        }

        return $alertes;
    }

    public function estRealisee(): bool
    {
        return $this->statut === 'realisee';
    }
}
