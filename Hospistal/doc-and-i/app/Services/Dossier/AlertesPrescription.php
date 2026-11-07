<?php

namespace App\Services\Dossier;

use App\Models\Antecedent;
use App\Models\InteractionMedicamenteuse;
use Illuminate\Support\Str;

/**
 * Alertes à la prescription (PRE-02), calculées sur le serveur à l'émission.
 *
 * - Allergie connue : un antécédent ACTIF (non retiré) de catégorie
 *   « allergie » du patient dont le libellé et le médicament se recouvrent
 *   (l'un contient l'autre), après normalisation : sans accents, sans casse,
 *   ponctuation réduite à des espaces.
 * - Interaction majeure : deux DCI de la table interactions_medicamenteuses
 *   retrouvées, l'une dans une ligne, l'autre dans une autre ligne de la même
 *   ordonnance ou dans un traitement en cours déclaré (antécédent actif de
 *   catégorie « traitement »).
 *
 * Aucune donnée médicale n'est inventée : pas de familles d'allergènes ni de
 * correspondances DCI ↔ nom commercial ; la table des interactions est livrée
 * vide, à remplir par le pharmacien du client. Une alerte est bloquante :
 * l'ordonnance ne s'émet que si le médecin la maintient explicitement.
 */
final class AlertesPrescription
{
    /** En dessous, une inclusion ne veut plus rien dire (une lettre, un sigle de deux lettres). */
    private const LONGUEUR_MIN = 3;

    /**
     * @param  list<array{medicament: string}>  $lignes  Dans l'ordre (rang = index + 1).
     * @return list<array{cle: string, type: string, rang: int, message: string}>
     */
    public function pour(string $patientId, array $lignes): array
    {
        $actifs = Antecedent::where('patient_id', $patientId)->whereNull('retire_le')
            ->whereIn('categorie', ['allergie', 'traitement'])->orderBy('created_at')->orderBy('id')->get();
        $allergies = $actifs->where('categorie', 'allergie');
        $traitements = $actifs->where('categorie', 'traitement');

        $alertes = [];
        foreach ($lignes as $i => $ligne) {
            $rang = $i + 1;
            foreach ($allergies as $allergie) {
                if (self::serecouvrent($ligne['medicament'], $allergie->libelle)) {
                    $alertes[] = [
                        'cle' => "allergie:{$rang}:{$allergie->id}",
                        'type' => 'allergie',
                        'rang' => $rang,
                        'message' => sprintf('Allergie connue du patient : « %s ». Ligne %d : %s.', $allergie->libelle, $rang, $ligne['medicament']),
                    ];
                }
            }
        }

        $interactions = InteractionMedicamenteuse::orderBy('dci_a')->orderBy('dci_b')->get();
        if ($interactions->isEmpty()) {
            return $alertes;
        }

        foreach ($lignes as $i => $ligne) {
            $rang = $i + 1;
            // Avec les lignes suivantes de la même ordonnance…
            foreach (array_slice($lignes, $i + 1, null, true) as $j => $autre) {
                foreach ($interactions as $interaction) {
                    if (self::interagissent($ligne['medicament'], $autre['medicament'], $interaction)) {
                        $alertes[] = [
                            'cle' => "interaction:{$rang}:ligne".($j + 1).":{$interaction->id}",
                            'type' => 'interaction',
                            'rang' => $rang,
                            'message' => sprintf('Interaction majeure entre la ligne %d (%s) et la ligne %d (%s) : %s', $rang, $ligne['medicament'], $j + 1, $autre['medicament'], $interaction->message),
                        ];
                    }
                }
            }
            // … et avec les traitements en cours déclarés.
            foreach ($traitements as $traitement) {
                foreach ($interactions as $interaction) {
                    if (self::interagissent($ligne['medicament'], $traitement->libelle, $interaction)) {
                        $alertes[] = [
                            'cle' => "interaction:{$rang}:traitement:{$traitement->id}:{$interaction->id}",
                            'type' => 'interaction',
                            'rang' => $rang,
                            'message' => sprintf('Interaction majeure entre la ligne %d (%s) et le traitement en cours « %s » : %s', $rang, $ligne['medicament'], $traitement->libelle, $interaction->message),
                        ];
                    }
                }
            }
        }

        return $alertes;
    }

    /** Minuscules, sans accents, lettres et chiffres séparés par une seule espace. */
    public static function normaliser(string $texte): string
    {
        $ascii = Str::lower(Str::ascii($texte));

        return trim(preg_replace('/[^a-z0-9]+/', ' ', $ascii));
    }

    /** L'un contient l'autre, une fois normalisés (et assez longs pour que ça veuille dire quelque chose). */
    public static function serecouvrent(string $a, string $b): bool
    {
        $a = self::normaliser($a);
        $b = self::normaliser($b);
        if (strlen($a) < self::LONGUEUR_MIN || strlen($b) < self::LONGUEUR_MIN) {
            return false;
        }

        return self::contient($a, $b) || self::contient($b, $a);
    }

    private static function interagissent(string $premier, string $second, InteractionMedicamenteuse $interaction): bool
    {
        return (self::mentionne($premier, $interaction->dci_a) && self::mentionne($second, $interaction->dci_b))
            || (self::mentionne($premier, $interaction->dci_b) && self::mentionne($second, $interaction->dci_a));
    }

    /** Le médicament prescrit mentionne-t-il cette DCI ? */
    private static function mentionne(string $medicament, string $dci): bool
    {
        $dci = self::normaliser($dci);

        return strlen($dci) >= self::LONGUEUR_MIN && self::contient(self::normaliser($medicament), $dci);
    }

    /**
     * Inclusion simple, pas sur des mots entiers : « penicilline » est dans
     * « benzylpenicilline ». Pour une allergie, une fausse alerte (que le
     * médecin maintient) vaut mieux qu'une allergie manquée.
     */
    private static function contient(string $botte, string $aiguille): bool
    {
        return str_contains($botte, $aiguille);
    }
}
