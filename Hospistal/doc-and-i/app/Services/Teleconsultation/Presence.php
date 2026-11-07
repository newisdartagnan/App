<?php

namespace App\Services\Teleconsultation;

use App\Enums\StatutConsultation;
use App\Models\Consultation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Présence réelle dans l'appel (revue de sécurité ; RG-06, RG-07).
 *
 * Chaque navigateur dont la connexion WebRTC est établie (« connected »)
 * envoie un battement toutes les presence_intervalle_secondes. Le serveur
 * retient le dernier battement de chacun :
 * - la salle passe « en cours » au premier battement où l'autre a battu
 *   lui aussi depuis moins de presence_tolerance_secondes (connectés
 *   ensemble) ;
 * - secondes_ensemble cumule le temps réellement partagé : chaque
 *   battement compte l'écart depuis la dernière mesure, plafonné à
 *   l'intervalle toléré, et seulement quand les deux sont présents.
 *
 * FinConsultation décide sur ces données : pas de « en cours » = personne
 * n'a vu personne (absence) ; trop peu de secondes ensemble = interrompue.
 * Un battement ne se rejoue pas utilement : il ne compte que du temps réel.
 */
final class Presence
{
    public function __construct(private readonly AccesConsultation $acces) {}

    /**
     * @return array{statut: string, ensemble: bool, secondes_ensemble: int}
     *
     * @throws AccesRefuse
     */
    public function battement(Consultation $consultation, User $user): array
    {
        return DB::transaction(function () use ($consultation, $user) {
            $c = Consultation::with('rendezVous')->whereKey($consultation->id)->lockForUpdate()->firstOrFail();
            $rdv = $c->rendezVous;

            $role = $this->acces->role($user, $rdv) ?? throw new AccesRefuse('Cette consultation ne vous concerne pas.');
            if (! $c->statut->estOuverte() || ! $this->acces->dansLaFenetre($rdv)) {
                throw new AccesRefuse('La salle de consultation est fermée.');
            }

            $config = config('doc-and-i.teleconsultation');
            $maintenant = CarbonImmutable::now();
            $moi = $role === AccesConsultation::PATIENT ? 'presence_patient_le' : 'presence_medecin_le';
            $autre = $role === AccesConsultation::PATIENT ? 'presence_medecin_le' : 'presence_patient_le';

            $c->{$moi} = $maintenant;
            $ensemble = $c->{$autre} !== null
                && $maintenant->getTimestamp() - $c->{$autre}->getTimestamp() <= $config['presence_tolerance_secondes'];

            if ($ensemble) {
                if ($c->statut === StatutConsultation::EnAttente) {
                    $c->transitionVers(StatutConsultation::EnCours);
                    $c->debut_le = $maintenant;
                    $c->ensemble_le = $maintenant;
                } elseif ($c->mesure_ensemble_le !== null) {
                    $ecart = $maintenant->getTimestamp() - $c->mesure_ensemble_le->getTimestamp();
                    $c->secondes_ensemble = $c->secondes_ensemble + max(0, min($ecart, $config['presence_tolerance_secondes']));
                }
                $c->mesure_ensemble_le = $maintenant;
            } else {
                // Séparés : la prochaine retrouvaille repart de zéro, la coupure ne compte pas.
                $c->mesure_ensemble_le = null;
            }

            $c->save();

            return ['statut' => $c->statut->value, 'ensemble' => $ensemble, 'secondes_ensemble' => $c->secondes_ensemble];
        });
    }
}
