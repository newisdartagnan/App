<?php

namespace App\Services;

use App\Models\Dispensation;
use App\Models\Prescription;
use App\Models\User;
use App\Models\Visit;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Le temps du parcours patient, poste par poste.
 *
 * Un hôpital ne se juge pas seulement à ce qu'il fait, mais à ce qu'il fait
 * attendre. Toutes les heures nécessaires sont déjà en base — accueil,
 * guichet, triage, cabinet, laboratoire, officine, sortie — mais personne ne
 * les avait jamais mises bout à bout. On les reconstitue plutôt que d'ouvrir
 * un chronomètre parallèle : un second registre finirait par mentir.
 *
 * Deux notions distinctes, et jamais confondues :
 *  - le JALON, un instant daté avec son auteur ;
 *  - le SEGMENT, une durée entre deux jalons, imputée soit à un poste (il
 *    travaillait), soit à l'attente (le patient patientait).
 *
 * On ne mesure que ce qui est daté. Une étape sans début connu reste un
 * jalon : mieux vaut un trou franc qu'une durée inventée.
 */
class ParcoursTemporelService
{
    /** Postes du parcours, dans l'ordre où le patient les rencontre. */
    public const POSTES = [
        'accueil' => 'Accueil',
        'guichet' => 'Guichet',
        'triage' => 'Triage infirmier',
        'cabinet' => 'Cabinet médical',
        'laboratoire' => 'Laboratoire',
        'imagerie' => 'Imagerie',
        'officine' => 'Officine',
        'bloc' => 'Bloc opératoire',
        'hospitalisation' => 'Hospitalisation',
        'sortie' => 'Sortie',
    ];

    /**
     * Tous les jalons datés d'un séjour, du plus ancien au plus récent.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function jalons(Visit $visit): Collection
    {
        $visit->loadMissing([
            'user', 'triagePar', 'medecinConsultant', 'patient',
            'factures.paiements.caissier',
            'examensLaboratoire.prescripteur', 'examensLaboratoire.laborantin',
            'actesCliniques.operateur',
        ]);

        $jalons = collect();

        $jalons->push($this->jalon('accueil', 'Enregistrement du passage',
            $visit->created_at ?? $visit->date_entree, $visit->user));

        foreach ($visit->factures as $facture) {
            $jalons->push($this->jalon('guichet', 'Facture '.$facture->numero_facture.' émise',
                $facture->date_facture, null));

            foreach ($facture->paiements as $paiement) {
                $jalons->push($this->jalon('guichet',
                    'Encaissement '.number_format((float) $paiement->montant_cdf, 0, ',', ' ').' CDF',
                    $paiement->date_paiement, $paiement->caissier));
            }
        }

        if ($visit->triage_fait_at) {
            $jalons->push($this->jalon('triage', 'Triage et constantes',
                $visit->triage_fait_at, $visit->triagePar));
        }

        if ($visit->consultation_debutee_at) {
            $jalons->push($this->jalon('cabinet', 'Entrée au cabinet',
                $visit->consultation_debutee_at, $visit->medecinConsultant));
        }

        foreach ($visit->consultations as $consultation) {
            $jalons->push($this->jalon('cabinet', 'Consultation rédigée',
                $consultation->finalise_at ?? $consultation->created_at, $consultation->user));
        }

        foreach ($visit->examensLaboratoire as $examen) {
            $poste = $examen->domaine === 'imagerie' ? 'imagerie' : 'laboratoire';
            $quoi = $examen->domaine === 'imagerie' ? 'Imagerie' : 'Examens';

            $jalons->push($this->jalon($poste, $quoi.' prescrits ('.$examen->numero_bon.')',
                $examen->date_prescription, $examen->prescripteur));

            if ($examen->date_prelevement) {
                $jalons->push($this->jalon($poste,
                    $examen->domaine === 'imagerie' ? 'Patient installé' : 'Prélèvement effectué',
                    $examen->date_prelevement, $examen->laborantin));
            }

            if ($examen->date_resultat) {
                $jalons->push($this->jalon($poste, 'Résultats rendus ('.$examen->numero_bon.')',
                    $examen->date_resultat, $examen->laborantin));
            }
        }

        foreach ($this->prescriptions($visit) as $prescription) {
            $jalons->push($this->jalon('officine', 'Ordonnance rédigée',
                $prescription->date_prescription ?? $prescription->created_at,
                $prescription->prescripteur));
        }

        foreach ($this->dispensations($visit) as $dispensation) {
            $jalons->push($this->jalon('officine', 'Médicaments délivrés',
                $dispensation->date_dispensation, $dispensation->pharmacien));
        }

        foreach ($visit->actesCliniques as $acte) {
            if ($acte->heure_entree_salle) {
                $jalons->push($this->jalon('bloc', 'Entrée en salle — '.$acte->libelle,
                    $acte->heure_entree_salle, $acte->operateur));
            }

            if ($acte->heure_sortie_salle) {
                $jalons->push($this->jalon('bloc', 'Sortie de salle — '.$acte->libelle,
                    $acte->heure_sortie_salle, $acte->operateur));
            } elseif ($acte->date_realisation) {
                $jalons->push($this->jalon('bloc', 'Acte réalisé — '.$acte->libelle,
                    $acte->date_realisation, $acte->operateur));
            }
        }

        if ($visit->date_sortie) {
            $jalons->push($this->jalon('sortie', 'Sortie du patient', $visit->date_sortie, null));
        }

        return $jalons
            ->filter(fn (array $j) => $j['moment'] instanceof Carbon)
            ->sortBy(fn (array $j) => $j['moment']->getTimestamp())
            ->values();
    }

    /**
     * Segments du parcours : ce qui a duré, et à qui l'imputer.
     *
     * Une durée n'est du travail que si le même agent tient les deux bouts,
     * au même poste. Tout le reste est de l'attente. La règle est volontairement
     * sévère : entre l'ordonnance d'examens signée par le médecin et le
     * prélèvement fait par le laborantin, le patient marche et patiente — ces
     * minutes-là n'appartiennent à personne, et les imputer au laboratoire
     * gonflerait sa charge de tout ce qu'il n'a pas fait.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function segments(Visit $visit): Collection
    {
        $jalons = $this->jalons($visit);
        $segments = collect();

        for ($i = 1; $i < $jalons->count(); $i++) {
            $avant = $jalons[$i - 1];
            $apres = $jalons[$i];

            $minutes = (int) round($avant['moment']->diffInSeconds($apres['moment']) / 60);

            if ($minutes <= 0) {
                continue;
            }

            $memeAgent = $avant['acteur'] !== null
                && $apres['acteur'] !== null
                && $avant['acteur']->id === $apres['acteur']->id;

            $travail = $memeAgent && $avant['poste'] === $apres['poste'];

            $segments->push([
                'depuis' => $avant['moment'],
                'jusqua' => $apres['moment'],
                'minutes' => $minutes,
                'poste' => $apres['poste'],
                'libelle' => $travail
                    ? 'Prise en charge — '.self::POSTES[$apres['poste']]
                    : 'Attente avant '.lcfirst(self::POSTES[$apres['poste']]),
                'attente' => ! $travail,
                'acteur' => $travail ? $apres['acteur'] : null,
            ]);
        }

        return $segments;
    }

    /**
     * Ce que le séjour a coûté en temps, vu du patient.
     *
     * @return array<string, mixed>
     */
    public function synthese(Visit $visit): array
    {
        $jalons = $this->jalons($visit);
        $segments = $this->segments($visit);

        $priseEnCharge = $segments->reject->attente->sum('minutes');
        $attente = $segments->filter->attente->sum('minutes');
        $total = $priseEnCharge + $attente;

        return [
            'debut' => $jalons->first()['moment'] ?? null,
            'fin' => $jalons->last()['moment'] ?? null,
            'total_minutes' => $total,
            'prise_en_charge_minutes' => $priseEnCharge,
            'attente_minutes' => $attente,
            'part_attente' => $total > 0 ? (int) round($attente * 100 / $total) : 0,
            'jalons' => $jalons->count(),
            'par_poste' => collect(self::POSTES)
                ->map(fn ($libelle, $cle) => [
                    'libelle' => $libelle,
                    'prise_en_charge' => $segments->where('poste', $cle)->reject->attente->sum('minutes'),
                    'attente' => $segments->where('poste', $cle)->filter->attente->sum('minutes'),
                ])
                ->filter(fn ($ligne) => $ligne['prise_en_charge'] > 0 || $ligne['attente'] > 0),
            // La plus longue attente : c'est celle qu'il faut aller regarder.
            'pire_attente' => $segments->filter->attente->sortByDesc('minutes')->first(),
        ];
    }

    /**
     * Temps d'un agent sur une période : ce qu'il a fait, et combien de temps.
     *
     * On ne compte que les minutes mesurées — deux jalons du même poste
     * encadrant son intervention. Un geste ponctuel, lui, se compte en actes
     * et non en minutes : prétendre le contraire serait inventer.
     *
     * @return array<string, mixed>
     */
    public function activiteDe(User $utilisateur, ?string $debut = null, ?string $fin = null): array
    {
        $depuis = $debut ? Carbon::parse($debut)->startOfDay() : now()->startOfMonth();
        $jusqua = $fin ? Carbon::parse($fin)->endOfDay() : now()->endOfDay();

        $visites = $this->visitesTouchees($utilisateur, $depuis, $jusqua);

        $minutes = 0;
        $interventions = 0;
        $parPoste = [];
        $parPatient = collect();

        foreach ($visites as $visite) {
            $siens = $this->segments($visite)
                ->filter(fn ($s) => $s['acteur']?->id === $utilisateur->id)
                ->filter(fn ($s) => $s['jusqua']->betweenIncluded($depuis, $jusqua));

            $sesJalons = $this->jalons($visite)
                ->filter(fn ($j) => $j['acteur']?->id === $utilisateur->id)
                ->filter(fn ($j) => $j['moment']->betweenIncluded($depuis, $jusqua));

            if ($sesJalons->isEmpty()) {
                continue;
            }

            $minutesVisite = $siens->sum('minutes');
            $minutes += $minutesVisite;
            $interventions += $sesJalons->count();

            foreach ($siens as $segment) {
                $parPoste[$segment['poste']] = ($parPoste[$segment['poste']] ?? 0) + $segment['minutes'];
            }

            foreach ($sesJalons as $jalon) {
                $parPoste[$jalon['poste']] ??= 0;
            }

            $parPatient->push([
                'visite' => $visite,
                'patient' => $visite->patient,
                'interventions' => $sesJalons->count(),
                'minutes' => $minutesVisite,
                'premier' => $sesJalons->first()['moment'],
                'dernier' => $sesJalons->last()['moment'],
                'etapes' => $sesJalons->pluck('libelle')->all(),
            ]);
        }

        return [
            'debut' => $depuis,
            'fin' => $jusqua,
            'minutes' => $minutes,
            'interventions' => $interventions,
            'patients' => $parPatient->count(),
            'minutes_par_patient' => $parPatient->count() > 0
                ? (int) round($minutes / $parPatient->count())
                : 0,
            'par_poste' => collect($parPoste)
                ->mapWithKeys(fn ($m, $cle) => [self::POSTES[$cle] ?? $cle => $m])
                ->sortDesc(),
            'parcours' => $parPatient->sortByDesc(fn ($l) => $l['dernier']->getTimestamp())->values(),
        ];
    }

    /**
     * Séjours qu'un agent a touchés sur la période.
     *
     * @return Collection<int, Visit>
     */
    private function visitesTouchees(User $utilisateur, Carbon $depuis, Carbon $jusqua): Collection
    {
        $id = $utilisateur->id;

        return Visit::query()
            ->where(fn ($q) => $q
                ->where('user_id', $id)
                ->orWhere('triage_par', $id)
                ->orWhere('consultation_par', $id)
                ->orWhereHas('consultations', fn ($c) => $c->where('user_id', $id))
                ->orWhereHas('examensLaboratoire', fn ($e) => $e
                    ->where('prescripteur_id', $id)->orWhere('laborantin_id', $id))
                ->orWhereHas('actesCliniques', fn ($a) => $a
                    ->where('operateur_id', $id)->orWhere('prescripteur_id', $id))
                ->orWhereHas('factures.paiements', fn ($p) => $p->where('caissier_id', $id))
            )
            ->where('date_entree', '>=', $depuis->copy()->subDays(30))
            ->where('date_entree', '<=', $jusqua)
            ->with(['patient'])
            ->orderByDesc('date_entree')
            ->limit(300)
            ->get();
    }

    /** @return Collection<int, Prescription> */
    private function prescriptions(Visit $visit): Collection
    {
        return Prescription::with('prescripteur')
            ->whereIn('consultation_id', $visit->consultations->pluck('id'))
            ->get();
    }

    /** @return Collection<int, Dispensation> */
    private function dispensations(Visit $visit): Collection
    {
        $prescriptions = $this->prescriptions($visit)->pluck('id');

        if ($prescriptions->isEmpty()) {
            return collect();
        }

        return Dispensation::with('pharmacien')
            ->whereHas('lignePrescription', fn ($q) => $q->whereIn('prescription_id', $prescriptions))
            ->get();
    }

    private function jalon(string $poste, string $libelle, mixed $moment, ?User $acteur): array
    {
        return [
            'poste' => $poste,
            'libelle' => $libelle,
            'moment' => $moment instanceof Carbon ? $moment : ($moment ? Carbon::parse($moment) : null),
            'acteur' => $acteur,
            'role' => $acteur?->libelleRoles(),
        ];
    }

    /** « 1 h 45 » plutôt que « 105 minutes » : c'est ainsi qu'on en parle. */
    public static function duree(?int $minutes): string
    {
        if ($minutes === null || $minutes <= 0) {
            return '—';
        }

        if ($minutes < 60) {
            return $minutes.' min';
        }

        $heures = intdiv($minutes, 60);
        $reste = $minutes % 60;

        if ($heures >= 24) {
            $jours = intdiv($heures, 24);

            return $jours.' j '.($heures % 24).' h';
        }

        return $heures.' h'.($reste > 0 ? ' '.str_pad((string) $reste, 2, '0', STR_PAD_LEFT) : '');
    }
}
