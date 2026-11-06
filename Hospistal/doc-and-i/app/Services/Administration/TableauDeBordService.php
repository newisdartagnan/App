<?php

namespace App\Services\Administration;

use App\Enums\StatutRendezVous;
use App\Support\Montant;
use Illuminate\Support\Facades\DB;

/**
 * Tableau de bord de l'administration (ADM-02). Requêtes SQL agrégées
 * seulement : aucun nom de patient, aucun motif ni symptôme ne sort d'ici.
 *
 * Définitions (période sur la date du rendez-vous, heure de Kinshasa) :
 * - rendez-vous payés : confirmés, terminés, absences, annulés par le
 *   médecin, et annulés par le patient APRÈS paiement (taux de
 *   remboursement posé ; une attente annulée avant paiement n'en a pas) ;
 * - taux d'annulation et d'absence : rapportés aux rendez-vous payés ;
 * - délai d'attente : de la demande au début du rendez-vous, sur les
 *   rendez-vous payés et non annulés ;
 * - chiffre d'affaires : journal de caisse (jour comptable), encaissements
 *   moins remboursements, par devise, jamais additionné entre devises ;
 * - consultations d'un médecin : rendez-vous confirmés ou terminés.
 */
final class TableauDeBordService
{
    private const PAYES_SQL = "(statut IN ('confirme', 'termine', 'absent_patient', 'absent_medecin', 'annule_medecin') OR (statut = 'annule_patient' AND taux_remboursement IS NOT NULL))";

    private const TENUS = ['confirme', 'termine', 'absent_patient', 'absent_medecin'];

    public const TOP_MEDECINS = 5;

    public function calculer(PeriodeTableau $periode): array
    {
        return [
            'periode' => ['cle' => $periode->cle, 'libelle' => $periode->libelle()],
            'parStatut' => $this->parStatut($periode),
            'taux' => $this->taux($periode),
            'delaiAttente' => $this->delaiAttente($periode),
            'chiffreAffaires' => $this->chiffreAffaires($periode),
            'topMedecins' => $this->topMedecins($periode),
            'satisfaction' => 'Disponible avec les avis des patients (ADM-03, V2).',
        ];
    }

    /** @return list<array{statut: string, libelle: string, nombre: int}> */
    private function parStatut(PeriodeTableau $periode): array
    {
        $nombres = DB::table('rendez_vous')
            ->where('debut', '>=', $periode->debut)->where('debut', '<', $periode->fin)
            ->groupBy('statut')
            ->selectRaw('statut, count(*) AS nombre')
            ->pluck('nombre', 'statut');

        return array_map(fn (StatutRendezVous $s) => [
            'statut' => $s->value,
            'libelle' => $s->libelle(),
            'nombre' => (int) ($nombres[$s->value] ?? 0),
        ], StatutRendezVous::cases());
    }

    private function taux(PeriodeTableau $periode): array
    {
        $l = DB::table('rendez_vous')
            ->where('debut', '>=', $periode->debut)->where('debut', '<', $periode->fin)
            ->selectRaw('count(*) FILTER (WHERE '.self::PAYES_SQL.') AS payes')
            ->selectRaw("count(*) FILTER (WHERE statut = 'annule_patient' AND taux_remboursement IS NOT NULL) AS annules_patient")
            ->selectRaw("count(*) FILTER (WHERE statut = 'annule_medecin') AS annules_medecin")
            ->selectRaw("count(*) FILTER (WHERE statut = 'absent_patient') AS absences_patient")
            ->selectRaw("count(*) FILTER (WHERE statut = 'absent_medecin') AS absences_medecin")
            ->first();

        $payes = (int) $l->payes;
        $indicateur = fn (int $n) => ['nombre' => $n, 'pourcentage' => self::pourcentage($n, $payes)];

        return [
            'payes' => $payes,
            'annulationPatient' => $indicateur((int) $l->annules_patient),
            'annulationMedecin' => $indicateur((int) $l->annules_medecin),
            'absencePatient' => $indicateur((int) $l->absences_patient),
            'absenceMedecin' => $indicateur((int) $l->absences_medecin),
        ];
    }

    private function delaiAttente(PeriodeTableau $periode): array
    {
        $l = DB::table('rendez_vous AS r')
            ->join('demandes_consultation AS d', 'd.id', '=', 'r.demande_id')
            ->where('r.debut', '>=', $periode->debut)->where('r.debut', '<', $periode->fin)
            ->whereIn('r.statut', self::TENUS)
            ->selectRaw('count(*) AS nombre, round(avg(extract(epoch FROM (r.debut - d.created_at))))::bigint AS secondes')
            ->first();

        $secondes = $l->secondes === null ? null : (int) $l->secondes;

        return [
            'nombre' => (int) $l->nombre,
            'minutes' => $secondes === null ? null : intdiv($secondes, 60),
            'libelle' => $secondes === null ? null : self::duree($secondes),
        ];
    }

    /** @return list<array{devise: string, encaisse: string, rembourse: string, net: string}> */
    private function chiffreAffaires(PeriodeTableau $periode): array
    {
        return DB::table('operations_caisse')
            ->where('jour', '>=', $periode->debut->toDateString())
            ->where('jour', '<', $periode->fin->toDateString())
            ->groupBy('devise')->orderBy('devise')
            ->selectRaw('devise')
            ->selectRaw("coalesce(sum(montant) FILTER (WHERE type = 'encaissement'), 0) AS encaisse")
            ->selectRaw("coalesce(-sum(montant) FILTER (WHERE type = 'remboursement'), 0) AS rembourse")
            ->selectRaw('sum(montant) AS net')
            ->get()
            ->map(fn ($l) => [
                'devise' => $l->devise,
                'encaisse' => Montant::afficher(self::decimal($l->encaisse), $l->devise),
                'rembourse' => Montant::afficher(self::decimal($l->rembourse), $l->devise),
                'net' => Montant::afficher(self::decimal($l->net), $l->devise),
            ])
            ->all();
    }

    /** @return list<array{medecin: string, consultations: int, annulations: int}> */
    private function topMedecins(PeriodeTableau $periode): array
    {
        return DB::table('rendez_vous AS r')
            ->join('medecins AS m', 'm.id', '=', 'r.medecin_id')
            ->join('users AS u', 'u.id', '=', 'm.user_id')
            ->where('r.debut', '>=', $periode->debut)->where('r.debut', '<', $periode->fin)
            ->groupBy('m.id', 'u.prenom', 'u.nom')
            ->selectRaw("u.prenom, u.nom, count(*) FILTER (WHERE r.statut IN ('confirme', 'termine')) AS consultations")
            ->selectRaw("count(*) FILTER (WHERE r.statut = 'annule_medecin') AS annulations")
            ->havingRaw("count(*) FILTER (WHERE r.statut IN ('confirme', 'termine', 'annule_medecin')) > 0")
            ->orderByDesc('consultations')->orderByDesc('annulations')->orderBy('u.nom')->orderBy('m.id')
            ->limit(self::TOP_MEDECINS)
            ->get()
            ->map(fn ($l) => [
                'medecin' => trim('Dr '.$l->prenom.' '.mb_strtoupper((string) $l->nom)),
                'consultations' => (int) $l->consultations,
                'annulations' => (int) $l->annulations,
            ])
            ->all();
    }

    /** Pourcentage à une décimale, calculé en entiers : « 12,5 % ». */
    public static function pourcentage(int $nombre, int $total): ?string
    {
        if ($total === 0) {
            return null;
        }

        $pourMille = intdiv($nombre * 1000 * 2 + $total, $total * 2);

        return intdiv($pourMille, 10).','.($pourMille % 10).' %';
    }

    public static function duree(int $secondes): string
    {
        $minutes = intdiv($secondes, 60);
        [$jours, $heures, $reste] = [intdiv($minutes, 1440), intdiv($minutes % 1440, 60), $minutes % 60];

        return match (true) {
            $jours > 0 => "{$jours} j {$heures} h",
            $heures > 0 => "{$heures} h {$reste} min",
            default => "{$reste} min",
        };
    }

    /** Somme PostgreSQL (« 25.00 », « -5 ») ramenée à deux décimales pour Montant. */
    private static function decimal(string|int|float $valeur): string
    {
        $texte = (string) $valeur;

        return preg_match('/^-?\d+$/', $texte) ? $texte.'.00' : $texte;
    }
}
