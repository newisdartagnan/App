<?php

namespace App\Services;

use App\Models\MesureAnthropometrique;
use App\Models\Patient;
use App\Models\PriseEnChargeNutritionnelle;
use App\Models\Visit;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * La prise en charge nutritionnelle : mesurer, admettre, décharger, compter.
 *
 * Le fil de la journée : on pèse et on mesure le bras, l'application dit ce
 * que la mesure établit, et propose l'unité qui convient. L'admission reste
 * la décision du soignant — c'est lui qui sait si l'enfant tousse, s'il
 * vomit tout ce qu'il avale, s'il y a quelqu'un pour le ramener mardi
 * prochain. L'application ne fait qu'éviter qu'il se trompe d'unité par
 * distraction, et qu'il ait à recompter tout cela le 30 du mois.
 */
class NutritionService
{
    /**
     * Enregistre une mesure et, si elle le justifie, dit ce qu'elle appelle.
     */
    public function mesurer(Patient $patient, array $donnees, ?Visit $visite = null): MesureAnthropometrique
    {
        return MesureAnthropometrique::create([
            'patient_id' => $patient->id,
            'visit_id' => $visite?->id,
            'user_id' => auth()->id(),
            'mesure_a' => $donnees['mesure_a'] ?? now(),
            'poids_kg' => $donnees['poids_kg'] ?? null,
            'taille_cm' => $donnees['taille_cm'] ?? null,
            'position_taille' => $donnees['position_taille'] ?? null,
            'perimetre_brachial_mm' => $donnees['perimetre_brachial_mm'] ?? null,
            'oedemes' => $donnees['oedemes'] ?? 'aucun',
            'z_score_pt' => $donnees['z_score_pt'] ?? null,
            'observation' => $donnees['observation'] ?? null,
        ]);
    }

    /**
     * L'unité que cette mesure appelle, et pourquoi.
     *
     * Rien n'est admis d'office : c'est une proposition, que l'écran
     * présente et que le soignant confirme ou écarte. La complication,
     * elle, ne se lit sur aucun ruban — c'est elle qui départage l'UNTA de
     * l'UNTI, et elle ne peut venir que du soignant.
     *
     * @return array{unite: ?string, critere: ?string, etat: string, message: string}
     */
    public function orientation(MesureAnthropometrique $mesure): array
    {
        $etat = $mesure->etatNutritionnel();

        if ($etat === 'severe') {
            return [
                'unite' => 'unta',
                'critere' => $mesure->critereDadmission(),
                'etat' => $etat,
                'message' => 'Malnutrition aiguë sévère. Sans complication, c\'est l\'UNTA ; '
                    .'avec une complication médicale, c\'est l\'UNTI — cela, seul l\'examen le dit.',
            ];
        }

        if ($etat === 'modere') {
            return [
                'unite' => 'uns',
                'critere' => 'mam_depistee',
                'etat' => $etat,
                'message' => 'Malnutrition aiguë modérée : supplémentation à l\'UNS.',
            ];
        }

        if ($etat === 'inconnu') {
            return [
                'unite' => null,
                'critere' => null,
                'etat' => $etat,
                'message' => 'Ni périmètre brachial ni score P/T : l\'état nutritionnel '
                    .'ne peut pas être établi à partir de cette mesure.',
            ];
        }

        return [
            'unite' => null,
            'critere' => null,
            'etat' => $etat,
            'message' => 'État nutritionnel normal : pas d\'admission.',
        ];
    }

    /**
     * Admet un patient dans une unité nutritionnelle.
     *
     * Un même patient ne peut pas être suivi deux fois en même temps : on
     * retrouverait deux fois la même entrée dans le rapport, et deux fois
     * la même issue.
     */
    public function admettre(
        Patient $patient,
        string $unite,
        string $critere,
        array $options = [],
    ): PriseEnChargeNutritionnelle {
        if (! isset(PriseEnChargeNutritionnelle::UNITES[$unite])) {
            throw new \InvalidArgumentException('Unité nutritionnelle inconnue : '.$unite);
        }

        if (! isset(PriseEnChargeNutritionnelle::CRITERES[$critere])) {
            throw new \InvalidArgumentException('Motif d\'admission inconnu : '.$critere);
        }

        // Les motifs ne circulent pas d'une unité à l'autre : « MAM
        // dépistée » n'est pas une entrée thérapeutique, et « œdèmes » n'est
        // pas une entrée en supplémentation. Le canevas les compte sur des
        // lignes différentes ; les mélanger ferait un rapport faux.
        $reserveALuns = in_array($critere, PriseEnChargeNutritionnelle::CRITERES_UNS, true);

        if ($reserveALuns !== ($unite === 'uns')) {
            throw new \InvalidArgumentException(
                'Le motif « '.PriseEnChargeNutritionnelle::CRITERES[$critere]
                .' » n\'appartient pas à l\''.PriseEnChargeNutritionnelle::UNITES[$unite]['sigle'].'.'
            );
        }

        return DB::transaction(function () use ($patient, $unite, $critere, $options) {
            $enCours = $this->suiviEnCours($patient);

            if ($enCours) {
                throw new \RuntimeException(
                    'Ce patient est déjà suivi à l\''.$enCours->sigle()
                    .' depuis le '.$enCours->date_admission->format('d/m/Y').'.'
                );
            }

            return PriseEnChargeNutritionnelle::create([
                'patient_id' => $patient->id,
                'establishment_id' => $patient->establishment_id,
                'visit_id' => $options['visit_id'] ?? null,
                'user_id' => auth()->id(),
                'unite' => $unite,
                'date_admission' => $options['date_admission'] ?? now()->toDateString(),
                'critere_admission' => $critere,
                // L'UNTI ne prend que le compliqué : le canevas ne lui
                // connaît aucune ligne sans complication.
                'avec_complication' => $unite === 'unti'
                    ? true
                    : (bool) ($options['avec_complication'] ?? false),
                'mesure_id' => $options['mesure_id'] ?? null,
                'groupe_specifique' => $options['groupe_specifique'] ?? null,
                'observation' => $options['observation'] ?? null,
            ]);
        });
    }

    /** Le suivi nutritionnel ouvert de ce patient, s'il y en a un. */
    public function suiviEnCours(Patient $patient): ?PriseEnChargeNutritionnelle
    {
        return PriseEnChargeNutritionnelle::where('patient_id', $patient->id)
            ->whereNull('date_sortie')
            ->orderByDesc('date_admission')
            ->first();
    }

    /**
     * Décharge un suivi.
     *
     * L'issue doit appartenir à l'unité : l'UNTA réfère vers le haut,
     * l'UNTI contre-réfère vers le bas, et les confondre ferait un rapport
     * faux dans les deux sens.
     */
    public function decharger(
        PriseEnChargeNutritionnelle $suivi,
        string $issue,
        ?string $dateSortie = null,
        ?string $observation = null,
    ): PriseEnChargeNutritionnelle {
        if (! isset(PriseEnChargeNutritionnelle::ISSUES[$suivi->unite][$issue])) {
            throw new \InvalidArgumentException(
                'Issue « '.$issue.' » inconnue pour l\''.$suivi->sigle().'.'
            );
        }

        if (! $suivi->estEnCours()) {
            throw new \RuntimeException('Ce suivi est déjà clos.');
        }

        $suivi->update([
            'date_sortie' => $dateSortie ?: now()->toDateString(),
            'issue' => $issue,
            'sortie_par' => auth()->id(),
            'observation' => $observation ?: $suivi->observation,
        ]);

        return $suivi->fresh(['patient']);
    }

    // ═══════════════════════════════════════════════════════════
    // Ce que le canevas demande
    // ═══════════════════════════════════════════════════════════

    /**
     * La section nutritionnelle du rapport mensuel.
     *
     * Chaque unité rend ses entrées et ses issues ventilées par sexe et par
     * tranche, plus le report du mois précédent — c'est-à-dire les suivis
     * déjà ouverts au premier du mois. Le canevas le demande en tête de
     * tableau et il ne se retrouve nulle part ailleurs.
     *
     * @return array<string, mixed>
     */
    public function rapportMensuel(Carbon $debut, Carbon $fin, ?string $etablissementId, array $unites): array
    {
        $sections = [];

        foreach ($unites as $unite) {
            $sections[$unite] = [
                'definition' => PriseEnChargeNutritionnelle::UNITES[$unite],
                'report' => $this->ventiler($this->reportDebutDeMois($debut, $etablissementId, $unite)),
                'entrees' => $this->entrees($debut, $fin, $etablissementId, $unite),
                'issues' => $this->issues($debut, $fin, $etablissementId, $unite),
            ];
        }

        return [
            'unites' => $sections,
            'groupes_specifiques' => in_array('uns', $unites, true)
                ? $this->groupesSpecifiques($debut, $fin, $etablissementId)
                : [],
            'mesures' => $this->mesuresDuMois($debut, $fin, $etablissementId),
        ];
    }

    /** Les suivis déjà ouverts au premier du mois. */
    private function reportDebutDeMois(Carbon $debut, ?string $etablissementId, string $unite): Collection
    {
        return PriseEnChargeNutritionnelle::query()
            ->with('patient')
            ->where('unite', $unite)
            ->when($etablissementId, fn ($q) => $q->where('establishment_id', $etablissementId))
            ->whereDate('date_admission', '<', $debut)
            // Encore ouvert, ou clos seulement après le premier du mois :
            // dans les deux cas il était là au matin du 1er.
            ->where(fn ($q) => $q->whereNull('date_sortie')->orWhereDate('date_sortie', '>=', $debut))
            ->get();
    }

    /** @return array<string, mixed> */
    private function entrees(Carbon $debut, Carbon $fin, ?string $etablissementId, string $unite): array
    {
        $suivis = PriseEnChargeNutritionnelle::query()
            ->with('patient')
            ->where('unite', $unite)
            ->when($etablissementId, fn ($q) => $q->where('establishment_id', $etablissementId))
            ->whereBetween('date_admission', [$debut->toDateString(), $fin->toDateString()])
            ->get();

        $criteres = $unite === 'uns'
            ? PriseEnChargeNutritionnelle::CRITERES_UNS
            : ['pt_pb', 'oedemes', 'rechute', 'autre'];

        $lignes = [];

        foreach ($criteres as $critere) {
            $lignes[$critere] = [
                'libelle' => PriseEnChargeNutritionnelle::CRITERES[$critere],
                'ventilation' => $this->ventiler($suivis->where('critere_admission', $critere)),
            ];
        }

        return ['lignes' => $lignes, 'total' => $suivis->count()];
    }

    /** @return array<string, mixed> */
    private function issues(Carbon $debut, Carbon $fin, ?string $etablissementId, string $unite): array
    {
        $suivis = PriseEnChargeNutritionnelle::query()
            ->with('patient')
            ->where('unite', $unite)
            ->when($etablissementId, fn ($q) => $q->where('establishment_id', $etablissementId))
            ->whereNotNull('date_sortie')
            ->whereBetween('date_sortie', [$debut->toDateString(), $fin->toDateString()])
            ->get();

        $lignes = [];

        foreach (PriseEnChargeNutritionnelle::ISSUES[$unite] as $issue => $libelle) {
            $lignes[$issue] = [
                'libelle' => $libelle,
                'ventilation' => $this->ventiler($suivis->where('issue', $issue)),
            ];
        }

        return [
            'lignes' => $lignes,
            'total' => $suivis->count(),
            // Le taux de guérison est l'indicateur que la zone regarde en
            // premier ; le calculer ici évite qu'il se calcule de travers
            // sur un coin de table.
            'taux_guerison' => $suivis->count() > 0
                ? round($suivis->where('issue', 'gueri')->count() / $suivis->count() * 100, 1)
                : null,
        ];
    }

    /**
     * Ventile un ensemble de suivis par sexe et par tranche d'âge.
     *
     * @return array<string, mixed>
     */
    private function ventiler(Collection $suivis): array
    {
        $ventilation = [];

        foreach (PriseEnChargeNutritionnelle::TRANCHES as $cle => $tranche) {
            $ventilation[$cle] = ['libelle' => $tranche['libelle'], 'f' => 0, 'm' => 0];
        }

        $horsTranche = 0;

        foreach ($suivis as $suivi) {
            $tranche = $suivi->tranche();

            if ($tranche === null) {
                // Moins de six mois, ou date de naissance inconnue : compté
                // à part plutôt que rangé de force dans une case.
                $horsTranche++;

                continue;
            }

            $ventilation[$tranche][$suivi->patient?->sexe === 'M' ? 'm' : 'f']++;
        }

        return [
            'tranches' => $ventilation,
            'hors_tranche' => $horsTranche,
            'total' => $suivis->count(),
        ];
    }

    /**
     * Les groupes que l'UNS suit à part, en nouveaux et anciens cas.
     *
     * @return array<string, mixed>
     */
    private function groupesSpecifiques(Carbon $debut, Carbon $fin, ?string $etablissementId): array
    {
        $suivis = PriseEnChargeNutritionnelle::query()
            ->where('unite', 'uns')
            ->whereNotNull('groupe_specifique')
            ->when($etablissementId, fn ($q) => $q->where('establishment_id', $etablissementId))
            ->get();

        $lignes = [];

        foreach (PriseEnChargeNutritionnelle::GROUPES_SPECIFIQUES as $cle => $libelle) {
            $duGroupe = $suivis->where('groupe_specifique', $cle);

            $lignes[$cle] = [
                'libelle' => $libelle,
                // Nouveau : admis dans le mois. Ancien : admis avant et
                // toujours suivi. C'est la lecture du canevas.
                'nouveaux' => $duGroupe
                    ->filter(fn ($s) => $s->date_admission->between($debut, $fin))
                    ->count(),
                'anciens' => $duGroupe
                    ->filter(fn ($s) => $s->date_admission->lessThan($debut)
                        && ($s->date_sortie === null || $s->date_sortie->greaterThanOrEqualTo($debut)))
                    ->count(),
            ];
        }

        return $lignes;
    }

    /**
     * Le dépistage du mois : ce qui a été mesuré, et ce qu'on y a trouvé.
     *
     * Cette ligne n'est pas sur le canevas, mais elle dit si le dépistage
     * tourne. Un mois sans mesure et sans admission ne veut pas dire un
     * mois sans malnutrition.
     *
     * @return array<string, mixed>
     */
    private function mesuresDuMois(Carbon $debut, Carbon $fin, ?string $etablissementId): array
    {
        $mesures = MesureAnthropometrique::query()
            ->whereBetween('mesure_a', [$debut, $fin])
            ->when($etablissementId, fn ($q) => $q->whereHas(
                'patient', fn ($p) => $p->where('establishment_id', $etablissementId)
            ))
            ->get();

        return [
            'total' => $mesures->count(),
            'severes' => $mesures->filter(fn ($m) => $m->etatNutritionnel() === 'severe')->count(),
            'moderes' => $mesures->filter(fn ($m) => $m->etatNutritionnel() === 'modere')->count(),
            'avec_oedemes' => $mesures->filter->aDesOedemes()->count(),
        ];
    }
}
