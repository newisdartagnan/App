<?php

namespace App\Services\Laboratoire;

use App\Models\AlerteMedecin;
use App\Models\BonExamen;
use App\Models\ResultatExamen;
use App\Models\User;
use App\Services\Dossier\AccesDossier;
use App\Services\RendezVous\Affichage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Portail d'alertes (PRE-08) et escalades (RG-10).
 *
 * - Le médecin voit SES alertes ouvertes, les marque « lue » ou « traitée ».
 * - Tâche planifiée : un résultat critique non traité sous 2 h est escaladé
 *   au coordinateur (escaladee_le sur l'alerte, alerte de coordination) ;
 *   des résultats à valider depuis plus de 48 h aussi, et le coordinateur
 *   peut alors les valider à la place du médecin (ValidationResultats).
 * - Le coordinateur voit les escalades ouvertes et les marque traitées.
 * Aucun message ne porte de donnée de santé.
 */
final class AlertesSoignants
{
    public function __construct(private readonly AccesDossier $acces) {}

    /** @throws LaboratoireRefuse */
    public function duMedecin(User $user): array
    {
        $medecin = $this->acces->medecinDe($user) ?? throw new LaboratoireRefuse('Espace réservé aux médecins.');

        return AlerteMedecin::with('patient:id,prenom,nom')->where('medecin_id', $medecin->id)->whereNull('traitee_le')
            ->orderByRaw("CASE gravite WHEN 'critique' THEN 0 WHEN 'urgent' THEN 1 ELSE 2 END")->orderBy('created_at')
            ->limit(200)->get()
            ->map(fn (AlerteMedecin $a) => [
                'id' => $a->id, 'type' => $a->type, 'gravite' => $a->gravite, 'message' => $a->message,
                'patient' => trim($a->patient->prenom.' '.mb_strtoupper((string) $a->patient->nom)),
                'creeeLe' => Affichage::dateHeure($a->created_at),
                'lue' => $a->lue_le !== null,
                'escaladee' => $a->escaladee_le !== null,
                'bonId' => $this->bonDe($a),
            ])->all();
    }

    /** @param  'lue'|'traitee'  $etat  @throws LaboratoireRefuse */
    public function marquer(User $user, string $alerteId, string $etat): void
    {
        $medecin = $this->acces->medecinDe($user) ?? throw new LaboratoireRefuse('Espace réservé aux médecins.');

        DB::transaction(function () use ($medecin, $alerteId, $etat) {
            $alerte = (Str::isUuid($alerteId) ? AlerteMedecin::whereKey($alerteId)->where('medecin_id', $medecin->id)->lockForUpdate()->first() : null)
                ?? throw new LaboratoireRefuse('Alerte introuvable.');
            $maintenant = CarbonImmutable::now();
            $alerte->lue_le ??= $maintenant;
            if ($etat === 'traitee') {
                $alerte->traitee_le ??= $maintenant;
            }
            $alerte->save();
        });
    }

    /** Escalades ouvertes, pour le coordinateur. */
    public function escaladesOuvertes(): array
    {
        return DB::table('alertes_coordination as a')
            ->join('bons_examen as b', 'b.id', '=', 'a.bon_id')
            ->join('users as p', 'p.id', '=', 'a.patient_id')
            ->join('medecins as m', 'm.id', '=', 'a.medecin_id')
            ->join('users as mu', 'mu.id', '=', 'm.user_id')
            ->whereNull('a.traitee_le')
            ->orderByRaw("CASE a.type WHEN 'critique_non_traite' THEN 0 ELSE 1 END")->orderBy('a.created_at')
            ->limit(200)
            ->get(['a.id', 'a.type', 'a.message', 'a.created_at', 'a.bon_id', 'b.numero', 'b.statut', 'p.prenom as patient_prenom', 'p.nom as patient_nom', 'mu.prenom as medecin_prenom', 'mu.nom as medecin_nom', 'mu.telephone as medecin_telephone'])
            ->map(fn ($a) => [
                'id' => $a->id, 'type' => $a->type, 'message' => $a->message,
                'creeeLe' => Affichage::dateHeure(CarbonImmutable::parse($a->created_at)),
                'bonId' => $a->bon_id, 'numero' => $a->numero, 'statut' => $a->statut,
                'patient' => trim($a->patient_prenom.' '.mb_strtoupper((string) $a->patient_nom)),
                'medecin' => trim('Dr '.$a->medecin_prenom.' '.mb_strtoupper((string) $a->medecin_nom)),
                // Pour joindre le médecin au sujet d'un résultat critique.
                'medecinTelephone' => $a->medecin_telephone,
            ])->all();
    }

    /** @throws LaboratoireRefuse */
    public function traiterEscalade(User $coordinateur, string $id): void
    {
        $fait = Str::isUuid($id) && DB::table('alertes_coordination')->where('id', $id)->whereNull('traitee_le')
            ->update(['traitee_le' => CarbonImmutable::now(), 'traitee_par' => $coordinateur->id, 'updated_at' => CarbonImmutable::now()]);
        if (! $fait && ! (Str::isUuid($id) && DB::table('alertes_coordination')->where('id', $id)->exists())) {
            throw new LaboratoireRefuse('Escalade introuvable.');
        }
    }

    /**
     * Tâche planifiée : escalades à 2 h (critique non traité) et à 48 h
     * (résultats à valider). Relancée, elle ne double rien.
     *
     * @return array{critiques: int, validations: int}
     */
    public function escalader(): array
    {
        $maintenant = CarbonImmutable::now();
        $compte = ['critiques' => 0, 'validations' => 0];

        $limiteCritique = $maintenant->subHours((int) config('doc-and-i.laboratoire.escalade_critique_heures'));
        $critiques = AlerteMedecin::where('type', 'resultat_critique')->whereNull('traitee_le')->whereNull('escaladee_le')
            ->where('created_at', '<=', $limiteCritique)->pluck('id');
        foreach ($critiques as $id) {
            $compte['critiques'] += DB::transaction(function () use ($id, $maintenant) {
                $alerte = AlerteMedecin::whereKey($id)->lockForUpdate()->first();
                if (! $alerte || $alerte->traitee_le !== null || $alerte->escaladee_le !== null) {
                    return 0;
                }
                $alerte->escaladee_le = $maintenant;
                $alerte->save();
                $bon = ResultatExamen::find($alerte->objet_id)?->bon;
                if ($bon !== null) {
                    $this->ouvrirEscalade('critique_non_traite', $bon, $alerte->id, 'Résultat critique non traité par le médecin depuis '.config('doc-and-i.laboratoire.escalade_critique_heures').' h (bon '.$bon->numero.').');
                }

                return 1;
            });
        }

        $limiteValidation = $maintenant->subHours((int) config('doc-and-i.laboratoire.escalade_validation_heures'));
        $enRetard = ResultatExamen::whereNull('valide_le')->where('depose_le', '<=', $limiteValidation)->distinct()->pluck('bon_id');
        foreach ($enRetard as $bonId) {
            $compte['validations'] += DB::transaction(function () use ($bonId) {
                $bon = BonExamen::whereKey($bonId)->lockForUpdate()->first();
                if (! $bon || DB::table('alertes_coordination')->where('type', 'validation_en_retard')->where('bon_id', $bon->id)->whereNull('traitee_le')->exists()) {
                    return 0;
                }
                $aValider = AlerteMedecin::where('objet_type', 'bon_examen')->where('objet_id', $bon->id)->where('type', 'resultat_a_valider')
                    ->whereNull('traitee_le')->first();
                $aValider?->forceFill(['escaladee_le' => $aValider->escaladee_le ?? CarbonImmutable::now()])->save();
                $this->ouvrirEscalade('validation_en_retard', $bon, $aValider?->id, 'Résultats à valider depuis plus de '.config('doc-and-i.laboratoire.escalade_validation_heures').' h (bon '.$bon->numero.') : le coordinateur peut les valider (RG-10).');

                return 1;
            });
        }

        return $compte;
    }

    private function ouvrirEscalade(string $type, BonExamen $bon, ?string $alerteId, string $message): void
    {
        DB::table('alertes_coordination')->insertOrIgnore([
            'id' => (string) Str::uuid(), 'type' => $type, 'bon_id' => $bon->id, 'alerte_medecin_id' => $alerteId,
            'patient_id' => $bon->patient_id, 'medecin_id' => $bon->medecin_id, 'message' => $message,
            'created_at' => CarbonImmutable::now(), 'updated_at' => CarbonImmutable::now(),
        ]);
    }

    private function bonDe(AlerteMedecin $a): ?string
    {
        return match ($a->objet_type) {
            'bon_examen' => $a->objet_id,
            'resultat_examen' => ResultatExamen::whereKey($a->objet_id)->value('bon_id'),
            default => null,
        };
    }
}
