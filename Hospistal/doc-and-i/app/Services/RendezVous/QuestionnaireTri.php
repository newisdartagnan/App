<?php

namespace App\Services\RendezVous;

/**
 * RDV-02 : tri des signes d'alerte avant toute réservation. Chaque question
 * reçoit une réponse explicite, « oui » ou « non » : une réponse absente est
 * une erreur, jamais un « non » par défaut (leçon de l'audit de dpi-rdc). Un
 * seul « oui » oriente vers les urgences et bloque la réservation (RG-08).
 */
final class QuestionnaireTri
{
    public const VERSION = 1;

    /** Clé => question posée au patient, mots simples. */
    public const QUESTIONS = [
        'respiration' => 'Avez-vous du mal à respirer en ce moment ?',
        'poitrine' => 'Avez-vous une douleur forte dans la poitrine ?',
        'connaissance' => 'Avez-vous perdu connaissance, ou fait des convulsions (corps qui tremble sans contrôle) ?',
        'saignement' => 'Saignez-vous beaucoup, sans que cela s\'arrête ?',
        'paralysie' => 'Avez-vous soudain une faiblesse ou une paralysie d\'un côté du corps, ou du mal à parler ?',
        'bebe_fievre' => 'Est-ce pour un bébé de moins de 3 mois qui a de la fièvre ?',
        'grossesse' => 'Êtes-vous enceinte, avec un saignement ou une douleur forte au ventre ?',
        'se_faire_du_mal' => 'Avez-vous des pensées de vous faire du mal ou de mettre fin à votre vie ?',
    ];

    public const REPONSES = ['oui', 'non'];

    /** @return list<array{cle: string, question: string}> */
    public static function questions(): array
    {
        return array_map(
            fn (string $cle) => ['cle' => $cle, 'question' => self::QUESTIONS[$cle]],
            array_keys(self::QUESTIONS)
        );
    }

    /** @return array<string, list<string>> */
    public static function regles(): array
    {
        $regles = ['tri' => ['required', 'array']];
        foreach (array_keys(self::QUESTIONS) as $cle) {
            $regles["tri.{$cle}"] = ['required', 'string', 'in:'.implode(',', self::REPONSES)];
        }

        return $regles;
    }

    /** @return array<string, string> */
    public static function messages(): array
    {
        $messages = ['tri.required' => 'Répondez à chaque question par oui ou par non.'];
        foreach (array_keys(self::QUESTIONS) as $cle) {
            $messages["tri.{$cle}.required"] = 'Répondez par oui ou par non.';
            $messages["tri.{$cle}.in"] = 'Répondez par oui ou par non.';
            $messages["tri.{$cle}.string"] = 'Répondez par oui ou par non.';
        }

        return $messages;
    }

    /**
     * Réponses gardées telles quelles, avec le texte de la question posée
     * (traçabilité si la formulation change).
     *
     * @param  array<string, string>  $reponses  validées
     * @return array{version: int, reponses: list<array{cle: string, question: string, reponse: string}>}
     */
    public static function trace(array $reponses): array
    {
        return [
            'version' => self::VERSION,
            'reponses' => array_map(fn (string $cle) => [
                'cle' => $cle,
                'question' => self::QUESTIONS[$cle],
                'reponse' => $reponses[$cle],
            ], array_keys(self::QUESTIONS)),
        ];
    }

    /** @param  array<string, string>  $reponses  validées */
    public static function alerte(array $reponses): bool
    {
        foreach (array_keys(self::QUESTIONS) as $cle) {
            if (($reponses[$cle] ?? null) === 'oui') {
                return true;
            }
        }

        return false;
    }
}
