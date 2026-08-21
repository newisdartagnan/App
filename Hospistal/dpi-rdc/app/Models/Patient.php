<?php

namespace App\Models;

use App\Models\Concerns\Syncable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Patient extends Model
{
    use HasUuids, SoftDeletes, Syncable;

    protected $fillable = [
        'establishment_id', 'dossier_number', 'global_patient_id',
        'nom', 'postnom', 'prenom', 'nom_soundex', 'prenom_soundex',
        'date_naissance', 'lieu_naissance', 'sexe', 'nationalite',
        'telephone', 'adresse', 'province', 'territoire',
        'profession', 'situation_matrimoniale', 'niveau_instruction',
        'contact_urgence_nom', 'contact_urgence_telephone', 'contact_urgence_lien',
        'type_prise_en_charge', 'assurance_nom', 'assurance_numero', 'groupe_sanguin',
        'duplicate_of', 'duplicate_confidence', 'merge_status',
        'sync_status', 'sync_hash',
    ];

    protected function casts(): array
    {
        return [
            'date_naissance' => 'date',
            'duplicate_confidence' => 'decimal:2',
            'telephone' => 'encrypted',
            'adresse' => 'encrypted',
            'contact_urgence_telephone' => 'encrypted',
        ];
    }

    public function establishment(): BelongsTo
    {
        return $this->belongsTo(Establishment::class);
    }

    public function visits(): HasMany
    {
        return $this->hasMany(Visit::class);
    }

    public function referentielsMedicaux(): HasMany
    {
        return $this->hasMany(PatientReferentielMedical::class);
    }

    public function documentsCliniques(): HasMany
    {
        return $this->hasMany(DocumentClinique::class);
    }

    public function rendezVous(): HasMany
    {
        return $this->hasMany(RendezVous::class);
    }

    public function assurances(): HasMany
    {
        return $this->hasMany(PatientAssurance::class);
    }

    /**
     * Contrat d'assurance en vigueur du patient, s'il en a un.
     *
     * On retient la couverture active de l'année en cours ; à défaut, la
     * dernière souscrite. Une police échue ne couvre plus rien.
     */
    public function assuranceEnVigueur(): ?PatientAssurance
    {
        if ($this->type_prise_en_charge !== 'assurance') {
            return null;
        }

        return $this->assurances()
            ->with('assurance')
            ->where('est_actif', true)
            ->where(fn ($q) => $q->whereNull('date_fin')->orWhereDate('date_fin', '>=', now()))
            ->orderByDesc('annee_courante')
            ->first();
    }

    /**
     * Prise en charge telle qu'elle doit figurer sur un bon ou un bulletin :
     * le nom de la société et le numéro de police, et non le mot « Assurance ».
     */
    public function libellePriseEnCharge(): string
    {
        if ($this->type_prise_en_charge === 'assurance') {
            $lien = $this->assuranceEnVigueur();
            $nom = $lien?->assurance?->nom ?: $this->assurance_nom;

            if (filled($nom)) {
                $numero = $lien?->numero_police ?: $this->assurance_numero;

                return filled($numero) ? $nom.' — n° '.$numero : $nom;
            }
        }

        return Facture::PRISES_EN_CHARGE[$this->type_prise_en_charge]
            ?? ucfirst((string) $this->type_prise_en_charge);
    }

    /** Le patient est-il couvert par une société conventionnée ? */
    public function estAssure(): bool
    {
        return $this->type_prise_en_charge === 'assurance';
    }

    public function getNomCompletAttribute(): string
    {
        return trim($this->nom.' '.($this->postnom ? $this->postnom.' ' : '').$this->prenom);
    }

    protected function getSyncPriority(): int
    {
        return 8;
    }
}
