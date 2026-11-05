<?php

namespace App\Services\Caisse;

use App\Enums\MoyenPaiement;
use App\Models\Medecin;
use App\Models\OperationCaisse;
use App\Services\RendezVous\Affichage;
use App\Support\Montant;
use Carbon\CarbonImmutable;

/**
 * PAY-09 : lecture du journal de caisse, par jour, par moyen, par médecin.
 * Les totaux sont TOUJOURS par devise : on n'additionne jamais des dollars
 * et des francs congolais.
 */
final class JournalCaisseConsultation
{
    /** Au-delà, l'écran deviendrait trop lourd en 3G. */
    private const LIGNES_MAX = 500;

    /**
     * @return array{lignes: list<array<string, mixed>>, totaux: list<array<string, string>>, parMoyen: list<array<string, string>>, parMedecin: list<array<string, string>>, tronque: bool}
     */
    public function consulter(CarbonImmutable $du, CarbonImmutable $au, ?string $moyen = null, ?string $medecinId = null): array
    {
        $requete = OperationCaisse::query()
            ->whereBetween('jour', [$du->toDateString(), $au->toDateString()])
            ->when($moyen, fn ($q) => $q->where('moyen', $moyen))
            ->when($medecinId, fn ($q) => $q->where('medecin_id', $medecinId));

        $operations = (clone $requete)->with(['paiement.facture', 'medecin.user'])
            ->orderBy('jour')->orderBy('created_at')->limit(self::LIGNES_MAX + 1)->get();

        $lignes = $operations->take(self::LIGNES_MAX)->map(fn (OperationCaisse $o) => [
            'id' => $o->id,
            'jour' => $o->jour->format('d/m/Y'),
            'type' => $o->type === 'encaissement' ? 'Encaissement' : 'Remboursement',
            'moyen' => MoyenPaiement::from($o->moyen)->libelle(),
            'medecin' => $o->medecin ? Affichage::medecin($o->medecin) : null,
            'facture' => $o->paiement?->facture?->numero,
            'montant' => Montant::afficher((string) $o->montant, $o->devise),
            'devise' => $o->devise,
        ])->values()->all();

        return [
            'lignes' => $lignes,
            'tronque' => $operations->count() > self::LIGNES_MAX,
            'totaux' => $this->totaux(clone $requete, []),
            'parMoyen' => array_map(fn ($t) => $t + ['libelle' => MoyenPaiement::from($t['moyen'])->libelle()], $this->totaux(clone $requete, ['moyen'])),
            'parMedecin' => array_map(function ($t) {
                $medecin = $t['medecin_id'] ? Medecin::with('user')->find($t['medecin_id']) : null;

                return $t + ['libelle' => $medecin ? Affichage::medecin($medecin) : 'Sans médecin'];
            }, $this->totaux(clone $requete, ['medecin_id'])),
        ];
    }

    /**
     * Encaissé, remboursé et net, en centimes additionnés par la base (montants
     * decimal), regroupés par devise et par les colonnes demandées.
     *
     * @param  list<string>  $groupes
     * @return list<array<string, string>>
     */
    private function totaux($requete, array $groupes): array
    {
        return $requete->toBase()
            ->selectRaw(implode(', ', array_merge($groupes, ['devise'])).",
                COALESCE(SUM(montant) FILTER (WHERE type = 'encaissement'), 0) AS encaisse,
                COALESCE(-SUM(montant) FILTER (WHERE type = 'remboursement'), 0) AS rembourse,
                SUM(montant) AS net,
                COUNT(*) AS operations")
            ->groupBy(array_merge($groupes, ['devise']))
            ->orderBy('devise')
            ->get()
            ->map(function ($t) use ($groupes) {
                $ligne = [];
                foreach ($groupes as $g) {
                    $ligne[$g] = $t->{$g};
                }

                return $ligne + [
                    'devise' => $t->devise,
                    'encaisse' => Montant::afficher(self::decimal($t->encaisse), $t->devise),
                    'rembourse' => Montant::afficher(self::decimal($t->rembourse), $t->devise),
                    'net' => Montant::afficher(self::decimal($t->net), $t->devise),
                    'operations' => (string) $t->operations,
                ];
            })->all();
    }

    /** PostgreSQL rend « 15.00 », « 0 » ou « -5.00 » : ramené à deux décimales. */
    private static function decimal(string|int|float $valeur): string
    {
        return Montant::depuisCentimes(Montant::enCentimes((string) $valeur));
    }
}
