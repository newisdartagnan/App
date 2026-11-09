<?php

namespace App\Services\Laboratoire;

use App\Enums\StatutBonExamen;
use App\Enums\StatutPaiement;
use App\Models\BonExamen;
use App\Models\Paiement;
use App\Models\Partenaire;
use App\Models\ResultatExamen;
use App\Models\User;
use App\Services\RendezVous\Affichage;
use App\Support\Montant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Les bons d'examen du patient (PRE-04, PRE-05, PRE-07) : « Mes examens ».
 *
 * - Laboratoire choisi par le patient avant de payer, si le médecin l'a
 *   laissé à son choix.
 * - Code de retrait : tiré au hasard quand le patient le demande, sur un bon
 *   payé ; montré UNE fois, gardé par son empreinte seulement (HMAC avec la
 *   clé de l'application, propre au bon). Perdu : un nouveau code remplace
 *   l'ancien.
 * - Résultats : VALIDÉS par le médecin seulement ; rien avant (PRE-07).
 */
final class BonsPatient
{
    public function liste(User $patient): array
    {
        $bons = BonExamen::with(['lignes', 'laboratoire:id,nom,commune,adresse,telephone', 'medecin.user:id,prenom,nom'])
            ->where('patient_id', $patient->id)
            ->orderByDesc('created_at')->orderByDesc('numero')->get();
        $enCours = Paiement::whereIn('bon_examen_id', $bons->pluck('id'))->whereIn('statut', StatutPaiement::enCours())->pluck('id', 'bon_examen_id');
        // Le patient ne voit que les résultats validés.
        $valides = ResultatExamen::whereIn('bon_id', $bons->pluck('id'))->whereNotNull('valide_le')->orderBy('valide_le')->get()->groupBy('bon_id');

        return $bons->map(fn (BonExamen $b) => [
            'id' => $b->id,
            'numero' => $b->numero,
            'statut' => $b->statut->value,
            'statutLibelle' => $b->statut->libelle(),
            'prescritLe' => Affichage::jour($b->created_at),
            'medecin' => Affichage::medecin($b->medecin),
            'montant' => Montant::afficher((string) $b->montant, $b->devise),
            'examens' => $b->lignes->pluck('libelle')->all(),
            'dateSouhaitee' => $b->date_souhaitee ? Affichage::jour($b->date_souhaitee) : null,
            'laboratoire' => $b->laboratoire?->only(['id', 'nom', 'commune', 'adresse', 'telephone']),
            'aChoisirLaboratoire' => $b->statut === StatutBonExamen::Prescrit && $b->laboratoire_id === null,
            'payable' => $b->statut === StatutBonExamen::Prescrit && $b->laboratoire_id !== null && ! isset($enCours[$b->id]),
            'paiementEnCours' => $enCours[$b->id] ?? null,
            'codeDisponible' => $b->statut === StatutBonExamen::Paye,
            'codeDejaRemis' => $b->code_retrait_remis_le !== null,
            'resultats' => ($valides[$b->id] ?? collect())->map(fn (ResultatExamen $r) => self::presenterResultat($r))->values()->all(),
        ])->all();
    }

    /** @throws LaboratoireRefuse */
    public function choisirLaboratoire(User $patient, BonExamen $bon, string $laboratoireId): BonExamen
    {
        return DB::transaction(function () use ($patient, $bon, $laboratoireId) {
            $b = BonExamen::whereKey($bon->id)->lockForUpdate()->firstOrFail();
            if ($b->patient_id !== $patient->id) {
                throw new LaboratoireRefuse('Ce bon d\'examen n\'est pas le vôtre.');
            }
            if ($b->statut !== StatutBonExamen::Prescrit) {
                throw new LaboratoireRefuse('Ce bon est déjà payé : le laboratoire ne change plus.');
            }
            // Laboratoire désigné par le médecin, ou déjà choisi : il ne change plus (contrôle serveur, pas seulement l'écran).
            if ($b->laboratoire_id !== null) {
                throw new LaboratoireRefuse('Le laboratoire de ce bon est déjà choisi.');
            }
            if (Paiement::where('bon_examen_id', $b->id)->whereIn('statut', StatutPaiement::enCours())->exists()) {
                throw new LaboratoireRefuse('Un paiement est en cours pour ce bon : attendez sa confirmation.');
            }
            $labo = Partenaire::whereKey($laboratoireId)->where('type', 'laboratoire')->where('actif', true)->first()
                ?? throw new LaboratoireRefuse('Ce laboratoire n\'est pas (ou plus) partenaire.');

            $b->laboratoire_id = $labo->id;
            $b->save();

            return $b;
        });
    }

    /**
     * Tire et remet le code de retrait d'un bon payé : la valeur en clair
     * n'est rendue qu'ici, une fois. Sans $nouveau, un code déjà remis n'est
     * pas redonné (il n'est gardé que par son empreinte).
     *
     * @throws LaboratoireRefuse
     */
    public function remettreCode(User $patient, BonExamen $bon, bool $nouveau = false): string
    {
        return DB::transaction(function () use ($patient, $bon, $nouveau) {
            $b = BonExamen::whereKey($bon->id)->lockForUpdate()->firstOrFail();
            if ($b->patient_id !== $patient->id) {
                throw new LaboratoireRefuse('Ce bon d\'examen n\'est pas le vôtre.');
            }
            if ($b->statut !== StatutBonExamen::Paye) {
                throw new LaboratoireRefuse($b->statut === StatutBonExamen::Prescrit
                    ? 'Le code de retrait est donné une fois le bon payé.'
                    : 'Le laboratoire vous a déjà reçu : plus besoin de code.');
            }
            if ($b->code_retrait_empreinte !== null && ! $nouveau) {
                throw new LaboratoireRefuse('Votre code de retrait vous a déjà été donné. Perdu ? Demandez un nouveau code : l\'ancien ne marchera plus.');
            }

            $chiffres = (int) config('doc-and-i.laboratoire.code_retrait_chiffres');
            $code = str_pad((string) random_int(0, 10 ** $chiffres - 1), $chiffres, '0', STR_PAD_LEFT);
            $b->code_retrait_empreinte = self::empreinteCode($b, $code);
            $b->code_retrait_remis_le = CarbonImmutable::now();
            $b->save();

            return $code;
        });
    }

    /** Empreinte du code, propre au bon : un même code sur deux bons n'a pas la même empreinte. */
    public static function empreinteCode(BonExamen $bon, string $code): string
    {
        return hash_hmac('sha256', $bon->id.'|'.preg_replace('/\D/', '', $code), (string) config('app.key'));
    }

    public static function presenterResultat(ResultatExamen $r): array
    {
        return [
            'id' => $r->id,
            'valeurs' => array_map(fn (array $v) => [
                'analyte' => $v['analyte'] ?? '',
                'valeur' => $v['valeur'] ?? '',
                'unite' => $v['unite'] ?? null,
                'normes' => $v['normes'] ?? null,
                'anormal' => (bool) ($v['anormal'] ?? false),
            ], $r->valeurs ?? []),
            'pdf' => $r->pdf_chemin !== null,
            'critique' => $r->critique,
            'deposeLe' => Affichage::dateHeure($r->depose_le),
            'valideLe' => $r->valide_le ? Affichage::dateHeure($r->valide_le) : null,
            'commentaire' => $r->commentaire_medecin,
        ];
    }
}
