<?php

namespace App\Services;

use App\Models\ExamenLaboratoire;
use App\Models\Facture;
use App\Models\Lit;
use App\Models\MouvementStock;
use App\Models\Visit;
use Illuminate\Support\Carbon;

/**
 * Indicateurs de pilotage. On privilégie les vues réellement exploitables au
 * quotidien — taux d'occupation, admissions, durée de séjour — plutôt qu'une
 * multiplication de croisements.
 */
class StatistiqueService
{
    /** Tranches d'âge utilisées par tous les croisements. */
    public const TRANCHES_AGE = [
        '0-4 ans' => [0, 4],
        '5-14 ans' => [5, 14],
        '15-24 ans' => [15, 24],
        '25-44 ans' => [25, 44],
        '45-64 ans' => [45, 64],
        '65 ans et +' => [65, 200],
    ];

    protected function visites(string $debut, string $fin)
    {
        return Visit::with(['patient', 'service', 'user', 'typeConsultation'])
            ->whereBetween('date_entree', [$debut . ' 00:00:00', $fin . ' 23:59:59'])
            ->where('statut', '!=', 'annule')
            ->get();
    }

    /**
     * Chiffres clés de la période.
     *
     * @return array<string, mixed>
     */
    public function synthese(string $debut, string $fin): array
    {
        $visites = $this->visites($debut, $fin);
        $hospitalisations = $visites->where('type', 'hospitalisation');

        $litsTotal = Lit::count();
        $litsOccupes = Lit::where('statut', 'occupe')->count();

        $sejoursClos = $hospitalisations->whereNotNull('date_sortie');
        $dureeMoyenne = $sejoursClos->isNotEmpty()
            ? round($sejoursClos->avg(fn ($v) => $v->joursHospitalisation()), 1)
            : 0;

        $jours = max(1, Carbon::parse($debut)->diffInDays(Carbon::parse($fin)) + 1);

        $recettes = (float) Facture::whereBetween('date_facture', [$debut . ' 00:00:00', $fin . ' 23:59:59'])
            ->where('statut', 'payee')->sum('total_ttc');
        $impayes = (float) Facture::whereBetween('date_facture', [$debut . ' 00:00:00', $fin . ' 23:59:59'])
            ->whereNotIn('statut', ['payee', 'annulee'])->sum('total_ttc');

        return [
            'admissions' => $visites->count(),
            'ambulatoires' => $visites->where('type', 'consultation_externe')->count(),
            'urgences' => $visites->where('type', 'urgence')->count(),
            'hospitalisations' => $hospitalisations->count(),
            'admissions_par_jour' => round($visites->count() / $jours, 1),
            'duree_sejour_moyenne' => $dureeMoyenne,
            'lits_total' => $litsTotal,
            'lits_occupes' => $litsOccupes,
            'taux_occupation' => $litsTotal > 0 ? round($litsOccupes / $litsTotal * 100, 1) : 0,
            'recettes' => $recettes,
            'impayes' => $impayes,
            'examens' => ExamenLaboratoire::whereBetween('date_prescription', [$debut . ' 00:00:00', $fin . ' 23:59:59'])->count(),
        ];
    }

    /**
     * Admissions jour par jour, pour la courbe d'activité.
     *
     * @return \Illuminate\Support\Collection<string, int>
     */
    public function admissionsParJour(string $debut, string $fin)
    {
        $visites = $this->visites($debut, $fin)->groupBy(fn ($v) => $v->date_entree->toDateString());

        $serie = collect();
        $curseur = Carbon::parse($debut);
        $borne = Carbon::parse($fin);

        // Les jours sans activité doivent apparaître à zéro, pas disparaître
        while ($curseur->lessThanOrEqualTo($borne) && $serie->count() < 400) {
            $jour = $curseur->toDateString();
            $serie[$jour] = $visites->get($jour)?->count() ?? 0;
            $curseur->addDay();
        }

        return $serie;
    }

    /**
     * Répartitions croisées de la période.
     *
     * @return array<string, \Illuminate\Support\Collection>
     */
    public function repartitions(string $debut, string $fin): array
    {
        $visites = $this->visites($debut, $fin);

        return [
            'type' => $visites->groupBy('type')->map->count()->sortDesc(),

            'sexe' => $visites->groupBy(fn ($v) => match ($v->patient->sexe) {
                'M' => 'Masculin', 'F' => 'Féminin', default => 'Non précisé',
            })->map->count(),

            'age' => collect(self::TRANCHES_AGE)->map(function ($bornes) use ($visites) {
                return $visites->filter(function ($v) use ($bornes) {
                    $age = $v->patient->date_naissance?->age;
                    return $age !== null && $age >= $bornes[0] && $age <= $bornes[1];
                })->count();
            }),

            'service' => $visites->whereNotNull('service_id')
                ->groupBy(fn ($v) => $v->service?->nom ?? '—')->map->count()->sortDesc(),

            'prestataire' => $visites->whereNotNull('user_id')
                ->groupBy(fn ($v) => trim(($v->user?->prenom ?? '') . ' ' . ($v->user?->nom ?? '')) ?: '—')
                ->map->count()->sortDesc()->take(10),

            'specialite' => $visites->whereNotNull('type_consultation_id')
                ->groupBy(fn ($v) => $v->typeConsultation?->libelle ?? '—')->map->count()->sortDesc(),

            'prise_en_charge' => $visites->groupBy(fn ($v) => $v->patient->type_prise_en_charge === 'assurance'
                ? ($v->patient->assurance_nom ?: 'Assurance')
                : 'Privé')->map->count()->sortDesc(),

            'heure_admission' => $visites->groupBy(fn ($v) => str_pad($v->date_entree->format('H'), 2, '0', STR_PAD_LEFT) . ' h')
                ->map->count()->sortKeys(),
        ];
    }

    /**
     * Occupation service par service.
     */
    public function occupationParService()
    {
        return \App\Models\Service::where('is_active', true)
            ->withCount('lits')
            ->orderBy('nom')
            ->get()
            ->map(function ($service) {
                $occupes = Lit::where('service_id', $service->id)->where('statut', 'occupe')->count();

                return [
                    'service' => $service,
                    'lits' => $service->lits_count,
                    'occupes' => $occupes,
                    'taux' => $service->lits_count > 0 ? round($occupes / $service->lits_count * 100, 1) : 0,
                ];
            })
            ->filter(fn ($l) => $l['lits'] > 0);
    }

    /**
     * Activité du laboratoire et de l'imagerie sur la période.
     *
     * @return array<string, \Illuminate\Support\Collection>
     */
    public function activiteLabo(string $debut, string $fin): array
    {
        $examens = ExamenLaboratoire::with(['resultats.typeExamen', 'laborantin'])
            ->whereBetween('date_prescription', [$debut . ' 00:00:00', $fin . ' 23:59:59'])
            ->get();

        $resultats = $examens->flatMap(fn ($e) => $e->resultats->unique('type_examen_id'));

        return [
            'domaine' => $examens->groupBy('domaine')->map->count(),
            'unite' => $resultats->groupBy(fn ($r) => $r->typeExamen->uniteAnalyse())->map->count()->sortDesc(),
            'test' => $resultats->groupBy(fn ($r) => $r->typeExamen->libelle)->map->count()->sortDesc()->take(10),
            'laborantin' => $examens->whereNotNull('laborantin_id')
                ->groupBy(fn ($e) => trim(($e->laborantin?->prenom ?? '') . ' ' . ($e->laborantin?->nom ?? '')))
                ->map->count()->sortDesc(),
        ];
    }

    /**
     * Consommation pharmaceutique par officine et par produit.
     *
     * @return array<string, \Illuminate\Support\Collection>
     */
    public function activitePharmacie(string $debut, string $fin): array
    {
        $mouvements = MouvementStock::with(['medicament', 'officine'])
            ->whereBetween('created_at', [$debut . ' 00:00:00', $fin . ' 23:59:59'])
            ->get();

        $sorties = $mouvements->filter(fn ($m) => str_starts_with($m->type, 'sortie'));

        return [
            'officine' => $sorties->groupBy(fn ($m) => $m->officine?->nom ?? 'Non affectée')
                ->map(fn ($g) => (float) $g->sum('quantite'))->sortDesc(),
            'produit' => $sorties->groupBy(fn ($m) => $m->medicament->denomination_commune)
                ->map(fn ($g) => (float) $g->sum('quantite'))->sortDesc()->take(10),
            'type_mouvement' => $mouvements->groupBy('type')->map->count()->sortDesc(),
        ];
    }
}
