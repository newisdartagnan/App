<?php

namespace App\Services\Dossier;

use App\Models\Antecedent;
use App\Models\InteractionMedicamenteuse;
use App\Models\RendezVous;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Alertes à la prescription (PRE-02), calculées sur le serveur à l'émission.
 *
 * - Allergie connue : un antécédent ACTIF (non retiré) de catégorie
 *   « allergie » du patient qui partage un mot avec le médicament, ou dont un
 *   mot contient un mot de l'autre (comparaison par jetons, voir jetons()).
 *   Un faux positif, que le médecin maintient, vaut mieux qu'une allergie
 *   ignorée : « Allergie à la pénicilline », « Pénicilline (urticaire) » et
 *   « pénicillines » alertent toutes face à « Pénicilline V ».
 * - Interaction majeure : deux DCI de la table interactions_medicamenteuses
 *   retrouvées, l'une dans une ligne, l'autre dans une autre ligne de la même
 *   ordonnance ou dans un traitement en cours déclaré (antécédent actif de
 *   catégorie « traitement »).
 *
 * Chaque alerte porte une clé de maintien émise par le serveur : un HMAC du
 * rendez-vous, de la ligne, du médicament normalisé et de l'antécédent ou de
 * l'interaction, avec une clé dérivée de DOSSIER_SECRET_SIGNATURE. Seule
 * cette clé vaut maintien : impossible de la fabriquer d'avance, et elle ne
 * vaut plus si le médicament de la ligne change.
 *
 * Aucune donnée médicale n'est inventée : pas de familles d'allergènes ni de
 * correspondances DCI ↔ nom commercial ; la table des interactions est livrée
 * vide, à remplir par le pharmacien du client. Une alerte est bloquante :
 * l'ordonnance ne s'émet que si le médecin la maintient explicitement.
 */
final class AlertesPrescription
{
    /** En dessous, un jeton ne veut plus rien dire (une lettre, un sigle de deux lettres, « mg »). */
    private const LONGUEUR_MIN = 3;

    /** Mots qui ne désignent pas une substance. */
    private const MOTS_VIDES = [
        'allergie', 'allergique', 'allergies', 'intolerance', 'reaction',
        'a', 'au', 'aux', 'la', 'le', 'les', 'l', 'de', 'du', 'des', 'd', 'et', 'ou', 'en', 'un', 'une',
        'avec', 'sans', 'par', 'pour', 'sur', 'chez', 'contre',
    ];

    /**
     * @param  list<array{medicament: string}>  $lignes  Dans l'ordre (rang = index + 1).
     * @return list<array{cle: string, type: string, rang: int, message: string}>
     */
    public function pour(RendezVous $rdv, array $lignes): array
    {
        $actifs = Antecedent::where('patient_id', $rdv->patient_id)->whereNull('retire_le')
            ->whereIn('categorie', ['allergie', 'traitement'])->orderBy('created_at')->orderBy('id')->get();
        $allergies = $actifs->where('categorie', 'allergie');
        $traitements = $actifs->where('categorie', 'traitement');

        $alertes = [];
        foreach ($lignes as $i => $ligne) {
            $rang = $i + 1;
            foreach ($allergies as $allergie) {
                if (self::serecouvrent($ligne['medicament'], $allergie->libelle)) {
                    $alertes[] = [
                        'cle' => self::cle($rdv, $rang, $ligne['medicament'], 'allergie:'.$allergie->id),
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
                            'cle' => self::cle($rdv, $rang, $ligne['medicament'], 'interaction:ligne:'.($j + 1).':'.self::normaliser($autre['medicament']).':'.$interaction->id),
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
                            'cle' => self::cle($rdv, $rang, $ligne['medicament'], 'interaction:traitement:'.$traitement->id.':'.$interaction->id),
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

    /**
     * Jetons significatifs : normalisés, sans mots vides, au singulier (« -s »
     * et « -x » finaux retirés), de 3 caractères ou plus, sans les nombres
     * (un dosage n'est pas une substance).
     *
     * @return list<string>
     */
    public static function jetons(string $texte): array
    {
        $jetons = [];
        foreach (explode(' ', self::normaliser($texte)) as $mot) {
            if ($mot === '' || in_array($mot, self::MOTS_VIDES, true) || ctype_digit($mot)) {
                continue;
            }
            if (strlen($mot) > self::LONGUEUR_MIN && in_array(substr($mot, -1), ['s', 'x'], true)) {
                $mot = substr($mot, 0, -1);
            }
            if (strlen($mot) >= self::LONGUEUR_MIN && ! in_array($mot, self::MOTS_VIDES, true)) {
                $jetons[] = $mot;
            }
        }

        return array_values(array_unique($jetons));
    }

    /** Un jeton commun, ou un jeton de l'un qui contient un jeton de l'autre. */
    public static function serecouvrent(string $a, string $b): bool
    {
        foreach (self::jetons($a) as $x) {
            foreach (self::jetons($b) as $y) {
                if (str_contains($x, $y) || str_contains($y, $x)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** Clé de maintien : HMAC (rendez-vous, rang, médicament normalisé, objet de l'alerte). */
    public static function cle(RendezVous $rdv, int $rang, string $medicament, string $objet): string
    {
        $secret = (string) config('doc-and-i.dossier.secret_signature');
        if (strlen($secret) < 32) {
            throw new RuntimeException('DOSSIER_SECRET_SIGNATURE absent ou trop court : aucune alerte ne peut être maintenue.');
        }
        // Clé dérivée : le sceau des documents et les clés de maintien ne partagent pas la même clé.
        $derivee = hash_hmac('sha256', 'doc-and-i/alertes-prescription/v1', $secret);

        return hash_hmac('sha256', implode("\n", [$rdv->id, $rang, self::normaliser($medicament), $objet]), $derivee);
    }

    private static function interagissent(string $premier, string $second, InteractionMedicamenteuse $interaction): bool
    {
        return (self::mentionne($premier, $interaction->dci_a) && self::mentionne($second, $interaction->dci_b))
            || (self::mentionne($premier, $interaction->dci_b) && self::mentionne($second, $interaction->dci_a));
    }

    /** Le médicament prescrit mentionne-t-il cette DCI ? (inclusion après normalisation) */
    private static function mentionne(string $medicament, string $dci): bool
    {
        $dci = self::normaliser($dci);

        return strlen($dci) >= self::LONGUEUR_MIN && str_contains(self::normaliser($medicament), $dci);
    }
}
