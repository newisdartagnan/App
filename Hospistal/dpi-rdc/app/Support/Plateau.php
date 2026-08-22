<?php

namespace App\Support;

/**
 * Le vocabulaire de chaque plateau technique.
 *
 * Un radiologue ne fait pas d'analyses et n'est pas un laborantin. Tant que
 * les deux plateaux partageaient les mêmes écrans, l'imagerie lisait des
 * intitulés qui ne la concernaient pas — « registre journalier des analyses »,
 * « activité des laborantins » — et cela suffit à faire douter d'un outil.
 *
 * Les mots vivent ici, une seule fois, plutôt que dans quinze ternaires
 * disséminés dans les vues.
 */
final class Plateau
{
    /** @return array<string, string> */
    public static function mots(?string $domaine): array
    {
        return $domaine === 'imagerie' ? self::IMAGERIE : self::LABORATOIRE;
    }

    public static function mot(?string $domaine, string $cle): string
    {
        return self::mots($domaine)[$cle] ?? $cle;
    }

    /**
     * L'imagerie rend un compte rendu, pas des valeurs mesurées.
     *
     * Une échographie n'a ni valeur de référence ni interprétation « haute
     * ou basse » : les colonnes qui les portent sont vides à chaque ligne et
     * encombrent une feuille qu'on lit debout.
     */
    public static function aDesValeursDeReference(?string $domaine): bool
    {
        return $domaine !== 'imagerie';
    }

    private const LABORATOIRE = [
        'service' => "Laboratoire d'analyses médicales",
        'service_court' => 'Laboratoire',
        'registre' => 'Registre journalier des analyses',
        'examen' => 'analyse',
        'examens' => 'analyses',
        'demande' => 'demande d\'analyses',
        'bulletin' => 'bulletin d\'analyses',
        'operateur' => 'Laborantin',
        'operateurs' => 'Laborantins',
        'activite_operateurs' => 'Activité des laborantins',
        'signataire' => 'Le biologiste',
        'production' => 'Prélèvement',
        'production_faite' => 'Prélèvement effectué',
        'unite' => 'Unité d\'analyse',
        'retour' => 'labo.index',
    ];

    private const IMAGERIE = [
        'service' => 'Imagerie médicale',
        'service_court' => 'Imagerie',
        'registre' => 'Registre journalier des examens d\'imagerie',
        'examen' => 'examen',
        'examens' => 'examens',
        'demande' => 'demande d\'imagerie',
        'bulletin' => 'compte rendu',
        'operateur' => 'Radiologue',
        'operateurs' => 'Radiologues',
        'activite_operateurs' => 'Activité des radiologues',
        'signataire' => 'Le médecin radiologue',
        'production' => 'Réalisation',
        'production_faite' => 'Examen réalisé',
        'unite' => 'Modalité',
        'retour' => 'imagerie.index',
    ];
}
