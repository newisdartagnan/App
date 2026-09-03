<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Le thème de l'application, choisi par chacun.
 *
 * Le réglage suit l'agent et non la machine : un poste d'hôpital passe de
 * main en main toute la journée, et l'infirmière de nuit ne doit pas hériter
 * du réglage du médecin du matin.
 */
class ApparenceController extends Controller
{
    /**
     * Les thèmes proposés.
     *
     * Chacun répond à une situation réelle et non à un goût : le néon de
     * midi, la salle éteinte de trois heures du matin, la fenêtre en plein
     * soleil, la vue qui baisse.
     *
     * @var array<string, array{nom: string, pourquoi: string, apercu: array<int, string>}>
     */
    public const THEMES = [
        'clair' => [
            'nom' => 'Clair',
            'pourquoi' => 'Le réglage d\'origine. Net et lumineux, il convient aux bureaux éclairés à la lumière du jour.',
            'apercu' => ['#ffffff', '#f9fafb', '#1e40af', '#1f2937'],
        ],
        'repos' => [
            'nom' => 'Repos des yeux',
            'pourquoi' => 'Le blanc devient un blanc cassé chaud, comme une feuille de papier. Pour ceux qui passent la journée devant l\'écran et le trouvent trop blanc.',
            'apercu' => ['#faf8f3', '#f3efe6', '#1e40af', '#3a352e'],
        ],
        'sombre' => [
            'nom' => 'Sombre',
            'pourquoi' => 'Fonds sombres et textes clairs, pour la garde de nuit : un écran blanc dans une salle éteinte éblouit et fatigue.',
            'apercu' => ['#2b3245', '#1c2233', '#5b8def', '#e8edf5'],
        ],
        'contraste' => [
            'nom' => 'Contraste élevé',
            'pourquoi' => 'Noir sur blanc, bordures marquées, sans demi-teinte. Pour un poste près d\'une fenêtre en plein soleil, ou pour qui distingue mal les nuances.',
            'apercu' => ['#ffffff', '#f5f5f5', '#0b2a86', '#000000'],
        ],
    ];

    /** Le thème d'un agent, ou celui d'origine s'il n'a rien choisi. */
    public static function theme(): string
    {
        $choisi = auth()->user()?->theme;

        return isset(self::THEMES[$choisi]) ? $choisi : 'clair';
    }

    public function index(): View
    {
        return view('apparence.index', [
            'themes' => self::THEMES,
            'actuel' => self::theme(),
        ]);
    }

    public function enregistrer(Request $request): RedirectResponse
    {
        $donnees = $request->validate([
            'theme' => ['required', Rule::in(array_keys(self::THEMES))],
        ], [
            'theme.in' => 'Ce thème n\'existe pas.',
        ]);

        $request->user()->update(['theme' => $donnees['theme']]);

        return back()->with('success',
            'Thème « '.self::THEMES[$donnees['theme']]['nom'].' » appliqué. Il vous suivra de poste en poste.');
    }
}
