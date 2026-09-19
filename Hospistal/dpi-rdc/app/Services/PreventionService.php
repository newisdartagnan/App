<?php

namespace App\Services;

use App\Models\ActePlanificationFamiliale;
use App\Models\Patient;
use App\Models\Vaccination;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * La planification familiale et le Programme élargi de vaccination.
 *
 * Deux registres, deux logiques, un même service parce qu'ils partagent la
 * même place dans le canevas : ce sont les deux activités préventives que
 * le rapport déclarait ne pas savoir remplir.
 *
 * Ce qui les sépare : la PF compte des actes — la même cliente revient
 * chaque mois et compte chaque fois. Le PEV compte des doses uniques — un
 * enfant ne reçoit le BCG qu'une fois, et un deuxième enregistrement est
 * une erreur de saisie qui gonflerait la couverture.
 */
class PreventionService
{
    // ═══════════════════════════════════════════════════════════
    // Planification familiale
    // ═══════════════════════════════════════════════════════════

    public function enregistrerActePf(Patient $patient, array $donnees): ActePlanificationFamiliale
    {
        $type = $donnees['type_acte'] ?? 'methode';

        if ($type === 'methode' && blank($donnees['methode'] ?? null)) {
            throw new \InvalidArgumentException('Une méthode doit être précisée.');
        }

        if ($type === 'methode' && ! isset(ActePlanificationFamiliale::METHODES[$donnees['methode']])) {
            throw new \InvalidArgumentException('Méthode inconnue : '.$donnees['methode']);
        }

        // Le sexe de la cliente doit s'accorder avec la méthode : un
        // préservatif masculin compté chez une femme déplace une ligne
        // entière du canevas.
        if ($type === 'methode') {
            $masculine = ActePlanificationFamiliale::METHODES[$donnees['methode']]['masculine'];

            if ($masculine && $patient->sexe === 'F') {
                throw new \InvalidArgumentException(
                    ActePlanificationFamiliale::METHODES[$donnees['methode']]['libelle']
                    .' ne se compte pas chez une femme.'
                );
            }

            if (! $masculine && $patient->sexe === 'M') {
                throw new \InvalidArgumentException(
                    ActePlanificationFamiliale::METHODES[$donnees['methode']]['libelle']
                    .' ne se compte pas chez un homme.'
                );
            }
        }

        return ActePlanificationFamiliale::create([
            'patient_id' => $patient->id,
            'establishment_id' => $patient->establishment_id,
            'user_id' => auth()->id(),
            'visit_id' => $donnees['visit_id'] ?? null,
            'date_acte' => $donnees['date_acte'] ?? now()->toDateString(),
            'type_acte' => $type,
            'methode' => $type === 'methode' ? $donnees['methode'] : null,
            'type_acceptation' => $donnees['type_acceptation'] ?? 'nouvelle',
            'canal' => $donnees['canal'] ?? 'ess',
            'post_partum' => (bool) ($donnees['post_partum'] ?? false),
            'observation' => $donnees['observation'] ?? null,
        ]);
    }

    /**
     * La section « planification familiale » du rapport mensuel.
     *
     * @return array<string, mixed>
     */
    public function rapportPf(Carbon $debut, Carbon $fin, ?string $etablissementId): array
    {
        $actes = ActePlanificationFamiliale::query()
            ->with('patient')
            ->whereBetween('date_acte', [$debut->toDateString(), $fin->toDateString()])
            ->when($etablissementId, fn ($q) => $q->where('establishment_id', $etablissementId))
            ->get();

        $methodes = $actes->where('type_acte', 'methode');
        $lignes = [];

        foreach (ActePlanificationFamiliale::METHODES as $cle => $methode) {
            $lignes[$cle] = [
                'libelle' => $methode['libelle'],
                'ventilation' => $this->ventilerPf($methodes->where('methode', $cle)),
            ];
        }

        return [
            'lignes' => $lignes,
            'total' => $methodes->count(),
            'nouvelles' => $methodes->where('type_acceptation', 'nouvelle')->count(),
            'renouvellements' => $methodes->where('type_acceptation', 'renouvellement')->count(),
            'par_canal' => collect(ActePlanificationFamiliale::CANAUX)
                ->mapWithKeys(fn ($libelle, $cle) => [$libelle => $methodes->where('canal', $cle)->count()]),
            // § 3.1 : le post-partum, compté à part et par canal.
            'post_partum' => [
                'avec_methode' => $this->parCanal(
                    $methodes->where('post_partum', true)->filter->estMethodeModerne()
                ),
                'conseillees' => $this->parCanal($actes->where('type_acte', 'conseil_post_partum')),
            ],
        ];
    }

    /** @return array<string, int> */
    private function parCanal(Collection $actes): array
    {
        return collect(ActePlanificationFamiliale::CANAUX)
            ->mapWithKeys(fn ($libelle, $cle) => [$cle => $actes->where('canal', $cle)->count()])
            ->all();
    }

    /**
     * Ventile des actes PF par canal et par tranche d'âge.
     *
     * @return array<string, mixed>
     */
    private function ventilerPf(Collection $actes): array
    {
        $ventilation = [];

        foreach (ActePlanificationFamiliale::CANAUX as $canal => $libelleCanal) {
            foreach (ActePlanificationFamiliale::TRANCHES as $cle => $tranche) {
                $ventilation[$canal][$cle] = 0;
            }
        }

        $horsTranche = 0;

        foreach ($actes as $acte) {
            $tranche = $acte->tranche();

            if ($tranche === null) {
                $horsTranche++;

                continue;
            }

            $canal = isset(ActePlanificationFamiliale::CANAUX[$acte->canal]) ? $acte->canal : 'ess';
            $ventilation[$canal][$tranche]++;
        }

        return [
            'canaux' => $ventilation,
            'hors_tranche' => $horsTranche,
            'total' => $actes->count(),
        ];
    }

    // ═══════════════════════════════════════════════════════════
    // Vaccination
    // ═══════════════════════════════════════════════════════════

    public function vacciner(Patient $patient, array $donnees): Vaccination
    {
        $antigene = $donnees['antigene'] ?? null;

        if (! isset(Vaccination::ANTIGENES[$antigene])) {
            throw new \InvalidArgumentException('Antigène inconnu : '.$antigene);
        }

        // Une dose ne se donne qu'une fois. Le deuxième enregistrement est
        // une erreur de saisie, et il gonflerait la couverture vaccinale.
        $deja = Vaccination::where('patient_id', $patient->id)
            ->where('antigene', $antigene)
            ->first();

        if ($deja) {
            throw new \RuntimeException(
                Vaccination::ANTIGENES[$antigene]['libelle'].' a déjà été administré le '
                .$deja->date_vaccination->format('d/m/Y').'.'
            );
        }

        return Vaccination::create([
            'patient_id' => $patient->id,
            'establishment_id' => $patient->establishment_id,
            'user_id' => auth()->id(),
            'date_vaccination' => $donnees['date_vaccination'] ?? now()->toDateString(),
            'antigene' => $antigene,
            'strategie' => $donnees['strategie'] ?? 'fixe',
            'lot' => $donnees['lot'] ?? null,
            'observation' => $donnees['observation'] ?? null,
        ]);
    }

    /**
     * Le carnet d'un enfant : ce qu'il a reçu, ce qui manque.
     *
     * C'est l'écran que la mère consulte avec l'infirmier quand elle a
     * perdu le sien.
     *
     * @return array<string, mixed>
     */
    public function carnet(Patient $patient): array
    {
        $recues = Vaccination::where('patient_id', $patient->id)
            ->orderBy('date_vaccination')
            ->get()
            ->keyBy('antigene');

        $ageSemaines = $patient->date_naissance
            ? (int) $patient->date_naissance->diffInWeeks(now())
            : null;

        $lignes = [];

        foreach (Vaccination::ANTIGENES as $cle => $antigene) {
            $dose = $recues->get($cle);

            $lignes[$cle] = [
                'libelle' => $antigene['libelle'],
                'recue' => $dose !== null,
                'date' => $dose?->date_vaccination,
                'strategie' => $dose?->libelleStrategie(),
                // « En retard » et non « manquant » : un rattrapage se fait,
                // et l'écran doit appeler à le faire plutôt qu'à constater.
                'en_retard' => $dose === null
                    && $ageSemaines !== null
                    && $ageSemaines > $antigene['age_semaines'],
                'du_a' => $antigene['age_semaines'],
            ];
        }

        $manquants = collect(Vaccination::SCHEMA_COMPLET)
            ->reject(fn ($cle) => $recues->has($cle))
            ->values();

        return [
            'lignes' => $lignes,
            'doses_recues' => $recues->count(),
            'complet' => $manquants->isEmpty(),
            'manquants_pour_etre_complet' => $manquants
                ->map(fn ($cle) => Vaccination::ANTIGENES[$cle]['libelle'])
                ->all(),
            'en_retard' => collect($lignes)->filter(fn ($l) => $l['en_retard'])->count(),
        ];
    }

    /**
     * La section « vaccination » du rapport mensuel.
     *
     * @return array<string, mixed>
     */
    public function rapportPev(Carbon $debut, Carbon $fin, ?string $etablissementId): array
    {
        $doses = Vaccination::query()
            ->with('patient')
            ->whereBetween('date_vaccination', [$debut->toDateString(), $fin->toDateString()])
            ->when($etablissementId, fn ($q) => $q->where('establishment_id', $etablissementId))
            ->get();

        $lignes = [];

        foreach (Vaccination::ANTIGENES as $cle => $antigene) {
            if ($cle === 'hpv') {
                // Le HPV a son propre tableau, ventilé par âge et non par
                // stratégie seule : il ne se range pas dans cette liste.
                continue;
            }

            $duType = $doses->where('antigene', $cle);

            $lignes[$cle] = [
                'libelle' => $antigene['libelle'],
                'strategies' => collect(Vaccination::STRATEGIES)
                    ->mapWithKeys(fn ($l, $s) => [$s => $duType->where('strategie', $s)->count()])
                    ->all(),
                'total' => $duType->count(),
            ];
        }

        return [
            'lignes' => $lignes,
            'total' => $doses->where('antigene', '!=', 'hpv')->count(),
            'hpv' => $this->hpv($doses->where('antigene', 'hpv')),
            'enfants_completement_vaccines' => $this->enfantsCompletementVaccines($debut, $fin, $etablissementId),
            'schema_complet' => collect(Vaccination::SCHEMA_COMPLET)
                ->map(fn ($cle) => Vaccination::ANTIGENES[$cle]['libelle'])
                ->all(),
        ];
    }

    /**
     * Le HPV, ventilé par tranche et par stratégie.
     *
     * @return array<string, mixed>
     */
    private function hpv(Collection $doses): array
    {
        $lignes = [];

        foreach (Vaccination::TRANCHES_HPV as $cle => $tranche) {
            $deLaTranche = $doses->filter(function (Vaccination $dose) use ($tranche) {
                $naissance = $dose->patient?->date_naissance;

                if (! $naissance) {
                    return false;
                }

                $age = (int) $naissance->diffInYears($dose->date_vaccination);

                return $age >= $tranche['min'] && ($tranche['max'] === null || $age <= $tranche['max']);
            });

            $lignes[$cle] = [
                'libelle' => $tranche['libelle'],
                'strategies' => collect(Vaccination::STRATEGIES)
                    ->mapWithKeys(fn ($l, $s) => [$s => $deLaTranche->where('strategie', $s)->count()])
                    ->all(),
                'total' => $deLaTranche->count(),
            ];
        }

        return ['lignes' => $lignes, 'total' => $doses->count()];
    }

    /**
     * Les enfants devenus complètement vaccinés dans le mois.
     *
     * On compte l'enfant au mois où il reçoit sa dernière dose manquante,
     * pas à chaque mois où il reste complet : sinon le même enfant serait
     * compté douze fois dans l'année.
     */
    private function enfantsCompletementVaccines(Carbon $debut, Carbon $fin, ?string $etablissementId): int
    {
        // Les enfants qui ont reçu au moins une dose dans le mois : eux
        // seuls peuvent avoir complété leur schéma ce mois-ci.
        $candidats = Vaccination::query()
            ->whereBetween('date_vaccination', [$debut->toDateString(), $fin->toDateString()])
            ->when($etablissementId, fn ($q) => $q->where('establishment_id', $etablissementId))
            ->pluck('patient_id')
            ->unique();

        if ($candidats->isEmpty()) {
            return 0;
        }

        $parEnfant = Vaccination::whereIn('patient_id', $candidats)
            ->whereIn('antigene', Vaccination::SCHEMA_COMPLET)
            ->get()
            ->groupBy('patient_id');

        $complets = 0;

        foreach ($parEnfant as $doses) {
            if ($doses->pluck('antigene')->unique()->count() < count(Vaccination::SCHEMA_COMPLET)) {
                continue;
            }

            // La dernière dose du schéma doit tomber dans le mois : c'est
            // elle qui fait l'enfant complet, et elle n'arrive qu'une fois.
            $derniere = $doses->max('date_vaccination');

            if ($derniere->betweenIncluded($debut, $fin)) {
                $complets++;
            }
        }

        return $complets;
    }
}
