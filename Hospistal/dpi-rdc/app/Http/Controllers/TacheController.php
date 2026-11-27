<?php

namespace App\Http\Controllers;

use App\Models\ResultatExamen;
use App\Services\LaboratoireService;
use App\Services\TacheService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * « Mes tâches » : chaque poste reste sur cette page et voit arriver son
 * travail. La page se recharge seule (fragment HTML toutes les 15 s) et peut
 * biper quand une tâche nouvelle apparaît. Pas de websocket : un réseau local
 * sans Internet suffit.
 */
class TacheController extends Controller
{
    public function __construct(private readonly TacheService $taches) {}

    public function index(Request $request): View
    {
        $sections = $this->taches->pour($request->user());

        return view('taches.index', [
            'sections' => $sections,
            'total' => $this->taches->total($sections),
        ]);
    }

    /** Le contenu seul, relu périodiquement par la page. */
    public function fragment(Request $request): JsonResponse
    {
        $sections = $this->taches->pour($request->user());

        return response()->json([
            'total' => $this->taches->total($sections),
            'html' => view('taches._sections', ['sections' => $sections])->render(),
        ]);
    }

    /** Un résultat tapé puis « Entrée » dans la liste du laboratoire. */
    public function resultat(Request $request, ResultatExamen $resultat): JsonResponse|RedirectResponse
    {
        $donnees = $request->validate([
            'valeur' => 'required|string|max:500',
        ], ['valeur.required' => 'Saisissez un résultat.']);

        abort_unless($request->user()->can('labo.validate') || $request->user()->hasRole('laborantin'), 403);

        $examen = $resultat->examen;
        if (! $this->taches->examenPeutRecevoirResultat($examen)) {
            $message = 'Paiement guichet requis avant saisie des résultats.';

            return $request->expectsJson()
                ? response()->json(['message' => $message], 422)
                : back()->with('error', $message);
        }

        $examen = app(LaboratoireService::class)->saisirUnResultat($resultat, $donnees['valeur']);
        $resultat->refresh();

        if ($request->expectsJson()) {
            return response()->json([
                'ok' => true,
                'interpretation' => $resultat->interpretation,
                'examen_statut' => $examen->statut,
            ]);
        }

        return back()->with('success', 'Résultat enregistré.');
    }
}
