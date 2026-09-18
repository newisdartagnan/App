<?php

namespace App\Services;

/**
 * Les quatre canevas du SNIS, et ce que chacun attend.
 *
 * Le rapport mensuel n'est pas le même selon l'échelon. Un centre de santé
 * remonte la consultation préscolaire, les sites de soins communautaires et
 * la nutrition UNTA ; un hôpital général de référence remonte l'hospitalisation,
 * le bloc opératoire et la banque de sang, mais aucun des trois premiers. Le
 * bureau central de zone, lui, ne soigne personne : il rend compte de la
 * coordination, des ressources, des finances et des épidémies de toute la zone.
 *
 * L'application produisait un rapport unique, taillé pour l'hôpital général
 * où elle tourne. Une installation en centre de santé y aurait trouvé des
 * rubriques qu'elle n'a pas à remplir, et n'y aurait pas trouvé les siennes.
 *
 * Le choix se fait donc à l'écran, et le rapport s'y conforme : il ne montre
 * que les rubriques du canevas retenu, et nomme franchement les sections de ce
 * canevas qu'il ne sait pas encore remplir.
 *
 * Source : canevas mensuels simplifiés du SNIS, édition du 12 octobre 2024,
 * déposés dans « Santé RDC » à la racine du dépôt.
 */
class SystemeSanteService
{
    /**
     * L'hôpital général de référence par défaut.
     *
     * C'est le cas de cette installation, et le canevas le plus complet :
     * mieux vaut proposer une rubrique de trop qu'en cacher une qu'il fallait
     * remplir.
     */
    public const DEFAUT = 'hgr';

    /** La clé du réglage, dans les paramètres de l'établissement. */
    public const CLE = 'snis.systeme_sante';

    /**
     * Les rubriques que l'application sait produire aujourd'hui.
     *
     * Chaque clé est celle d'une section du rapport ; ce qui n'est pas dans la
     * liste d'un système donné n'est simplement pas calculé, et l'écran ne
     * l'affiche pas.
     */
    public const RUBRIQUES = [
        'consultations' => 'Consultations et utilisation des services',
        'morbidite' => 'Morbidité — diagnostics posés',
        'hospitalisation' => 'Hospitalisation — admissions, issues, séjours',
        'maternite' => 'Santé de la mère et du nouveau-né',
        'laboratoire' => 'Laboratoire et imagerie',
        'sang' => 'Banque du sang',
        'pharmacie' => 'Médicaments et intrants',
        'deces' => 'Décès enregistrés',
    ];

    /**
     * Les quatre canevas, et ce que l'application sait en remplir.
     *
     * `non_suivi` reprend les sections du canevas officiel, numérotées comme
     * elles le sont sur le formulaire : celui qui remonte le rapport les
     * retrouve telles quelles dans son registre papier.
     *
     * @var array<string, array{
     *     nom: string, sigle: string, echelon: string, pourquoi: string,
     *     rubriques: array<int, string>, non_suivi: array<int, string>
     * }>
     */
    public const SYSTEMES = [
        'cs' => [
            'nom' => 'Centre de santé',
            'sigle' => 'CS',
            'echelon' => 'Premier échelon — soins de santé primaires',
            'pourquoi' => 'Consultations, maternité, santé de l\'enfant et nutrition. Ni hospitalisation, ni bloc, ni banque du sang.',
            'rubriques' => ['consultations', 'morbidite', 'maternite', 'laboratoire', 'pharmacie', 'deces'],
            'non_suivi' => [
                '3. Planification familiale — nouvelles acceptantes par méthode',
                '4. Supervision et gestion — personnel, primes, équipements',
                '8. Santé de l\'enfant — consultations préscolaires et vaccination (PEV)',
                // Les cas qu'un relais oriente sont comptés depuis la
                // provenance ; son activité propre ne l'est pas.
                '9. Activités et gestion de la communauté — activité propre des relais',
                '10. Sites de soins communautaires',
                '11. Prise en charge nutritionnelle (UNTA)',
            ],
        ],
        'hgr' => [
            'nom' => 'Hôpital général de référence',
            'sigle' => 'HGR',
            'echelon' => 'Deuxième échelon — hôpital de la zone de santé',
            'pourquoi' => 'Le canevas complet de l\'hôpital : consultations, hospitalisation, bloc, laboratoire et banque du sang. C\'est celui de cette installation.',
            'rubriques' => ['consultations', 'morbidite', 'hospitalisation', 'maternite', 'laboratoire', 'sang', 'pharmacie', 'deces'],
            'non_suivi' => [
                '3. Planification familiale — nouvelles acceptantes par méthode',
                '4. Supervision et gestion — personnel, primes, équipements',
                '6. Notification des cas et urgences — maladies à déclaration obligatoire',
                '7. Prise en charge de la malnutrition (UNTI) — entrées et issues',
                '11. Activité du bloc opératoire — interventions par type',
            ],
        ],
        'hst' => [
            'nom' => 'Hôpital secondaire ou tertiaire',
            'sigle' => 'HST',
            'echelon' => 'Troisième échelon — hôpital provincial ou national',
            'pourquoi' => 'Même socle que l\'hôpital général de référence, sans les activités de zone : il ne remonte pas la planification familiale.',
            'rubriques' => ['consultations', 'morbidite', 'hospitalisation', 'maternite', 'laboratoire', 'sang', 'pharmacie', 'deces'],
            'non_suivi' => [
                '4. Supervision et gestion — personnel, primes, équipements',
                '6. Notification des cas et urgences — maladies à déclaration obligatoire',
                '7. Prise en charge de la malnutrition (UNTI) — entrées et issues',
                '11. Activité du bloc opératoire — interventions par type',
            ],
        ],
        'bcz' => [
            'nom' => 'Bureau central de la zone de santé',
            'sigle' => 'BCZ',
            'echelon' => 'Encadrement — coordination de la zone',
            'pourquoi' => 'Le bureau ne soigne pas : il coordonne. Son canevas porte sur la supervision, les ressources, les finances et les épidémies de toute la zone.',
            // Des huit rubriques de soins, seule la gestion des intrants
            // concerne le bureau : le reste appartient aux structures.
            'rubriques' => ['pharmacie'],
            'non_suivi' => [
                '1. Coordination et encadrement des structures de la zone',
                '2. Gestion des ressources humaines et matérielles',
                '3. Gestion financière de la zone',
                '5. Suivi des épidémies et du SNIS',
            ],
        ],
    ];

    public function __construct(private readonly ParametreService $parametres) {}

    /** Le système retenu par l'établissement courant. */
    public function systeme(): string
    {
        $choisi = $this->parametres->lire(self::CLE);

        return is_string($choisi) && isset(self::SYSTEMES[$choisi])
            ? $choisi
            : self::DEFAUT;
    }

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return self::SYSTEMES[$this->systeme()];
    }

    public function definir(string $systeme): void
    {
        if (! isset(self::SYSTEMES[$systeme])) {
            throw new \InvalidArgumentException('Système de santé inconnu : '.$systeme);
        }

        $this->parametres->ecrire(self::CLE, $systeme);
    }

    /** Les rubriques attendues du système retenu, dans l'ordre du rapport. */
    public function rubriques(): array
    {
        return $this->definition()['rubriques'];
    }

    /** Cette rubrique est-elle attendue du système retenu ? */
    public function produit(string $rubrique): bool
    {
        return in_array($rubrique, $this->rubriques(), true);
    }
}
