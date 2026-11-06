<?php

namespace App\Services\Personnel;

use App\Enums\StatutRendezVous;
use App\Models\Consultation;
use App\Models\Medecin;
use App\Models\RendezVous;
use App\Services\Teleconsultation\AccesConsultation;

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
        $rendezVous = $medecin->rendezVous()
            ->with(['patient:id,nom,prenom', 'demande:id,motif'])
            ->where('fin', '>', now())
            // Un créneau expiré faute de paiement n'a jamais été un rendez-vous.
            ->where('statut', '!=', StatutRendezVous::Expire->value)
            ->orderBy('debut')
            ->limit(self::LIMITE)
            ->get();

        // Statut de la salle de téléconsultation (TLC-01), quand elle existe.
        $salles = Consultation::whereIn('rendez_vous_id', $rendezVous->pluck('id'))->get()->keyBy('rendez_vous_id');

        return $rendezVous
            ->map(function (RendezVous $rdv) use ($salles) {
                $debut = $rdv->debut->setTimezone(DisponibilitesMedecinService::FUSEAU);
                // Revue de sécurité : un créneau bloqué le temps du paiement, ou
                // annulé, n'est pas (ou plus) une relation de soin. Le médecin
                // n'en voit que l'heure et le statut ; nom et motif seulement
                // pour un rendez-vous confirmé ou une consultation terminée.
                $relationDeSoin = in_array($rdv->statut, [StatutRendezVous::Confirme, StatutRendezVous::Termine], true);
                $salle = $salles->get($rdv->id);

                return [
                    'id' => $rdv->id,
                    'date' => $debut->locale('fr')->translatedFormat('l j F Y'),
                    'heure' => $debut->format('H:i'),
                    'heureFin' => $rdv->fin->setTimezone(DisponibilitesMedecinService::FUSEAU)->format('H:i'),
                    'patient' => $relationDeSoin ? trim($rdv->patient->prenom.' '.mb_strtoupper((string) $rdv->patient->nom)) : null,
                    'motif' => $relationDeSoin ? $rdv->demande?->motif : null,
                    'statut' => self::libelle($rdv->statut),
                    'statutCode' => $rdv->statut->value,
                    // RG-05 : seul un rendez-vous confirmé, pas encore commencé, s'annule.
                    'annulable' => $rdv->statut === StatutRendezVous::Confirme && $rdv->debut->isFuture(),
                    'consultation' => $salle?->statut->value,
                    'consultationLibelle' => $salle?->statut->libelle(),
                    // Bouton « Rejoindre » : rendez-vous confirmé, salle dans sa fenêtre horaire, pas close.
                    'rejoignable' => $rdv->statut === StatutRendezVous::Confirme
                        && app(AccesConsultation::class)->dansLaFenetre($rdv)
                        && ($salle === null || $salle->statut->estOuverte()),
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
