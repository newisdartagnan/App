<?php

namespace App\Services;

use App\Models\AbsenceMedecin;
use App\Models\DisponibiliteMedecin;
use App\Models\TypeConsultation;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Qui consulte, quoi, et quand.
 *
 * L'accueil a besoin de savoir si la spécialité demandée est assurée avant
 * d'encaisser : envoyer un patient payer une consultation de cardiologie un
 * jour sans cardiologue est la meilleure façon de créer un litige au guichet.
 */
class DisponibiliteService
{
    /**
     * Médecins d'une spécialité présents à cette date et cette heure.
     *
     * @return Collection<int, User>
     */
    public function medecinsDisponibles(?string $specialite, ?string $jour = null, ?string $heure = null): Collection
    {
        $jour ??= now()->toDateString();
        $heure ??= now()->format('H:i');
        $jourSemaine = (int) Carbon::parse($jour)->isoWeekday();

        return $this->medecins($specialite)
            ->filter(function (User $medecin) use ($jour, $heure, $jourSemaine) {
                if ($this->estAbsent($medecin, $jour)) {
                    return false;
                }

                $plages = $medecin->disponibilites->where('jour_semaine', $jourSemaine);

                // Aucune plage déclarée : le médecin est réputé disponible.
                // On n'empêche pas de travailler un établissement qui n'a pas
                // encore saisi ses horaires.
                if ($plages->isEmpty()) {
                    return $medecin->disponibilites->isEmpty();
                }

                return $plages->contains(fn (DisponibiliteMedecin $p) => $p->couvre($heure));
            })
            ->values();
    }

    /**
     * Tous les médecins d'une spécialité, disponibles ou non.
     *
     * @return Collection<int, User>
     */
    public function medecins(?string $specialite): Collection
    {
        return User::role('medecin')
            ->where('is_active', true)
            ->when($specialite, fn ($q) => $q->where('specialite', $specialite))
            ->with(['disponibilites' => fn ($q) => $q->where('is_active', true), 'absences'])
            ->orderBy('nom')
            ->get();
    }

    public function estAbsent(User $medecin, string $jour): bool
    {
        return $medecin->absences->contains(fn (AbsenceMedecin $a) => $a->couvre($jour));
    }

    /**
     * État de chaque spécialité proposée à l'accueil, pour la date donnée.
     *
     * @return array<int, array{specialite: string, types: array<int, string>, disponibles: Collection<int, User>, tous: Collection<int, User>}>
     */
    public function tableauDesSpecialites(?string $jour = null, ?string $heure = null): array
    {
        $jour ??= now()->toDateString();
        $heure ??= now()->format('H:i');

        $types = TypeConsultation::where('est_actif', true)->orderBy('libelle')->get();

        $parSpecialite = $types->groupBy(fn ($t) => $t->specialite ?: 'Médecine générale');

        $tableau = [];

        foreach ($parSpecialite as $specialite => $groupe) {
            $tous = $this->medecins($specialite);

            // Les libellés de spécialité des types de consultation ne
            // correspondent pas toujours à ceux saisis sur les comptes
            // médecins ; on retombe alors sur l'ensemble des médecins.
            if ($tous->isEmpty() && $specialite === 'Médecine générale') {
                $tous = $this->medecins(null)->filter(fn ($m) => blank($m->specialite))->values();
            }

            $tableau[] = [
                'specialite' => $specialite,
                'types' => $groupe->pluck('libelle')->all(),
                'disponibles' => $this->medecinsDisponibles($specialite, $jour, $heure)
                    ->when($tous->isEmpty(), fn ($c) => collect()),
                'tous' => $tous,
            ];
        }

        return $tableau;
    }

    /**
     * Y a-t-il quelqu'un pour assurer ce type de consultation maintenant ?
     * Renvoie null si oui, sinon le message à afficher à l'accueil.
     */
    public function avertissementPour(TypeConsultation $type, ?string $jour = null, ?string $heure = null): ?string
    {
        $specialite = $type->specialite ?: 'Médecine générale';
        $tous = $this->medecins($specialite);

        if ($tous->isEmpty()) {
            return 'Aucun médecin n\'est enregistré en '.mb_strtolower($specialite).'.';
        }

        if ($this->medecinsDisponibles($specialite, $jour, $heure)->isEmpty()) {
            return 'Aucun médecin de '.mb_strtolower($specialite).' n\'est présent à cette heure — '
                .'le patient sera pris en charge dès le retour d\'un praticien.';
        }

        return null;
    }
}
