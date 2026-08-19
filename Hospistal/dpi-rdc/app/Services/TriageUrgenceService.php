<?php

namespace App\Services;

use App\Models\TriageUrgence;
use App\Models\Visit;

/**
 * Échelle de triage d'urgence à 5 niveaux.
 *
 * Le niveau est CALCULÉ à partir des critères cliniques cochés — le soignant
 * ne le choisit pas. Chaque critère porte le niveau qu'il impose ; le niveau
 * retenu est le plus grave de tous (1 = réanimation, 5 = non urgent), et les
 * critères qui l'ont déterminé sont conservés pour la traçabilité.
 */
class TriageUrgenceService
{
    /**
     * Grille des critères, par bloc clinique.
     * Chaque critère : clé => [libellé, niveau imposé].
     *
     * @return array<string, array{titre: string, type: string, criteres: array<string, array{0: string, 1: int}>}>
     */
    public function grille(): array
    {
        return [
            'alerte_vitale' => [
                'titre' => 'Symptômes et signes d\'alerte vitale',
                'type' => 'multiple',
                'criteres' => [
                    'arret_cardiorespiratoire' => ['Arrêt cardiorespiratoire', 1],
                    'polytraumatise' => ['Polytraumatisé', 1],
                    'glasgow_inf_8' => ['Glasgow < 8', 1],
                    'patient_inconscient' => ['Patient inconscient', 1],
                    'apnee' => ['Apnée', 1],
                    'reaction_anaphylactique' => ['Réaction anaphylactique', 1],
                    'hemorragie' => ['Hémorragie', 2],
                    'convulsion' => ['Convulsion', 2],
                    'fc_sup_180' => ['FC > 180/min', 2],
                ],
            ],
            'circulation' => [
                'titre' => 'Circulation',
                'type' => 'unique',
                'criteres' => [
                    'fc_normale' => ['FC 60–100/min', 5],
                    'fc_100_140' => ['FC 100–140/min', 4],
                    'fc_140_180' => ['FC 140–180/min', 3],
                    'fc_extreme' => ['FC > 180/min ou < 40/min', 2],
                ],
            ],
            'pouls' => [
                'titre' => 'Caractéristique du pouls',
                'type' => 'unique',
                'criteres' => [
                    'pouls_rythmique' => ['Rythmique', 5],
                    'pouls_arythmique' => ['Arythmique', 3],
                ],
            ],
            'temperature' => [
                'titre' => 'Température',
                'type' => 'unique',
                'criteres' => [
                    'afebrile' => ['Afébrile', 5],
                    'febrile' => ['Fébrile > 38 °C', 4],
                    'hyperthermie' => ['Hyperthermie > 40 °C', 3],
                ],
            ],
            'neurologique' => [
                'titre' => 'État neurologique (Glasgow)',
                'type' => 'unique',
                'criteres' => [
                    'glasgow_15' => ['15 points', 5],
                    'glasgow_14' => ['14 points', 4],
                    'glasgow_10_13' => ['10 à 13 points', 2],
                    'glasgow_inf_10' => ['Moins de 10 points', 1],
                ],
            ],
            'etat_general' => [
                'titre' => 'État général',
                'type' => 'unique',
                'criteres' => [
                    'bon_etat' => ['Bon état général', 5],
                    'assez_bon_etat' => ['Assez bon état général', 4],
                    'mauvais_etat' => ['Mauvais état général', 2],
                ],
            ],
            'peau' => [
                'titre' => 'État de la peau',
                'type' => 'multiple',
                'criteres' => [
                    'bien_coloree' => ['Bien colorée', 5],
                    'ictere' => ['Ictère', 4],
                    'paleur' => ['Pâleur', 3],
                    'transpiration' => ['Transpiration profuse', 3],
                    'cyanose' => ['Cyanose', 1],
                ],
            ],
            'douleur' => [
                'titre' => 'Intensité de la douleur',
                'type' => 'unique',
                'criteres' => [
                    'douleur_absente' => ['Absente ou légère', 5],
                    'douleur_moderee' => ['Modérée', 4],
                    'douleur_intense' => ['Intense', 3],
                ],
            ],
            'evolution' => [
                'titre' => 'Temps d\'évolution de la maladie',
                'type' => 'unique',
                'criteres' => [
                    'evolution_sup_6h' => ['6 heures ou plus', 5],
                    'evolution_inf_6h' => ['Moins de 6 heures', 4],
                ],
            ],
            'arrivee' => [
                'titre' => 'Mode d\'arrivée',
                'type' => 'unique',
                'criteres' => [
                    'arrive_marchant' => ['En marchant', 5],
                    'arrive_transporte' => ['Transporté par une autre personne', 3],
                    'arrive_ambulance' => ['Ambulance / brancard', 2],
                ],
            ],
            'respiration' => [
                'titre' => 'Respiration',
                'type' => 'unique',
                'criteres' => [
                    'fr_normale' => ['FR normale, respiration libre', 5],
                    'fr_elevee' => ['FR élevée sans tirage', 4],
                    'tirage_intercostal' => ['Tirage intercostal', 2],
                    'stridor' => ['Stridor', 1],
                ],
            ],
            'pathologie' => [
                'titre' => 'Pathologie motivant la venue',
                'type' => 'multiple',
                'criteres' => [
                    'dyspnee' => ['Dyspnée', 2],
                    'cephalee' => ['Céphalée', 4],
                    'agitation' => ['Agitation', 3],
                    'reaction_allergique' => ['Réaction allergique', 2],
                    'amputation' => ['Amputation / traumatisme grave', 1],
                    'douleur_abdominale' => ['Douleur abdominale', 3],
                    'douleur_thoracique' => ['Douleur thoracique', 2],
                    'paresie_dysarthrie' => ['Parésie / dysarthrie', 2],
                    'intoxication' => ['Intoxication médicamenteuse', 2],
                    'perte_connaissance' => ['Perte de connaissance', 1],
                ],
            ],
            'saignement' => [
                'titre' => 'Saignement actif',
                'type' => 'unique',
                'criteres' => [
                    'saignement_non' => ['Non', 5],
                    'saignement_leger' => ['Léger', 4],
                    'saignement_abondant' => ['Abondant', 1],
                ],
            ],
        ];
    }

    /**
     * Niveau imposé par chaque critère coché, tous blocs confondus.
     *
     * @return array<string, int>
     */
    protected function niveauxParCritere(): array
    {
        $niveaux = [];
        foreach ($this->grille() as $bloc) {
            foreach ($bloc['criteres'] as $cle => [$libelle, $niveau]) {
                $niveaux[$cle] = $niveau;
            }
        }

        return $niveaux;
    }

    /**
     * Libellé lisible d'un critère.
     */
    public function libelleCritere(string $cle): string
    {
        foreach ($this->grille() as $bloc) {
            if (isset($bloc['criteres'][$cle])) {
                return $bloc['criteres'][$cle][0];
            }
        }

        return $cle;
    }

    /**
     * Calcule le niveau d'urgence : le plus grave des critères cochés.
     * En l'absence de critère, le patient est classé non urgent (niveau 5).
     *
     * @param  array<int, string>  $criteres
     * @return array{niveau: int, delai: int, declencheurs: array<int, string>}
     */
    public function calculerNiveau(array $criteres): array
    {
        $bareme = $this->niveauxParCritere();
        $retenus = array_values(array_filter($criteres, fn ($c) => isset($bareme[$c])));

        if ($retenus === []) {
            return ['niveau' => 5, 'delai' => TriageUrgence::NIVEAUX[5]['delai'], 'declencheurs' => []];
        }

        $niveau = min(array_map(fn ($c) => $bareme[$c], $retenus));

        // Ne garder que les critères qui ont réellement déterminé le niveau
        $declencheurs = array_values(array_filter($retenus, fn ($c) => $bareme[$c] === $niveau));

        return [
            'niveau' => $niveau,
            'delai' => TriageUrgence::NIVEAUX[$niveau]['delai'],
            'declencheurs' => $declencheurs,
        ];
    }

    /**
     * Enregistre le triage d'une visite d'urgence.
     *
     * @param  array<int, string>  $criteres
     */
    public function enregistrer(Visit $visit, array $criteres, bool $atr = false, ?string $observation = null): TriageUrgence
    {
        $calcul = $this->calculerNiveau($criteres);

        $triage = TriageUrgence::create([
            'visit_id' => $visit->id,
            'user_id' => auth()->id(),
            'niveau' => $calcul['niveau'],
            'delai_cible_minutes' => $calcul['delai'],
            'criteres' => array_values($criteres),
            'criteres_declencheurs' => $calcul['declencheurs'],
            'atr' => $atr,
            'observation' => $observation,
            'triage_at' => now(),
        ]);

        // Le triage vaut prise en charge infirmière initiale de la visite
        $visit->update(['triage_fait_at' => now(), 'triage_par' => auth()->id()]);

        return $triage;
    }
}
