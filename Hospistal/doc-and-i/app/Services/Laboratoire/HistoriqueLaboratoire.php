<?php

namespace App\Services\Laboratoire;

use App\Enums\StatutBonExamen;
use App\Models\BonExamen;
use App\Models\ResultatExamen;
use App\Models\User;
use App\Services\RendezVous\Affichage;

/**
 * Entrées de l'historique du dossier (LectureDossier) pour le laboratoire :
 * les bons d'examen prescrits et les résultats VALIDÉS (jamais un résultat
 * en attente de validation, PRE-07). Même forme que les autres entrées.
 */
final class HistoriqueLaboratoire
{
    /** @return list<array{type: string, instant: mixed, id: string, titre: string, details: list<string>}> */
    public static function evenements(User $patient, callable $nomMedecin): array
    {
        $evenements = [];

        BonExamen::with('lignes')->where('patient_id', $patient->id)->where('statut', '!=', StatutBonExamen::Annule->value)->get()
            ->each(function (BonExamen $b) use (&$evenements, $nomMedecin) {
                $evenements[] = [
                    'type' => 'bon_examen', 'instant' => $b->created_at, 'id' => $b->id,
                    'titre' => 'Bon d\'examens '.$b->numero.' — '.$nomMedecin($b->medecin_id),
                    'details' => [...$b->lignes->pluck('libelle')->all(), 'Statut : '.$b->statut->libelle()],
                ];
            });

        ResultatExamen::with('bon:id,numero,medecin_id')->whereNotNull('valide_le')
            ->whereHas('bon', fn ($q) => $q->where('patient_id', $patient->id))->get()
            ->each(function (ResultatExamen $r) use (&$evenements, $nomMedecin) {
                $details = array_map(fn (array $v) => trim(($v['analyte'] ?? '').' : '.($v['valeur'] ?? '').' '.($v['unite'] ?? ''))
                    .(! empty($v['normes']) ? ' (normes : '.$v['normes'].')' : '')
                    .(! empty($v['anormal']) ? ' — anormal' : ''), $r->valeurs ?? []);
                if ($r->pdf_chemin !== null) {
                    $details[] = 'Compte rendu PDF du laboratoire';
                }
                if ($r->commentaire_medecin) {
                    $details[] = 'Commentaire du médecin : '.$r->commentaire_medecin;
                }
                $evenements[] = [
                    'type' => 'resultat_examen', 'instant' => $r->valide_le, 'id' => $r->id,
                    'titre' => 'Résultats d\'examens (bon '.$r->bon->numero.') validés — '.$nomMedecin($r->bon->medecin_id),
                    'details' => $details,
                ];
            });

        return $evenements;
    }

    public static function dateAffichee(mixed $instant): string
    {
        return Affichage::dateHeure($instant);
    }
}
