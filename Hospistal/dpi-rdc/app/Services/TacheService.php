<?php

namespace App\Services;

use App\Models\ExamenLaboratoire;
use App\Models\PlanAdministration;
use App\Models\Prescription;
use App\Models\User;
use App\Models\Visit;
use Illuminate\Support\Collection;

/**
 * « Mes tâches » : ce que chaque poste a à faire, sans fouiller l'application.
 *
 * Le médecin voit les consultations qui l'attendent, le laboratoire et
 * l'imagerie les examens sans résultat, la pharmacie les ordonnances à
 * servir, l'infirmier les prises à administrer. Chaque section n'apparaît
 * que si l'agent a le droit d'agir dessus : on ne montre pas un travail
 * qu'on ne peut pas faire.
 */
class TacheService
{
    /** Statuts d'un examen qui attend encore son résultat. */
    public const EXAMEN_EN_ATTENTE = ['prescrit', 'preleve', 'en_analyse'];

    /**
     * @return array<string, array{titre: string, icone: string, elements: Collection}>
     */
    public function pour(User $user): array
    {
        $sections = [];

        if ($user->can('consultation.create')) {
            $sections['consultations'] = [
                'titre' => 'Consultations en attente',
                'icone' => '🩺',
                'elements' => $this->consultationsEnAttente(),
            ];
        }

        if ($user->can('labo.validate') || $user->hasRole('laborantin')) {
            $sections['labo'] = [
                'titre' => 'Examens de laboratoire en attente de résultat',
                'icone' => '🔬',
                'elements' => $this->examensEnAttente('labo'),
            ];
            $sections['imagerie'] = [
                'titre' => 'Examens d\'imagerie en attente de résultat',
                'icone' => '🩻',
                'elements' => $this->examensEnAttente('imagerie'),
            ];
        }

        if ($user->can('dispensation.execute')) {
            $sections['pharmacie'] = [
                'titre' => 'Prescriptions en attente de distribution',
                'icone' => '💊',
                'elements' => $this->prescriptionsADispenser(),
            ];
        }

        if ($user->can('soin.execute')) {
            $sections['soins'] = [
                'titre' => 'Produits en attente d\'administration',
                'icone' => '💉',
                'elements' => $this->administrationsDues(),
            ];
        }

        return $sections;
    }

    /** Nombre total de tâches : sert au bip quand il augmente. */
    public function total(array $sections): int
    {
        return (int) collect($sections)->sum(fn ($s) => $s['elements']->count());
    }

    public function consultationsEnAttente(): Collection
    {
        return Visit::with(['patient', 'service', 'typeConsultation', 'triagesUrgence'])
            ->whereIn('type', ['consultation_externe', 'urgence'])
            ->where('statut', 'en_cours')
            ->whereDoesntHave('consultations')
            ->whereNull('consultation_debutee_at')
            ->orderBy('date_entree')
            ->limit(100)
            ->get()
            ->map(function (Visit $v) {
                // Normal, moyen, urgent : l'urgence d'abord, le triage ensuite.
                // Niveaux 1-2 : urgent ; 3 ou urgence non triée : moyen.
                $niveau = (int) ($v->triagesUrgence->sortByDesc('triage_at')->first()?->niveau ?? 0);
                $priorite = match (true) {
                    $niveau >= 1 && $niveau <= 2 => 'urgent',
                    $niveau === 3, $v->type === 'urgence' && $niveau === 0 => 'moyen',
                    default => 'normal',
                };

                return (object) [
                    'visit' => $v,
                    'patient' => $v->patient,
                    'priorite' => $priorite,
                    'lieu' => $v->service?->nom ?? ($v->type === 'urgence' ? 'Urgences' : 'Consultation'),
                    'specialite' => $v->typeConsultation?->specialite ?: 'Médecine générale',
                    'depuis' => $v->date_entree,
                    'url' => route('visites.consulter', $v),
                ];
            })
            ->sortBy(fn ($t) => ['urgent' => 0, 'moyen' => 1, 'normal' => 2][$t->priorite])
            ->values();
    }

    public function examensEnAttente(string $domaine): Collection
    {
        return ExamenLaboratoire::with(['patient', 'visit.service', 'facture', 'resultats.typeExamen'])
            ->where('domaine', $domaine)
            ->whereIn('statut', self::EXAMEN_EN_ATTENTE)
            ->orderByDesc('urgence')
            ->orderBy('date_prescription')
            ->limit(100)
            ->get()
            ->map(fn (ExamenLaboratoire $e) => (object) [
                'examen' => $e,
                'patient' => $e->patient,
                'service' => $e->visit?->service?->nom ?? ($e->visit?->type === 'urgence' ? 'Urgences' : 'Consultation'),
                'paye' => $this->examenPeutRecevoirResultat($e),
                'url' => route('labo.show', $e),
            ]);
    }

    /** Même règle que la saisie classique : guichet d'abord, sauf hospitalisé. */
    public function examenPeutRecevoirResultat(ExamenLaboratoire $examen): bool
    {
        if ($examen->visit?->serviACredit()) {
            return true;
        }

        return ! $examen->facture || $examen->facture->statut === 'payee';
    }

    public function prescriptionsADispenser(): Collection
    {
        return Prescription::with(['patient', 'lignes.medicament', 'consultation.visit.service'])
            ->whereIn('statut', ['en_attente', 'partiellement_dispensee'])
            ->orderBy('date_prescription')
            ->limit(100)
            ->get()
            ->map(fn (Prescription $p) => (object) [
                'prescription' => $p,
                'patient' => $p->patient,
                'service' => $p->consultation?->visit?->service?->nom ?? '—',
                'depuis' => $p->date_prescription,
                'url' => route('pharmacie.prescription', $p),
            ]);
    }

    /** Prises du jour déjà dues (heure passée) et non administrées. */
    public function administrationsDues(): Collection
    {
        $heure = (int) now()->format('G');

        return PlanAdministration::with(['visit.patient', 'visit.service', 'visit.lit', 'administrations'])
            ->whereDate('jour', today())
            ->whereHas('visit', fn ($q) => $q->whereNotIn('statut', ['termine', 'annule']))
            ->get()
            ->flatMap(function (PlanAdministration $plan) use ($heure) {
                return collect($plan->heures ?? [])
                    ->filter(fn ($h) => (int) $h <= $heure + 1 && ! $plan->administreeA((int) $h))
                    ->map(fn ($h) => (object) [
                        'plan' => $plan,
                        'patient' => $plan->visit?->patient,
                        'heure' => (int) $h,
                        'en_retard' => $plan->enRetardA((int) $h),
                        'service' => $plan->visit?->service?->nom ?? '—',
                        'lit' => $plan->visit?->lit?->numero,
                        'url' => route('mar.index', $plan->visit_id),
                    ]);
            })
            ->sortBy('heure')
            ->values();
    }
}
