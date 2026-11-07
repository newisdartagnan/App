<?php

namespace App\Services\Teleconsultation;

use App\Models\Consultation;
use App\Models\User;

/**
 * Ce que la page de la salle donne au composant AppelVideo, pour le patient
 * comme pour le médecin : rôle, adresse de la signalisation, serveurs ICE
 * (TURN éphémère), dernier signal déjà adressé (on ne relit pas l'historique).
 */
final class SalleAppel
{
    /** Interrogation de la signalisation : assez rapide pour l'établissement de l'appel, sobre en 3G. */
    public const INTERVALLE_MS = 1500;

    /** TLC-02 : en dessous, en vidéo reçue, l'appel bascule en audio seul. */
    public const SEUIL_VIDEO_KBPS = 150;

    public function __construct(private readonly ServeursIce $serveurs, private readonly Signalisation $signalisation) {}

    public function proprietes(Consultation $consultation, User $user, string $role, string $adresseSignaux, string $adressePresence): array
    {
        $config = config('doc-and-i.teleconsultation');
        $serveurs = $this->serveurs->pour($user, $consultation->rendezVous->fin->addMinutes((int) $config['fermeture_minutes_apres']));

        return [
            'role' => $role,
            'signaux' => $adresseSignaux,
            // Battement de présence tant que la connexion WebRTC est établie (Presence::battement).
            'presence' => $adressePresence,
            'presenceIntervalleMs' => (int) $config['presence_intervalle_secondes'] * 1000,
            'iceServers' => $serveurs,
            'iceTransportPolicy' => self::politiqueTransport($serveurs),
            'dernierSignal' => $this->signalisation->dernierId($consultation, $role),
            'intervalleMs' => self::INTERVALLE_MS,
            'seuilVideoKbps' => self::SEUIL_VIDEO_KBPS,
            'statut' => $consultation->statut->value,
            'secondesEnsemble' => (int) $consultation->secondes_ensemble,
        ];
    }

    /**
     * Avec un relais TURN, tout passe par lui (« relay ») : l'adresse IP de
     * l'un n'est jamais donnée à l'autre. Sans TURN (poste de développement),
     * connexion directe (« all »), sinon l'appel ne s'établirait pas.
     *
     * @param  list<array{urls: list<string>, username?: string, credential?: string}>  $serveurs
     */
    public static function politiqueTransport(array $serveurs): string
    {
        $avecTurn = collect($serveurs)->contains(fn (array $s) => isset($s['credential'])
            && collect($s['urls'])->contains(fn (string $url) => str_starts_with($url, 'turn:') || str_starts_with($url, 'turns:')));

        return $avecTurn && config('doc-and-i.teleconsultation.relais_seulement') ? 'relay' : 'all';
    }
}
