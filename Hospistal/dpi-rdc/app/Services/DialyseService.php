<?php

namespace App\Services;

use App\Models\ActeClinique;
use App\Models\GenerateurDialyse;
use App\Models\Patient;
use App\Models\SeanceDialyse;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Dialyse : le calendrier des séances et leur réalisation.
 */
class DialyseService
{
    /** Tarifs par type de séance, en francs congolais. */
    private const TARIFS = [
        'hemodialyse' => 'dialyse_seance',
        'hemodialyse_epo' => 'dialyse_seance_epo',
        'peritoneale' => 'dialyse_peritoneale',
    ];

    /**
     * Planifie une séance sur un générateur.
     *
     * Le générateur est la ressource rare de l'unité : on refuse un créneau
     * déjà pris, et l'on refuse aussi un poste réservé aux porteurs de
     * l'antigène HBs à un patient qui ne l'est pas — la règle d'hygiène va
     * dans les deux sens.
     *
     * @return array{seance: ?SeanceDialyse, erreur: ?string}
     */
    public function planifier(Patient $patient, array $donnees, ?SeanceDialyse $seance = null): array
    {
        $generateur = GenerateurDialyse::find($donnees['generateur_id'] ?? null);

        if (! $generateur) {
            return ['seance' => null, 'erreur' => 'Choisissez le générateur qui recevra le patient.'];
        }

        $debut = Carbon::parse($donnees['date_seance']);
        $duree = (int) ($donnees['duree_minutes'] ?? 240);
        $fin = $debut->copy()->addMinutes($duree);

        $conflits = $generateur->occupationEntre($debut, $fin, $seance?->id);

        if ($conflits->isNotEmpty()) {
            $conflit = $conflits->first();

            return ['seance' => null, 'erreur' => sprintf(
                '%s est déjà occupé de %s à %s par %s. Choisissez un autre créneau ou un autre poste.',
                $generateur->nom,
                $conflit->date_seance->format('H:i'),
                $conflit->finPrevue()->format('H:i'),
                $conflit->patient?->nom_complet ?? 'un autre patient'
            )];
        }

        $attributs = [
            'establishment_id' => $patient->establishment_id,
            'patient_id' => $patient->id,
            'generateur_id' => $generateur->id,
            'date_seance' => $debut,
            'duree_minutes' => $duree,
            'type' => $donnees['type'] ?? 'hemodialyse',
            'abord' => $donnees['abord'] ?? null,
            'poids_sec_kg' => $donnees['poids_sec_kg'] ?? null,
            'nephrologue_id' => $donnees['nephrologue_id'] ?? null,
            'visit_id' => $donnees['visit_id'] ?? null,
            'statut' => 'planifiee',
        ];

        if ($seance) {
            $seance->update($attributs);

            return ['seance' => $seance->fresh(), 'erreur' => null];
        }

        return ['seance' => SeanceDialyse::create($attributs), 'erreur' => null];
    }

    /**
     * Programme récurrent d'un dialysé : les mêmes jours, à la même heure,
     * pendant plusieurs semaines.
     *
     * Un insuffisant rénal chronique vient trois fois par semaine, toute
     * l'année. Le saisir séance par séance serait une punition.
     *
     * @param  array<int, int>  $jours  jours de la semaine (1 = lundi … 7 = dimanche)
     * @return array{creees: int, conflits: array<int, string>}
     */
    public function programmerRecurrence(
        Patient $patient,
        array $jours,
        string $heure,
        Carbon $debut,
        int $semaines,
        array $donnees
    ): array {
        $creees = 0;
        $conflits = [];

        for ($semaine = 0; $semaine < $semaines; $semaine++) {
            foreach ($jours as $jour) {
                $date = $debut->copy()
                    ->startOfWeek()
                    ->addWeeks($semaine)
                    ->addDays((int) $jour - 1)
                    ->setTimeFromTimeString($heure);

                // On ne remplit pas le passé : la première semaine peut être
                // entamée, ses jours écoulés sont sautés.
                if ($date->lt($debut)) {
                    continue;
                }

                $resultat = $this->planifier($patient, [...$donnees, 'date_seance' => $date->toDateTimeString()]);

                if ($resultat['erreur']) {
                    $conflits[] = $date->format('d/m/Y à H:i').' — '.$resultat['erreur'];

                    continue;
                }

                $creees++;
            }
        }

        return ['creees' => $creees, 'conflits' => $conflits];
    }

    /**
     * Clôt une séance : les mesures de fin, puis l'acte facturable.
     *
     * La séance réalisée devient un acte clinique — c'est lui que la caisse
     * facture. On ne le crée qu'une fois : une séance ne se facture pas deux
     * fois parce qu'on a corrigé un poids.
     */
    public function realiser(SeanceDialyse $seance, array $donnees): SeanceDialyse
    {
        return DB::transaction(function () use ($seance, $donnees) {
            $seance->update([
                ...$donnees,
                'statut' => 'realisee',
                'infirmier_id' => $donnees['infirmier_id'] ?? auth()->id(),
                'ultrafiltration_ml' => $donnees['ultrafiltration_ml'] ?? null,
            ]);

            $seance->refresh();

            // À défaut d'ultrafiltration relevée au générateur, le poids perdu
            // en tient lieu : un kilogramme, un litre.
            if ($seance->ultrafiltration_ml === null && $seance->perteDePoidsMl() !== null) {
                $seance->update(['ultrafiltration_ml' => max(0, $seance->perteDePoidsMl())]);
            }

            if (! $seance->acte_clinique_id && $seance->visit_id) {
                $seance->update(['acte_clinique_id' => $this->creerActeFacturable($seance)->id]);
            }

            return $seance->fresh(['generateur', 'patient', 'acteClinique']);
        });
    }

    /** L'acte clinique correspondant à la séance, pour la caisse. */
    private function creerActeFacturable(SeanceDialyse $seance): ActeClinique
    {
        $tarifs = config('dpi.tarifs_cdf', []);
        $cle = self::TARIFS[$seance->type] ?? 'dialyse_seance';

        return ActeClinique::create([
            'visit_id' => $seance->visit_id,
            'patient_id' => $seance->patient_id,
            'prescripteur_id' => $seance->nephrologue_id ?? auth()->id(),
            'operateur_id' => $seance->nephrologue_id,
            'domaine' => 'dialyse',
            'libelle' => $seance->libelleType().' du '.$seance->date_seance->format('d/m/Y'),
            'prix' => $tarifs[$cle] ?? 120000,
            'quantite' => 1,
            'statut' => 'realise',
            'date_realisation' => $seance->date_seance,
            'compte_rendu' => $this->resumeSeance($seance),
        ]);
    }

    private function resumeSeance(SeanceDialyse $seance): string
    {
        $morceaux = array_filter([
            $seance->libelleType(),
            $seance->duree_minutes ? $seance->duree_minutes.' minutes' : null,
            $seance->abord ? 'abord : '.mb_strtolower($seance->libelleAbord()) : null,
            $seance->ultrafiltration_ml !== null ? 'ultrafiltration '.$seance->ultrafiltration_ml.' ml' : null,
            $seance->incidents ? 'incident : '.$seance->incidents : null,
        ]);

        return implode(' — ', $morceaux);
    }

    /**
     * Indicateurs de l'unité sur une période.
     *
     * @return array<string, mixed>
     */
    public function indicateurs(string $debut, string $fin): array
    {
        $seances = SeanceDialyse::with(['patient', 'generateur'])
            ->whereBetween('date_seance', [$debut.' 00:00:00', $fin.' 23:59:59'])
            ->get();

        $realisees = $seances->where('statut', 'realisee');

        return [
            'planifiees' => $seances->count(),
            'realisees' => $realisees->count(),
            'absences' => $seances->where('statut', 'absente')->count(),
            'patients' => $seances->pluck('patient_id')->unique()->count(),
            'ultrafiltration_moyenne' => $realisees->pluck('ultrafiltration_ml')->filter()->avg(),
            'par_generateur' => $realisees->groupBy(fn ($s) => $s->generateur?->nom ?? 'Non affecté')
                ->map->count()->sortDesc(),
            'par_abord' => $realisees->whereNotNull('abord')
                ->groupBy(fn ($s) => $s->libelleAbord())->map->count()->sortDesc(),
            'incidents' => $realisees->filter(fn ($s) => filled($s->incidents))->count(),
        ];
    }
}
