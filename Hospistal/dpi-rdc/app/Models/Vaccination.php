<?php

namespace App\Models;

use App\Models\Concerns\Syncable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Une dose, un antigène, un enfant, un jour.
 *
 * Le canevas demande des totaux par antigène et par stratégie. On pourrait
 * les saisir tels quels, en fin de mois, à partir des bâtons de la fiche de
 * pointage. On enregistre plutôt chaque dose sur un enfant nommé, pour deux
 * raisons : le carnet se reconstitue quand la mère l'a perdu, et on sait
 * lesquels ont décroché entre la première et la troisième dose — ce qu'un
 * total mensuel ne dira jamais.
 *
 * Source : canevas mensuel du SNIS du 12 octobre 2024 — CS § 8.4 et § 8.4.2.
 */
class Vaccination extends Model
{
    use HasUuids, Syncable;

    protected $table = 'vaccinations';

    protected $fillable = [
        'patient_id', 'establishment_id', 'user_id',
        'date_vaccination', 'antigene', 'strategie', 'lot', 'observation',
    ];

    protected function casts(): array
    {
        return ['date_vaccination' => 'date'];
    }

    /**
     * Les antigènes du calendrier, dans l'ordre du canevas.
     *
     * `age` est l'âge auquel la dose est due, tel qu'il figure au
     * calendrier national. Il ne bloque rien — un rattrapage est une bonne
     * nouvelle, pas une erreur de saisie — mais l'écran s'en sert pour dire
     * ce qui est en retard.
     */
    public const ANTIGENES = [
        'bcg' => ['libelle' => 'BCG', 'age_semaines' => 0],
        'vpo_0' => ['libelle' => 'VPO 0', 'age_semaines' => 0],
        'vpo_1' => ['libelle' => 'VPO 1', 'age_semaines' => 6],
        'vpo_2' => ['libelle' => 'VPO 2', 'age_semaines' => 10],
        'vpo_3' => ['libelle' => 'VPO 3', 'age_semaines' => 14],
        'vpi_1' => ['libelle' => 'VPI 1', 'age_semaines' => 14],
        'vpi_2' => ['libelle' => 'VPI 2', 'age_semaines' => 36],
        'dtc_hepb_hib_1' => ['libelle' => 'DTC-HepB-Hib 1', 'age_semaines' => 6],
        'dtc_hepb_hib_2' => ['libelle' => 'DTC-HepB-Hib 2', 'age_semaines' => 10],
        'dtc_hepb_hib_3' => ['libelle' => 'DTC-HepB-Hib 3', 'age_semaines' => 14],
        'pcv13_1' => ['libelle' => 'PCV-13 1', 'age_semaines' => 6],
        'pcv13_2' => ['libelle' => 'PCV-13 2', 'age_semaines' => 10],
        'pcv13_3' => ['libelle' => 'PCV-13 3', 'age_semaines' => 14],
        'rota_1' => ['libelle' => 'Rota 1', 'age_semaines' => 6],
        'rota_2' => ['libelle' => 'Rota 2', 'age_semaines' => 10],
        'rota_3' => ['libelle' => 'Rota 3', 'age_semaines' => 14],
        'vaa' => ['libelle' => 'VAA (fièvre jaune)', 'age_semaines' => 39],
        'var_rr_1' => ['libelle' => 'VAR 1 / RR 1', 'age_semaines' => 39],
        'var_rr_2' => ['libelle' => 'VAR 2 / RR 2', 'age_semaines' => 65],
        'vap_1' => ['libelle' => 'VAP 1', 'age_semaines' => 6],
        'vap_2' => ['libelle' => 'VAP 2', 'age_semaines' => 10],
        'vap_3' => ['libelle' => 'VAP 3', 'age_semaines' => 14],
        'vap_4' => ['libelle' => 'VAP 4', 'age_semaines' => 39],
        'hpv' => ['libelle' => 'HPV', 'age_semaines' => 468],
    ];

    /**
     * Ce qu'il faut pour être « complètement vacciné ».
     *
     * Le canevas demande le nombre d'ECV sans dire ce qu'il met dedans.
     * Cette liste est donc une règle de l'application, pas du formulaire :
     * elle est écrite ici pour qu'on puisse la lire, la discuter et la
     * corriger, plutôt qu'enfouie dans une requête. L'écran l'affiche.
     */
    public const SCHEMA_COMPLET = [
        'bcg', 'vpo_1', 'vpo_2', 'vpo_3',
        'dtc_hepb_hib_1', 'dtc_hepb_hib_2', 'dtc_hepb_hib_3',
        'pcv13_1', 'pcv13_2', 'pcv13_3',
        'var_rr_1',
    ];

    /**
     * Les trois stratégies, et ce qu'elles coûtent.
     *
     * Le canevas les sépare sur chaque ligne : une dose au poste fixe et
     * une dose posée à quinze kilomètres en équipe mobile ne se comparent
     * pas, et c'est l'écart entre les trois colonnes qui dit si la zone est
     * réellement couverte.
     */
    public const STRATEGIES = [
        'fixe' => 'Fixe — au centre de santé',
        'avance' => 'Avancée — site périphérique',
        'mobile' => 'Mobile — équipe en déplacement',
    ];

    /** Les tranches d'âge du canevas pour le HPV. */
    public const TRANCHES_HPV = [
        'neuf_13' => ['libelle' => '9 – 13 ans', 'min' => 9, 'max' => 13],
        'quatorze_plus' => ['libelle' => '14 ans et plus', 'min' => 14, 'max' => null],
    ];

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function libelleAntigene(): string
    {
        return self::ANTIGENES[$this->antigene]['libelle'] ?? $this->antigene;
    }

    public function libelleStrategie(): string
    {
        return self::STRATEGIES[$this->strategie] ?? $this->strategie;
    }

    /** L'âge de l'enfant, en semaines, le jour de la dose. */
    public function ageEnSemaines(): ?int
    {
        $naissance = $this->patient?->date_naissance;

        return $naissance ? (int) $naissance->diffInWeeks($this->date_vaccination) : null;
    }

    protected function getSyncPriority(): int
    {
        return 6;
    }
}
