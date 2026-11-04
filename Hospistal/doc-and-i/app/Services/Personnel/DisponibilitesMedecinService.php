<?php

namespace App\Services\Personnel;

use App\Enums\StatutRendezVous;
use App\Models\AbsenceMedecin;
use App\Models\Medecin;
use App\Models\PlageDisponibilite;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Disponibilités du médecin (RDV-04) : plages hebdomadaires et absences,
 * en heure de Kinshasa. Chaque écriture se fait sous verrou du médecin :
 * deux envois simultanés ne créent pas deux plages qui se chevauchent.
 */
final class DisponibilitesMedecinService
{
    public const FUSEAU = 'Africa/Kinshasa';

    public const JOURS = [1 => 'Lundi', 2 => 'Mardi', 3 => 'Mercredi', 4 => 'Jeudi', 5 => 'Vendredi', 6 => 'Samedi', 7 => 'Dimanche'];

    /** @param string $debut,$fin « HH:MM », heure de Kinshasa */
    public function ajouterPlage(Medecin $medecin, int $jour, string $debut, string $fin): PlageDisponibilite
    {
        if ($fin <= $debut) {
            throw ValidationException::withMessages(['heure_fin' => 'L\'heure de fin doit être après l\'heure de début.']);
        }

        return DB::transaction(function () use ($medecin, $jour, $debut, $fin) {
            Medecin::whereKey($medecin->id)->lockForUpdate()->first();

            $chevauche = $medecin->plages()
                ->where('jour_semaine', $jour)
                ->where('heure_debut', '<', $fin)
                ->where('heure_fin', '>', $debut)
                ->exists();

            if ($chevauche) {
                throw ValidationException::withMessages(['heure_debut' => 'Cette plage chevauche une plage déjà enregistrée le '.mb_strtolower(self::JOURS[$jour]).'.']);
            }

            return $medecin->plages()->create(['jour_semaine' => $jour, 'heure_debut' => $debut, 'heure_fin' => $fin]);
        });
    }

    /** 404 si la plage n'est pas celle de CE médecin. */
    public function supprimerPlage(Medecin $medecin, string $id): void
    {
        $medecin->plages()->findOrFail($id)->delete();
    }

    /**
     * Enregistre une absence ; renvoie aussi le nombre de rendez-vous
     * occupants pris sur la période (à reporter ou annuler par la coordination).
     *
     * @return array{0: AbsenceMedecin, 1: int}
     */
    public function ajouterAbsence(Medecin $medecin, CarbonImmutable $debut, CarbonImmutable $fin, ?string $motif): array
    {
        if ($fin <= $debut) {
            throw ValidationException::withMessages(['fin' => 'La fin doit être après le début.']);
        }

        if ($fin <= now()) {
            throw ValidationException::withMessages(['fin' => 'Cette période est déjà passée.']);
        }

        return DB::transaction(function () use ($medecin, $debut, $fin, $motif) {
            Medecin::whereKey($medecin->id)->lockForUpdate()->first();

            $chevauche = $medecin->absences()->where('debut', '<', $fin)->where('fin', '>', $debut)->exists();

            if ($chevauche) {
                throw ValidationException::withMessages(['debut' => 'Cette période chevauche une absence déjà enregistrée.']);
            }

            $absence = $medecin->absences()->create(['debut' => $debut, 'fin' => $fin, 'motif' => $motif]);

            $rendezVous = $medecin->rendezVous()
                ->whereIn('statut', StatutRendezVous::occupants())
                ->where('debut', '<', $fin)
                ->where('fin', '>', $debut)
                ->count();

            return [$absence, $rendezVous];
        });
    }

    /** Seule une absence pas encore commencée se supprime ; 404 si ce n'est pas celle de CE médecin. */
    public function supprimerAbsence(Medecin $medecin, string $id): void
    {
        $absence = $medecin->absences()->findOrFail($id);

        if ($absence->debut <= now()) {
            throw ValidationException::withMessages(['absence' => 'Une absence déjà commencée ou passée ne peut pas être supprimée.']);
        }

        $absence->delete();
    }

    /** @return list<array{id: string, jourSemaine: int, jour: string, heureDebut: string, heureFin: string}> */
    public function plages(Medecin $medecin): array
    {
        return $medecin->plages()->orderBy('jour_semaine')->orderBy('heure_debut')->get()
            ->map(fn (PlageDisponibilite $p) => [
                'id' => $p->id,
                'jourSemaine' => $p->jour_semaine,
                'jour' => self::JOURS[$p->jour_semaine],
                'heureDebut' => substr($p->heure_debut, 0, 5),
                'heureFin' => substr($p->heure_fin, 0, 5),
            ])->all();
    }

    /** Absences en cours ou à venir. */
    public function absences(Medecin $medecin): array
    {
        return $medecin->absences()->where('fin', '>', now())->orderBy('debut')->get()
            ->map(fn (AbsenceMedecin $a) => [
                'id' => $a->id,
                'debut' => self::formater($a->debut),
                'fin' => self::formater($a->fin),
                'motif' => $a->motif,
                'supprimable' => $a->debut->isFuture(),
            ])->all();
    }

    private static function formater(\DateTimeInterface $date): string
    {
        return CarbonImmutable::instance($date)->setTimezone(self::FUSEAU)->locale('fr')->translatedFormat('l j F Y à H:i');
    }
}
