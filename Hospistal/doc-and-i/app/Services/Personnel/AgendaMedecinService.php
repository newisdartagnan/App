<?php

namespace App\Services\Personnel;

use App\Enums\StatutRendezVous;
use App\Models\Medecin;
use App\Models\RendezVous;

/**
 * Agenda du médecin (lecture seule pour ce lot) : ses rendez-vous à venir,
 * avec le strict nécessaire du patient (prénom, nom) et le motif.
 */
final class AgendaMedecinService
{
    /** Au-delà, l'écran deviendrait trop lourd en 3G ; la pagination viendra avec le volume réel. */
    private const LIMITE = 200;

    public function aVenir(Medecin $medecin): array
    {
        return $medecin->rendezVous()
            ->with(['patient:id,nom,prenom', 'demande:id,motif'])
            ->where('fin', '>', now())
            // Un créneau expiré faute de paiement n'a jamais été un rendez-vous.
            ->where('statut', '!=', StatutRendezVous::Expire->value)
            ->orderBy('debut')
            ->limit(self::LIMITE)
            ->get()
            ->map(function (RendezVous $rdv) {
                $debut = $rdv->debut->setTimezone(DisponibilitesMedecinService::FUSEAU);

                return [
                    'id' => $rdv->id,
                    'date' => $debut->locale('fr')->translatedFormat('l j F Y'),
                    'heure' => $debut->format('H:i'),
                    'heureFin' => $rdv->fin->setTimezone(DisponibilitesMedecinService::FUSEAU)->format('H:i'),
                    'patient' => trim($rdv->patient->prenom.' '.mb_strtoupper((string) $rdv->patient->nom)),
                    'motif' => $rdv->demande?->motif,
                    'statut' => self::libelle($rdv->statut),
                    'statutCode' => $rdv->statut->value,
                ];
            })->all();
    }

    public function nombreAVenir(Medecin $medecin): int
    {
        return $medecin->rendezVous()->where('fin', '>', now())->whereIn('statut', StatutRendezVous::occupants())->count();
    }

    /** StatutRendezVous::libelle() parle au patient (« Annulé par vous ») ; ici, au médecin. */
    public static function libelle(StatutRendezVous $statut): string
    {
        return match ($statut) {
            StatutRendezVous::AnnuleParPatient => 'Annulé par le patient',
            StatutRendezVous::AnnuleParMedecin => 'Annulé par vous',
            StatutRendezVous::AbsencePatient => 'Patient absent',
            StatutRendezVous::AbsenceMedecin => 'Vous étiez absent',
            default => $statut->libelle(),
        };
    }
}
