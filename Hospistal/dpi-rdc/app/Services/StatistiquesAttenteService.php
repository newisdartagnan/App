<?php

namespace App\Services;

use App\Models\Visit;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * L'attente à l'échelle de l'hôpital.
 *
 * La chronologie dit ce qu'un patient a attendu. Elle ne dit pas qu'il manque
 * un caissier le lundi matin. Pour cela il faut empiler les parcours et
 * regarder où les minutes s'accumulent : quel poste, quel jour, quelle heure.
 *
 * On ne moyenne pas aveuglément. La moyenne d'attente se laisse tirer par un
 * dossier oublié tout un après-midi ; la médiane dit ce que vit le patient
 * ordinaire. Les deux sont données côte à côte, et quand elles s'écartent,
 * c'est le signe qu'il faut aller voir les cas extrêmes plutôt que le total.
 */
class StatistiquesAttenteService
{
    /**
     * Au-delà, on plafonne : reconstituer dix mille parcours ferait attendre
     * l'écran qui mesure l'attente.
     */
    public const PLAFOND = 1500;

    public const JOURS = [
        1 => 'Lundi', 2 => 'Mardi', 3 => 'Mercredi', 4 => 'Jeudi',
        5 => 'Vendredi', 6 => 'Samedi', 0 => 'Dimanche',
    ];

    public function __construct(private readonly ParcoursTemporelService $parcours) {}

    /**
     * Tout ce que la période dit de l'attente.
     *
     * @return array<string, mixed>
     */
    public function analyse(string $debut, string $fin, ?string $etablissementId = null, ?string $type = null): array
    {
        $depuis = Carbon::parse($debut)->startOfDay();
        $jusqua = Carbon::parse($fin)->endOfDay();

        $visites = $this->visites($depuis, $jusqua, $etablissementId, $type);
        $attentes = $this->attentes($visites);

        return [
            'debut' => $depuis,
            'fin' => $jusqua,
            'type' => $type,
            'visites' => $visites->count(),
            'plafonne' => $visites->count() >= self::PLAFOND,
            'mesurables' => $attentes->pluck('visite_id')->unique()->count(),
            'total_attente' => $attentes->sum('minutes'),
            'global' => $this->resume($attentes->pluck('minutes')),
            'par_poste' => $this->parPoste($attentes),
            'par_jour_semaine' => $this->parJourSemaine($attentes),
            'par_heure' => $this->parHeure($attentes),
            'par_jour' => $this->parJour($attentes),
            'creneaux_noirs' => $this->creneauxNoirs($attentes),
            'pires' => $this->pires($attentes),
        ];
    }

    /**
     * Séjours de la période, avec de quoi reconstituer leur parcours.
     *
     * @return Collection<int, Visit>
     */
    private function visites(Carbon $depuis, Carbon $jusqua, ?string $etablissementId, ?string $type): Collection
    {
        return Visit::query()
            ->with([
                'patient', 'user', 'triagePar', 'medecinConsultant', 'consultations.user',
                'factures.paiements.caissier',
                'examensLaboratoire.prescripteur', 'examensLaboratoire.laborantin',
                'actesCliniques.operateur',
            ])
            ->whereBetween('date_entree', [$depuis, $jusqua])
            ->when($etablissementId, fn ($q) => $q->where('establishment_id', $etablissementId))
            ->when($type, fn ($q) => $q->where('type', $type))
            ->orderBy('date_entree')
            ->limit(self::PLAFOND)
            ->get();
    }

    /**
     * Toutes les attentes de la période, une ligne par intervalle.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function attentes(Collection $visites): Collection
    {
        $lignes = collect();

        foreach ($visites as $visite) {
            foreach ($this->parcours->segments($visite)->filter->attente as $segment) {
                $lignes->push([
                    'visite_id' => $visite->id,
                    'visite' => $visite,
                    'poste' => $segment['poste'],
                    'minutes' => $segment['minutes'],
                    'depuis' => $segment['depuis'],
                    'jour' => $segment['depuis']->toDateString(),
                    'jour_semaine' => $segment['depuis']->dayOfWeek,
                    'heure' => (int) $segment['depuis']->format('G'),
                ]);
            }
        }

        return $lignes;
    }

    /**
     * Moyenne, médiane et pire cas d'une série de minutes.
     *
     * @param  Collection<int, int>  $minutes
     * @return array<string, int>
     */
    private function resume(Collection $minutes): array
    {
        if ($minutes->isEmpty()) {
            return ['nombre' => 0, 'moyenne' => 0, 'mediane' => 0, 'pire' => 0, 'total' => 0];
        }

        $triees = $minutes->sort()->values();
        $milieu = intdiv($triees->count(), 2);

        return [
            'nombre' => $triees->count(),
            'moyenne' => (int) round($triees->avg()),
            // La moyenne se laisse tirer par un dossier oublié un après-midi ;
            // la médiane dit ce que vit le patient ordinaire.
            'mediane' => $triees->count() % 2 === 0
                ? (int) round(($triees[$milieu - 1] + $triees[$milieu]) / 2)
                : (int) $triees[$milieu],
            'pire' => (int) $triees->last(),
            'total' => (int) $triees->sum(),
        ];
    }

    /** @return Collection<string, array<string, mixed>> */
    private function parPoste(Collection $attentes): Collection
    {
        return collect(ParcoursTemporelService::POSTES)
            ->map(function (string $libelle, string $cle) use ($attentes) {
                $lignes = $attentes->where('poste', $cle);

                return ['libelle' => $libelle] + $this->resume($lignes->pluck('minutes'));
            })
            ->filter(fn ($ligne) => $ligne['nombre'] > 0)
            ->sortByDesc('total');
    }

    /** @return Collection<string, array<string, mixed>> */
    private function parJourSemaine(Collection $attentes): Collection
    {
        // Indexé par le nom du jour : c'est ainsi qu'on le cherche.
        return collect(self::JOURS)
            ->mapWithKeys(function (string $libelle, int $jour) use ($attentes) {
                $lignes = $attentes->where('jour_semaine', $jour);

                return [$libelle => ['libelle' => $libelle] + $this->resume($lignes->pluck('minutes'))];
            })
            ->filter(fn ($ligne) => $ligne['nombre'] > 0);
    }

    /** @return Collection<int, array<string, mixed>> */
    private function parHeure(Collection $attentes): Collection
    {
        return collect(range(0, 23))
            ->mapWithKeys(function (int $heure) use ($attentes) {
                $lignes = $attentes->where('heure', $heure);

                return [$heure => ['libelle' => sprintf('%02dh', $heure)] + $this->resume($lignes->pluck('minutes'))];
            })
            ->filter(fn ($ligne) => $ligne['nombre'] > 0);
    }

    /** @return Collection<string, array<string, mixed>> */
    private function parJour(Collection $attentes): Collection
    {
        return $attentes->groupBy('jour')
            ->map(fn ($lignes) => $this->resume($lignes->pluck('minutes')))
            ->sortKeys();
    }

    /**
     * Les créneaux où l'attente s'accumule : jour de la semaine × heure.
     *
     * C'est la réponse à « il manque un caissier le lundi matin » — non pas
     * une intuition, mais un croisement.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function creneauxNoirs(Collection $attentes): Collection
    {
        return $attentes
            ->groupBy(fn ($a) => $a['jour_semaine'].'|'.$a['heure'].'|'.$a['poste'])
            ->map(function (Collection $lignes, string $cle) {
                [$jour, $heure, $poste] = explode('|', $cle);

                return [
                    'jour' => self::JOURS[(int) $jour] ?? $jour,
                    'heure' => sprintf('%02dh', (int) $heure),
                    'poste' => ParcoursTemporelService::POSTES[$poste] ?? $poste,
                    // Deux patients qui attendent dix minutes ne font pas un
                    // problème ; huit qui en attendent quarante, si.
                    'patients' => $lignes->pluck('visite_id')->unique()->count(),
                ] + $this->resume($lignes->pluck('minutes'));
            })
            ->filter(fn ($c) => $c['patients'] >= 2)
            ->sortByDesc('total')
            ->take(12)
            ->values();
    }

    /**
     * Les attentes les plus longues, nommées.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function pires(Collection $attentes): Collection
    {
        return $attentes->sortByDesc('minutes')->take(10)->map(fn ($a) => [
            'minutes' => $a['minutes'],
            'poste' => ParcoursTemporelService::POSTES[$a['poste']] ?? $a['poste'],
            'quand' => $a['depuis'],
            'visite' => $a['visite'],
            'patient' => $a['visite']->patient,
        ])->values();
    }
}
