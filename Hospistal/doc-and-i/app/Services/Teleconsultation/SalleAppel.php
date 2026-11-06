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

    public function proprietes(Consultation $consultation, User $user, string $role, string $adresseSignaux): array
    {
        return [
            'role' => $role,
            'signaux' => $adresseSignaux,
            'iceServers' => $this->serveurs->pour($user),
            'dernierSignal' => $this->signalisation->dernierId($consultation, $role),
            'intervalleMs' => self::INTERVALLE_MS,
            'seuilVideoKbps' => self::SEUIL_VIDEO_KBPS,
            'statut' => $consultation->statut->value,
        ];
    }
}
