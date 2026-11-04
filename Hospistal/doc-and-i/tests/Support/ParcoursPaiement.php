<?php

namespace Tests\Support;

use App\Enums\MoyenPaiement;
use App\Enums\StatutPaiement;
use App\Models\Paiement;
use App\Models\RendezVous;
use App\Models\TauxChange;
use App\Models\User;
use App\Services\Paiement\Fournisseur\FournisseurPaiement;
use App\Services\Paiement\Fournisseur\FournisseurSimulation;
use App\Services\Paiement\Fournisseur\SignatureRappel;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * Paiements fictifs pour les tests. À utiliser avec ParcoursRendezVous.
 * Aucun argent ne circule : pilote « simulation » imposé par phpunit.xml.
 */
trait ParcoursPaiement
{
    /** Paiement posé directement en base, sans passer par le service. */
    protected function paiementExistant(
        RendezVous $rdv,
        StatutPaiement $statut = StatutPaiement::Reussi,
        MoyenPaiement $moyen = MoyenPaiement::MPesa,
        ?string $montant = null,
        string $devise = 'USD',
    ): Paiement {
        $montant ??= (string) $rdv->prix;
        $paiement = new Paiement;
        $paiement->forceFill([
            'patient_id' => $rdv->patient_id,
            'rendez_vous_id' => $rdv->id,
            'objet' => 'consultation',
            'moyen' => $moyen,
            'telephone_payeur' => $moyen->estMobileMoney() ? '+243810000001' : null,
            'montant' => $montant,
            'devise' => $devise,
            'montant_reference' => (string) $rdv->prix,
            'devise_reference' => $rdv->devise,
            'statut' => $statut,
            'cle_idempotence' => (string) Str::uuid(),
            'fournisseur' => FournisseurSimulation::NOM,
            'reference_fournisseur' => 'SIM-P-'.Str::random(20),
            'confirme_le' => $statut === StatutPaiement::Reussi ? CarbonImmutable::now() : null,
        ])->save();

        return $paiement;
    }

    protected function tauxDuJour(string $cdfParUsd = '2850.0000', ?User $saisiPar = null): TauxChange
    {
        $taux = new TauxChange;
        $taux->forceFill([
            'jour' => CarbonImmutable::now('Africa/Kinshasa')->toDateString(),
            'cdf_par_usd' => $cdfParUsd,
            'saisi_par' => ($saisiPar ?? User::factory()->sansRole()->create())->id,
        ])->save();

        return $taux;
    }

    protected function simulation(): FournisseurSimulation
    {
        return app(FournisseurPaiement::class);
    }

    /**
     * Envoie un rappel signé, comme l'agrégateur, sur l'adresse publique des
     * rappels. $entete remplace la signature (tests de signature fausse).
     */
    protected function envoyerRappel(array $rappel, ?string $entete = null): TestResponse
    {
        return $this->call('POST', '/paiement/rappel/'.FournisseurSimulation::NOM, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_'.strtoupper(str_replace('-', '_', SignatureRappel::ENTETE)) => $entete ?? $rappel['entete'],
        ], $rappel['corps']);
    }
}
