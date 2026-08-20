<?php

namespace App\Services;

use App\Models\Facture;
use App\Models\Forfait;
use App\Models\LigneFacture;
use App\Models\Visit;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Règles de forfait.
 *
 * Trois régimes de prise en charge cohabitent :
 *
 *  - **Forfait global** : un montant unique couvre tout le séjour. Aucune
 *    prestation n'est facturée à l'acte tant que la durée incluse n'est pas
 *    dépassée.
 *  - **Forfait partiel** : le montant couvre les catégories cochées ; le
 *    reste suit la facturation normale.
 *  - **Convention** : la société ou la mutuelle prend en charge selon son
 *    taux et son plafond (règles portées par le tiers payant).
 *
 * Un séjour peut cumuler un forfait et une convention : le forfait
 * s'applique d'abord, la convention couvre ensuite ce qui reste dû.
 */
class ForfaitService
{
    /**
     * Applique un forfait à un séjour et émet sa facture. Le montant est
     * figé : une révision ultérieure du tarif ne réécrit pas le passé.
     */
    public function appliquer(Visit $visit, Forfait $forfait): Facture
    {
        return DB::transaction(function () use ($visit, $forfait) {
            $facture = app(FacturationService::class)->creerFactureAmbulatoire(
                $visit->patient,
                $visit,
                'autre',
                'Forfait '.$forfait->libelle,
                (float) $forfait->montant,
                $forfait->devise,
                $forfait->id
            );

            $visit->update([
                'forfait_id' => $forfait->id,
                'forfait_montant' => $forfait->montant,
                'forfait_facture_id' => $facture->id,
            ]);

            app(AcompteService::class)->imputer($facture);

            return $facture->fresh();
        });
    }

    /** Retire le forfait d'un séjour ; sa facture reste à annuler au guichet. */
    public function retirer(Visit $visit): void
    {
        $visit->update([
            'forfait_id' => null,
            'forfait_montant' => null,
            'forfait_facture_id' => null,
        ]);
    }

    /**
     * Le forfait du séjour prend-il en charge cette catégorie de prestation ?
     * Au-delà de la durée incluse, le forfait cesse de couvrir et tout
     * redevient facturable à l'acte.
     */
    public function couvre(Visit $visit, string $categorie): bool
    {
        $forfait = $visit->forfait;

        if (! $forfait || ! $forfait->couvreEncore($visit)) {
            return false;
        }

        return $forfait->couvre($categorie);
    }

    /**
     * Écarte des lignes à facturer celles que le forfait prend déjà en
     * charge, et renvoie ce qui reste réellement à porter sur la facture.
     *
     * @param  array<int, array{type: string, libelle: string, reference_id?: ?string, quantite: float|int, prix_unitaire: float}>  $lignes
     * @return array{lignes: array<int, array<string, mixed>>, couvertes: array<int, array<string, mixed>>}
     */
    public function filtrerLignes(Visit $visit, array $lignes): array
    {
        if (! $visit->forfait_id) {
            return ['lignes' => $lignes, 'couvertes' => []];
        }

        $retenues = [];
        $couvertes = [];

        foreach ($lignes as $ligne) {
            if ($this->couvre($visit, $ligne['type'])) {
                $couvertes[] = $ligne;
            } else {
                $retenues[] = $ligne;
            }
        }

        return ['lignes' => $retenues, 'couvertes' => $couvertes];
    }

    /**
     * Forfaits proposables pour un séjour : ceux de l'établissement, plus
     * ceux réservés à la convention du patient.
     *
     * @return Collection<int, Forfait>
     */
    public function disponiblesPour(Visit $visit): Collection
    {
        $assurance = app(FacturationService::class)
            ->resolvePatientAssurance($visit->patient)?->assurance_id;

        return Forfait::where('establishment_id', $visit->establishment_id)
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('assurance_id')
                ->when($assurance, fn ($q2) => $q2->orWhere('assurance_id', $assurance)))
            ->orderBy('libelle')
            ->get();
    }

    /**
     * Ce que le forfait a effectivement épargné au patient sur ce séjour :
     * les lignes déjà facturées qu'il aurait fallu payer sans lui.
     */
    public function economieEstimee(Visit $visit): float
    {
        if (! $visit->forfait_id) {
            return 0.0;
        }

        $categories = $visit->forfait->estGlobal()
            ? array_keys(Forfait::CATEGORIES)
            : ($visit->forfait->categories_couvertes ?? []);

        return (float) LigneFacture::whereIn('facture_id', $visit->factures()->pluck('id'))
            ->whereIn('type', $categories)
            ->sum('total_ligne');
    }
}
