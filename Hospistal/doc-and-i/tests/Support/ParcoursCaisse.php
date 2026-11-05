<?php

namespace Tests\Support;

use App\Enums\MotifRemboursement;
use App\Enums\MoyenPaiement;
use App\Enums\StatutRemboursement;
use App\Models\EvenementPaiement;
use App\Models\Medecin;
use App\Models\Paiement;
use App\Models\Remboursement;
use App\Services\Paiement\FacturationService;
use App\Services\Paiement\Fournisseur\FournisseurPaiement;
use App\Services\Paiement\Fournisseur\RappelFournisseur;
use App\Services\Paiement\JournalCaisse;
use App\Services\Paiement\RemboursementService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Scénarios de caisse pour les tests : paiements encaissés (facture et
 * journal), remboursements réussis, rappels du prestataire. À utiliser avec
 * ParcoursRendezVous, ParcoursPaiement et PersonnelDeTest.
 */
trait ParcoursCaisse
{
    private int $creneauCaisse = 0;

    protected function fournisseurFactice(): FournisseurFactice
    {
        $factice = new FournisseurFactice;
        $this->app->instance(FournisseurPaiement::class, $factice);

        return $factice;
    }

    /** Paiement réussi, facturé et inscrit au journal, comme après le rappel de l'opérateur. */
    protected function paiementEncaisse(?Medecin $medecin = null, string $prix = '10.00', string $devise = 'USD', MoyenPaiement $moyen = MoyenPaiement::MPesa): Paiement
    {
        $medecin ??= $this->medecin();
        $type = $this->typeConsultation($medecin, $prix, devise: $devise);
        // Un créneau différent à chaque appel : un médecin n'a qu'un rendez-vous par heure de début.
        $rdv = $this->rendezVousExistant($medecin, $this->patient(), $this->lundi('09:00')->addMinutes(30 * $this->creneauCaisse++), type: $type);
        $paiement = $this->paiementExistant($rdv, moyen: $moyen, montant: $prix, devise: $devise);

        app(FacturationService::class)->emettreFacture($paiement);
        app(JournalCaisse::class)->encaissement($paiement);

        return $paiement;
    }

    /** Remboursement passé par tout le circuit : demandé, validé, exécuté, rappel réussi. */
    protected function remboursementReussi(Paiement $paiement, string $montant): Remboursement
    {
        $service = app(RemboursementService::class);
        $r = $service->demander($paiement, $montant, MotifRemboursement::PaiementTardif);
        $r = $service->executer($r);
        $service->traiterRappel(...$this->rappelRemboursement($r));

        $r = $r->fresh();
        if ($r->statut !== StatutRemboursement::Reussi) {
            throw new \LogicException('Le remboursement de test n\'a pas abouti : '.$r->statut->value);
        }

        return $r;
    }

    /**
     * Rappel authentifié du prestataire et l'événement enregistré, tels que
     * les remet le contrôleur des rappels.
     *
     * @return array{0: RappelFournisseur, 1: EvenementPaiement}
     */
    protected function rappelRemboursement(Remboursement $r, bool $reussi = true, ?string $montant = null, ?string $devise = null, ?string $evenement = null): array
    {
        $rappel = new RappelFournisseur(
            identifiantEvenement: $evenement ?? 'evt-'.Str::uuid(),
            type: RappelFournisseur::REMBOURSEMENT,
            referenceMarchand: $r->cle_idempotence,
            referenceFournisseur: $r->reference_fournisseur ?? 'SIM-R-'.Str::random(12),
            reussi: $reussi,
            montant: $montant ?? (string) $r->montant,
            devise: $devise ?? $r->devise,
            motifEchec: $reussi ? null : 'Compte du bénéficiaire fermé',
            chargeUtile: ['evenement' => $evenement],
        );

        $evt = new EvenementPaiement;
        $evt->forceFill([
            'fournisseur' => 'simulation',
            'identifiant_evenement' => $rappel->identifiantEvenement,
            'type' => RappelFournisseur::REMBOURSEMENT,
            'charge_utile' => $rappel->chargeUtile,
            'recu_le' => CarbonImmutable::now(),
        ])->save();

        return [$rappel, $evt];
    }
}
