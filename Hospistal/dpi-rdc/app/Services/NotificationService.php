<?php

namespace App\Services;

use App\Models\ExamenLaboratoire;
use App\Models\NotificationInterne;
use App\Models\Prescription;
use App\Models\User;

/**
 * Notifications internes entre services, sur le modèle CSK
 * (includes/notifications_helpers.php) :
 *  - chaque prescription notifie le service concerné (labo / imagerie / pharmacie) ;
 *  - résultats validés ou médicaments délivrés notifient le médecin prescripteur.
 */
class NotificationService
{
    public function envoyer(
        string $service,
        string $type,
        string $titre,
        string $message,
        ?string $referenceType = null,
        ?string $referenceId = null,
        ?string $codeReference = null,
        ?string $destinataireId = null,
        ?string $groupeDestinataire = null,
        string $priorite = 'normale'
    ): NotificationInterne {
        return NotificationInterne::create([
            'service' => $service,
            'type' => $type,
            'titre' => $titre,
            'message' => $message,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'code_reference' => $codeReference,
            'destinataire_id' => $destinataireId,
            'groupe_destinataire' => $groupeDestinataire,
            'priorite' => $priorite,
        ]);
    }

    /**
     * Équipe à prévenir pour un domaine d'examen.
     *
     * L'imagerie s'adresse aux manipulateurs quand l'établissement en a
     * affecté ; sinon elle revient au laboratoire, qui tient le plateau
     * technique dans les structures où le personnel est mutualisé.
     */
    public function groupePourDomaine(string $domaine): string
    {
        if ($domaine !== 'imagerie') {
            return 'laborantin';
        }

        return User::whereHas('roles', fn ($q) => $q->where('name', 'radiologue'))->exists()
            ? 'radiologue'
            : 'laborantin';
    }

    /**
     * Nouvelle prescription d'examens → équipe labo ou imagerie.
     */
    public function prescriptionExamen(ExamenLaboratoire $examen): NotificationInterne
    {
        $examen->loadMissing(['patient', 'resultats', 'prescripteur']);
        $service = $examen->domaine === 'imagerie' ? 'imagerie' : 'labo';
        $nb = $examen->resultats->unique('type_examen_id')->count();

        return $this->envoyer(
            service: $service,
            type: 'prescription_recue',
            titre: strtoupper($service) . ' : ' . $examen->numero_bon,
            message: 'Nouvelle prescription — ' . $examen->patient->nom_complet
                . " ({$nb} examen(s))"
                . ($examen->prescripteur ? ' — Dr ' . $examen->prescripteur->nom : ''),
            referenceType: 'examen',
            referenceId: $examen->id,
            codeReference: $examen->numero_bon,
            groupeDestinataire: $this->groupePourDomaine($examen->domaine),
            priorite: $examen->urgence ? 'urgente' : 'normale',
        );
    }

    /**
     * Résultats validés → médecin prescripteur.
     */
    public function resultatsPrets(ExamenLaboratoire $examen): ?NotificationInterne
    {
        if (! $examen->prescripteur_id) {
            return null;
        }

        $examen->loadMissing('patient');
        $service = $examen->domaine === 'imagerie' ? 'imagerie' : 'labo';

        return $this->envoyer(
            service: $service,
            type: 'resultat_pret',
            titre: strtoupper($service) . ' : ' . $examen->numero_bon,
            message: ($examen->domaine === 'imagerie' ? 'Compte-rendu disponible' : 'Résultats disponibles')
                . ' — ' . $examen->patient->nom_complet,
            referenceType: 'examen',
            referenceId: $examen->id,
            codeReference: $examen->numero_bon,
            destinataireId: $examen->prescripteur_id,
            priorite: 'haute',
        );
    }

    /**
     * Nouvelle ordonnance → pharmacie.
     */
    public function prescriptionPharmacie(Prescription $prescription): NotificationInterne
    {
        $prescription->loadMissing(['patient', 'lignes', 'prescripteur']);

        return $this->envoyer(
            service: 'pharmacie',
            type: 'prescription_recue',
            titre: 'PHARMACIE : ordonnance ' . strtoupper(substr($prescription->id, 0, 8)),
            message: 'Nouvelle ordonnance — ' . $prescription->patient->nom_complet
                . ' (' . $prescription->lignes->count() . ' ligne(s))'
                . ($prescription->prescripteur ? ' — Dr ' . $prescription->prescripteur->nom : ''),
            referenceType: 'prescription',
            referenceId: $prescription->id,
            groupeDestinataire: 'pharmacien',
        );
    }

    /**
     * Médicaments délivrés → médecin prescripteur.
     */
    public function medicamentsDelivres(Prescription $prescription): ?NotificationInterne
    {
        if (! $prescription->prescripteur_id) {
            return null;
        }

        $prescription->loadMissing('patient');

        return $this->envoyer(
            service: 'pharmacie',
            type: 'medicament_delivre',
            titre: 'PHARMACIE : ordonnance ' . strtoupper(substr($prescription->id, 0, 8)),
            message: 'Médicaments délivrés — ' . $prescription->patient->nom_complet,
            referenceType: 'prescription',
            referenceId: $prescription->id,
            destinataireId: $prescription->prescripteur_id,
            priorite: 'haute',
        );
    }

    public function nonLuesPour(User $user, ?string $service = null): int
    {
        return NotificationInterne::query()
            ->pourUtilisateur($user)
            ->actives()
            ->nonLues()
            ->when($service, fn ($q) => $q->where('service', $service))
            ->count();
    }
}
