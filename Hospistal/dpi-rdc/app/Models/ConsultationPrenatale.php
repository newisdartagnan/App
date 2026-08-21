<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Une consultation prénatale : une ligne du carnet de suivi.
 */
class ConsultationPrenatale extends Model
{
    use HasUuids;

    protected $table = 'consultations_prenatales';

    protected $fillable = [
        'grossesse_id', 'visit_id', 'user_id', 'date_consultation', 'numero',
        'terme_semaines', 'poids_kg', 'tension_systolique', 'tension_diastolique',
        'hauteur_uterine_cm', 'bruits_coeur_foetal', 'presentation',
        'oedemes', 'albuminurie', 'glycosurie', 'hemoglobine',
        'vat_dose', 'fer_folates', 'sulfadoxine_pyrimethamine', 'moustiquaire_remise',
        'observations', 'conduite_a_tenir', 'prochain_rendez_vous',
    ];

    protected function casts(): array
    {
        return [
            'date_consultation' => 'datetime',
            'prochain_rendez_vous' => 'date',
            'poids_kg' => 'decimal:2',
            'hauteur_uterine_cm' => 'decimal:1',
            'hemoglobine' => 'decimal:1',
            'fer_folates' => 'boolean',
            'sulfadoxine_pyrimethamine' => 'boolean',
            'moustiquaire_remise' => 'boolean',
        ];
    }

    public function grossesse(): BelongsTo
    {
        return $this->belongsTo(Grossesse::class);
    }

    public function soignant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function tension(): ?string
    {
        if (! $this->tension_systolique || ! $this->tension_diastolique) {
            return null;
        }

        return $this->tension_systolique.'/'.$this->tension_diastolique.' mmHg';
    }

    /**
     * Signes qui doivent faire réagir avant que la patiente ne reparte.
     *
     * Ce ne sont pas des diagnostics mais des alertes : une tension à 150/95
     * avec de l'albumine, c'est une pré-éclampsie jusqu'à preuve du contraire.
     *
     * @return array<int, string>
     */
    public function alertes(): array
    {
        $alertes = [];

        if ($this->tension_systolique >= 140 || $this->tension_diastolique >= 90) {
            $alertes[] = 'Tension élevée ('.$this->tension().') — rechercher une pré-éclampsie';
        }

        if (in_array($this->albuminurie, ['+', '++', '+++'], true)) {
            $alertes[] = 'Albuminurie '.$this->albuminurie.' — protéinurie à confirmer';
        }

        if ($this->hemoglobine !== null && (float) $this->hemoglobine < 11) {
            $alertes[] = 'Anémie (Hb '.($this->hemoglobine + 0).' g/dL) — fer et bilan';
        }

        if ($this->bruits_coeur_foetal !== null
            && ($this->bruits_coeur_foetal < 110 || $this->bruits_coeur_foetal > 160)) {
            $alertes[] = 'Rythme cardiaque fœtal anormal ('.$this->bruits_coeur_foetal.' bpm)';
        }

        if (in_array($this->oedemes, ['++', '+++'], true)) {
            $alertes[] = 'Œdèmes importants';
        }

        return $alertes;
    }
}
