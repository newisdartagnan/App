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
        'telephone', 'telephone_index', 'adresse', 'province', 'territoire',
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

    /**
     * L'empreinte du numéro suit le numéro, sans qu'on ait à y penser.
     *
     * Le téléphone est chiffré : on ne peut pas le chercher en base. On tient
     * donc à côté une signature qui, elle, se compare à l'identique.
     */
    protected static function booted(): void
    {
        static::saving(function (Patient $patient) {
            if ($patient->isDirty('telephone')) {
                $patient->telephone_index = self::empreinteTelephone($patient->telephone);
            }
        });
    }

    /**
     * La signature d'un numéro de téléphone, ou null s'il n'y en a pas.
     *
     * On ne garde que les chiffres qui font le numéro : indicatif du pays et
     * zéro d'appel sautent, pour que « +243 81 555 0001 », « 0815550001 » et
     * « 243815550001 » désignent bien la même personne. La signature est une
     * HMAC : sans la clé de l'application, elle ne se remonte pas — neuf
     * chiffres se retrouveraient sinon par simple énumération.
     */
    public static function empreinteTelephone(?string $telephone): ?string
    {
        $chiffres = preg_replace('/\D+/', '', (string) $telephone);

        if (str_starts_with($chiffres, '243') && mb_strlen($chiffres) > 9) {
            $chiffres = mb_substr($chiffres, 3);
        }

        $chiffres = ltrim($chiffres, '0');

        if (mb_strlen($chiffres) < 6) {
            return null;
        }

        return hash_hmac('sha256', $chiffres, (string) config('app.key'));
    }

    /**
     * Le terme cherché ressemble-t-il à un numéro qu'on aurait recopié ?
     */
    public static function ressembleAUnTelephone(string $terme): bool
    {
        return self::empreinteTelephone($terme) !== null
            && preg_match('/^[\d\s.+()-]+$/', trim($terme)) === 1;
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
