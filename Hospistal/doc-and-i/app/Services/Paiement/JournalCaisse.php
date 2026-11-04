<?php

namespace App\Services\Paiement;

use App\Enums\StatutPaiement;
use App\Enums\StatutRemboursement;
use App\Models\OperationCaisse;
use App\Models\Paiement;
use App\Models\Remboursement;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/**
 * PAY-09 : journal de caisse, une ligne par mouvement d'argent réel. Écrire
 * deux fois le même mouvement ne crée qu'une ligne (index uniques partiels,
 * insertion « ON CONFLICT DO NOTHING ») ; aucune ligne ne se modifie.
 */
final class JournalCaisse
{
    public function encaissement(Paiement $paiement): OperationCaisse
    {
        if ($paiement->statut !== StatutPaiement::Reussi || $paiement->confirme_le === null) {
            throw new LogicException("Paiement {$paiement->id} non réussi : rien à encaisser.");
        }

        $this->inserer([
            'type' => 'encaissement',
            'paiement_id' => $paiement->id,
            'remboursement_id' => null,
            'medecin_id' => $this->medecin($paiement),
            'moyen' => $paiement->moyen->value,
            'montant' => (string) $paiement->montant,
            'devise' => $paiement->devise,
            'jour' => $paiement->confirme_le->setTimezone('Africa/Kinshasa')->toDateString(),
        ]);

        return OperationCaisse::where('type', 'encaissement')->where('paiement_id', $paiement->id)->firstOrFail();
    }

    public function sortie(Remboursement $remboursement): OperationCaisse
    {
        if ($remboursement->statut !== StatutRemboursement::Reussi || $remboursement->termine_le === null) {
            throw new LogicException("Remboursement {$remboursement->id} non réussi : rien à sortir.");
        }

        $paiement = $remboursement->paiement;

        $this->inserer([
            'type' => 'remboursement',
            'paiement_id' => $paiement->id,
            'remboursement_id' => $remboursement->id,
            'medecin_id' => $this->medecin($paiement),
            'moyen' => $paiement->moyen->value,
            'montant' => '-'.$remboursement->montant,
            'devise' => $remboursement->devise,
            'jour' => $remboursement->termine_le->setTimezone('Africa/Kinshasa')->toDateString(),
        ]);

        return OperationCaisse::where('type', 'remboursement')->where('remboursement_id', $remboursement->id)->firstOrFail();
    }

    private function inserer(array $ligne): void
    {
        $maintenant = CarbonImmutable::now();

        DB::table('operations_caisse')->insertOrIgnore($ligne + [
            'id' => (string) Str::uuid(),
            'created_at' => $maintenant,
            'updated_at' => $maintenant,
        ]);
    }

    private function medecin(Paiement $paiement): ?string
    {
        return $paiement->rendez_vous_id
            ? DB::table('rendez_vous')->where('id', $paiement->rendez_vous_id)->value('medecin_id')
            : null;
    }
}
