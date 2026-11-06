<?php

namespace App\Services\RendezVous;

use App\Enums\StatutRendezVous;
use App\Models\Medecin;
use App\Models\RendezVous;
use App\Models\Specialite;
use App\Models\TypeConsultation;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * RDV-04 : créneaux libres d'un médecin pour un type de consultation.
 *
 * Les plages sont hebdomadaires, en heure de Kinshasa (fuseau de
 * l'application, UTC+1 toute l'année, sans changement d'heure). Un créneau
 * dure le temps du type ; il est retiré s'il touche une absence ou un
 * rendez-vous occupant (confirmé, ou en attente de paiement non échue), quelle
 * que soit la durée de ce dernier, s'il est passé, ou s'il commence dans moins
 * de delai_minimum_minutes. Tout est calculé à partir de now() : les tests
 * fixent l'heure avec travelTo().
 */
class CreneauxService
{
    /** Heure de Kinshasa, quel que soit le fuseau de la valeur reçue. */
    public static function local(DateTimeInterface|string $instant): CarbonImmutable
    {
        return CarbonImmutable::parse($instant)->setTimezone(config('app.timezone'));
    }

    /**
     * Rendez-vous qui occupent le créneau du médecin à cet instant : confirmé,
     * ou en attente de paiement dont le délai n'est pas échu.
     */
    public static function occupants(Builder $requete, CarbonImmutable $maintenant): Builder
    {
        return $requete->where(fn (Builder $q) => $q
            ->where('statut', StatutRendezVous::Confirme->value)
            ->orWhere(fn (Builder $q) => $q
                ->where('statut', StatutRendezVous::EnAttentePaiement->value)
                ->where(fn (Builder $q) => $q->whereNull('expire_le')->orWhere('expire_le', '>', $maintenant))));
    }

    /**
     * Refuse un couple médecin / type qu'un patient ne peut pas réserver
     * (RDV-03) : médecin inactif ou non vérifié, type inactif, d'une autre
     * spécialité, ou dont le prix n'est pas encore fixé.
     *
     * @throws ReservationRefusee
     */
    public function verifierOffre(Medecin $medecin, TypeConsultation $type): void
    {
        if (! $medecin->estReservable()) {
            throw new ReservationRefusee('Ce médecin ne reçoit pas de rendez-vous en ce moment.');
        }

        if ($type->specialite_id !== $medecin->specialite_id) {
            throw new ReservationRefusee('Ce type de consultation n\'est pas proposé par ce médecin.');
        }

        $this->verifierType($type);
    }

    /**
     * Refuse un type que l'administration ne propose pas (ADM-01) : type
     * inactif, spécialité inactive, ou prix pas encore fixé. Les rendez-vous
     * déjà pris ne passent pas par ici : ils restent payables et reportables.
     *
     * @throws ReservationRefusee
     */
    public function verifierType(TypeConsultation $type): void
    {
        if (! $type->actif || ! Specialite::whereKey($type->specialite_id)->where('active', true)->exists()) {
            throw new ReservationRefusee('Ce type de consultation n\'est pas proposé en ce moment.');
        }

        if ($type->prix === null) {
            throw new ReservationRefusee('Le prix de cette consultation n\'est pas encore fixé : elle ne peut pas encore être réservée.');
        }
    }

    /**
     * Créneaux libres, du plus tôt au plus tard, sur l'horizon configuré
     * (aujourd'hui compris).
     *
     * @param  string|null  $ignorer  rendez-vous à ne pas compter comme occupant (son propre report)
     * @return list<CarbonImmutable>
     */
    public function creneaux(Medecin $medecin, TypeConsultation $type, ?string $ignorer = null): array
    {
        $maintenant = CarbonImmutable::now(config('app.timezone'));
        $auPlusTot = $maintenant->addMinutes((int) config('doc-and-i.rendez_vous.delai_minimum_minutes'));
        $premierJour = $maintenant->startOfDay();
        $finHorizon = $premierJour->addDays((int) config('doc-and-i.rendez_vous.horizon_jours'));
        $duree = $type->duree_minutes;

        $plages = $medecin->plages()->get()->groupBy('jour_semaine');

        $absences = $medecin->absences()
            ->where('debut', '<', $finHorizon)
            ->where('fin', '>', $maintenant)
            ->get(['debut', 'fin']);

        $occupes = self::occupants(RendezVous::query(), $maintenant)
            ->where('medecin_id', $medecin->id)
            ->where('debut', '<', $finHorizon)
            ->where('fin', '>', $maintenant)
            ->when($ignorer, fn (Builder $q) => $q->whereKeyNot($ignorer))
            ->get(['debut', 'fin']);

        $creneaux = [];

        for ($jour = $premierJour; $jour < $finHorizon; $jour = $jour->addDay()) {
            foreach ($plages->get($jour->dayOfWeekIso, []) as $plage) {
                $finPlage = $jour->setTimeFromTimeString($plage->heure_fin);

                for ($debut = $jour->setTimeFromTimeString($plage->heure_debut); $debut->addMinutes($duree) <= $finPlage; $debut = $debut->addMinutes($duree)) {
                    $fin = $debut->addMinutes($duree);

                    if ($debut < $auPlusTot || $this->touche($absences, $debut, $fin) || $this->touche($occupes, $debut, $fin)) {
                        continue;
                    }

                    // Deux plages qui se recouvrent ne donnent pas deux fois la même heure.
                    $creneaux[$debut->getTimestamp()] = $debut;
                }
            }
        }

        ksort($creneaux);

        return array_values($creneaux);
    }

    /** Ce créneau fait-il partie de ceux que le service proposerait ? */
    public function estPropose(Medecin $medecin, TypeConsultation $type, DateTimeInterface $debut, ?string $ignorer = null): bool
    {
        $instant = $debut->getTimestamp();

        foreach ($this->creneaux($medecin, $type, $ignorer) as $creneau) {
            if ($creneau->getTimestamp() === $instant) {
                return true;
            }
        }

        return false;
    }

    /**
     * « Premier médecin disponible » (RDV-03) : les créneaux de tous les
     * médecins réservables de la spécialité du type. Chaque heure n'apparaît
     * qu'une fois, avec le premier médecin libre à cette heure.
     *
     * @return list<array{debut: CarbonImmutable, medecin: Medecin}>
     */
    public function premiersDisponibles(TypeConsultation $type): array
    {
        $medecins = Medecin::reservables()
            ->where('specialite_id', $type->specialite_id)
            ->with('user')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $creneaux = [];

        foreach ($medecins as $medecin) {
            foreach ($this->creneaux($medecin, $type) as $debut) {
                $creneaux[$debut->getTimestamp()] ??= ['debut' => $debut, 'medecin' => $medecin];
            }
        }

        ksort($creneaux);

        return array_values($creneaux);
    }

    /** @param  Collection<int, Model>  $periodes */
    private function touche(Collection $periodes, CarbonImmutable $debut, CarbonImmutable $fin): bool
    {
        foreach ($periodes as $periode) {
            if ($periode->debut < $fin && $periode->fin > $debut) {
                return true;
            }
        }

        return false;
    }
}
