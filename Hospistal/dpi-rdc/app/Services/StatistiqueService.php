<?php

namespace App\Services;

use App\Models\ExamenLaboratoire;
use App\Models\Facture;
use App\Models\Lit;
use App\Models\MouvementStock;
use App\Models\Service;
use App\Models\Visit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

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
            ->whereBetween('date_entree', [$debut.' 00:00:00', $fin.' 23:59:59'])
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

        $recettes = (float) Facture::whereBetween('date_facture', [$debut.' 00:00:00', $fin.' 23:59:59'])
            ->where('statut', 'payee')->sum('total_ttc');
        $impayes = (float) Facture::whereBetween('date_facture', [$debut.' 00:00:00', $fin.' 23:59:59'])
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
            'examens' => ExamenLaboratoire::whereBetween('date_prescription', [$debut.' 00:00:00', $fin.' 23:59:59'])->count(),
        ];
    }

    /**
     * Admissions jour par jour, pour la courbe d'activité.
     *
     * @return Collection<string, int>
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
     * @return array<string, Collection>
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
                ->groupBy(fn ($v) => trim(($v->user?->prenom ?? '').' '.($v->user?->nom ?? '')) ?: '—')
                ->map->count()->sortDesc()->take(10),

            'specialite' => $visites->whereNotNull('type_consultation_id')
                ->groupBy(fn ($v) => $v->typeConsultation?->libelle ?? '—')->map->count()->sortDesc(),

            'prise_en_charge' => $visites->groupBy(fn ($v) => $v->patient->type_prise_en_charge === 'assurance'
                ? ($v->patient->assurance_nom ?: 'Assurance')
                : 'Privé')->map->count()->sortDesc(),

            'heure_admission' => $visites->groupBy(fn ($v) => str_pad($v->date_entree->format('H'), 2, '0', STR_PAD_LEFT).' h')
                ->map->count()->sortKeys(),
        ];
    }

    /**
     * Occupation service par service.
     */
    public function occupationParService()
    {
        return Service::where('is_active', true)
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
     * Examens d'un plateau technique sur la période.
     *
     * Le laboratoire et l'imagerie partagent la même table mais ne se
     * regardent pas ensemble : un bilan sanguin et un scanner ne se comptent
     * ni dans la même unité ni par le même personnel.
     */
    private function examensDuDomaine(string $domaine, string $debut, string $fin)
    {
        return ExamenLaboratoire::with(['resultats.typeExamen', 'laborantin'])
            ->where('domaine', $domaine)
            ->whereBetween('date_prescription', [$debut.' 00:00:00', $fin.' 23:59:59'])
            ->get();
    }

    /** Délais moyens entre la prescription et le rendu, en heures. */
    private function delaiMoyenHeures($examens): ?float
    {
        $rendus = $examens->filter(fn ($e) => $e->date_resultat && $e->date_prescription);

        if ($rendus->isEmpty()) {
            return null;
        }

        return round($rendus->avg(fn ($e) => $e->date_prescription->diffInMinutes($e->date_resultat)) / 60, 1);
    }

    /**
     * Activité du laboratoire d'analyses sur la période.
     *
     * @return array<string, mixed>
     */
    public function activiteLabo(string $debut, string $fin): array
    {
        $examens = $this->examensDuDomaine('labo', $debut, $fin);
        $resultats = $examens->flatMap(fn ($e) => $e->resultats->unique('type_examen_id'));

        return [
            'statut' => $examens->groupBy('statut')->map->count(),
            'unite' => $resultats->groupBy(fn ($r) => $r->typeExamen?->uniteAnalyse() ?? 'Autres analyses')->map->count()->sortDesc(),
            'test' => $resultats->groupBy(fn ($r) => $r->typeExamen?->libelle ?? 'Inconnu')->map->count()->sortDesc()->take(10),
            'laborantin' => $examens->whereNotNull('laborantin_id')
                ->groupBy(fn ($e) => trim(($e->laborantin?->prenom ?? '').' '.($e->laborantin?->nom ?? '')))
                ->map->count()->sortDesc(),
            'total' => $examens->count(),
            'urgents' => $examens->where('urgence', true)->count(),
            'delai_moyen' => $this->delaiMoyenHeures($examens),
        ];
    }

    /**
     * Activité de l'imagerie médicale sur la période.
     *
     * L'imagerie ne rend pas des valeurs mais des comptes rendus : on compte
     * les examens par modalité et les comptes rendus signés, pas des dosages.
     *
     * @return array<string, mixed>
     */
    public function activiteImagerie(string $debut, string $fin): array
    {
        $examens = $this->examensDuDomaine('imagerie', $debut, $fin);
        $resultats = $examens->flatMap(fn ($e) => $e->resultats->unique('type_examen_id'));

        return [
            'statut' => $examens->groupBy('statut')->map->count(),
            'modalite' => $resultats->groupBy(fn ($r) => $r->typeExamen?->libelleModalite() ?? 'Autre')->map->count()->sortDesc(),
            'test' => $resultats->groupBy(fn ($r) => $r->typeExamen?->libelle ?? 'Inconnu')->map->count()->sortDesc()->take(10),
            'radiologue' => $examens->whereNotNull('laborantin_id')
                ->groupBy(fn ($e) => trim(($e->laborantin?->prenom ?? '').' '.($e->laborantin?->nom ?? '')))
                ->map->count()->sortDesc(),
            'total' => $examens->count(),
            'urgents' => $examens->where('urgence', true)->count(),
            'comptes_rendus' => $examens->filter(fn ($e) => filled($e->conclusion))->count(),
            'delai_moyen' => $this->delaiMoyenHeures($examens),
        ];
    }

    /**
     * Consommation pharmaceutique par officine et par produit.
     *
     * @return array<string, Collection>
     */
    public function activitePharmacie(string $debut, string $fin): array
    {
        $mouvements = MouvementStock::with(['medicament', 'officine'])
            ->whereBetween('created_at', [$debut.' 00:00:00', $fin.' 23:59:59'])
            ->get();

        $sorties = $mouvements->filter(fn ($m) => str_starts_with($m->type, 'sortie'));

        return [
            'officine' => $sorties->groupBy(fn ($m) => $m->officine?->nom ?? 'Non affectée')
                ->map(fn ($g) => (float) $g->sum('quantite'))->sortDesc(),
            'produit' => $sorties->groupBy(fn ($m) => $m->medicament?->denomination_commune ?? 'Produit retiré')
                ->map(fn ($g) => (float) $g->sum('quantite'))->sortDesc()->take(10),
            'type_mouvement' => $mouvements->groupBy('type')->map->count()->sortDesc(),
        ];
    }
}
