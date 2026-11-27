<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Empreinte digitale d'un patient, capturée par le lecteur du poste. */
class EmpreintePatient extends Model
{
    use HasUuids;

    protected $table = 'empreintes_patient';

    protected $fillable = ['patient_id', 'doigt', 'image_path', 'gabarit', 'user_id'];

    public const DOIGTS = [
        'pouce_droit' => 'Pouce droit', 'index_droit' => 'Index droit', 'majeur_droit' => 'Majeur droit',
        'annulaire_droit' => 'Annulaire droit', 'auriculaire_droit' => 'Auriculaire droit',
        'pouce_gauche' => 'Pouce gauche', 'index_gauche' => 'Index gauche', 'majeur_gauche' => 'Majeur gauche',
        'annulaire_gauche' => 'Annulaire gauche', 'auriculaire_gauche' => 'Auriculaire gauche',
    ];

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
