<?php

namespace App\Http\Controllers;

use App\Services\StatistiqueService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Tableau de bord de pilotage : activité, occupation, plateau technique.
 */
class StatistiqueController extends Controller
{
    public function __construct(protected StatistiqueService $stats) {}

    public function index(Request $request): View
    {
        $onglet = $request->query('onglet', 'activite');
        $debut = $request->query('debut', now()->startOfMonth()->toDateString());
        $fin = $request->query('fin', now()->toDateString());

        $synthese = $this->stats->synthese($debut, $fin);

        $donnees = match ($onglet) {
            'labo' => ['labo' => $this->stats->activiteLabo($debut, $fin)],
            'imagerie' => ['imagerie' => $this->stats->activiteImagerie($debut, $fin)],
            'pharmacie' => ['pharmacie' => $this->stats->activitePharmacie($debut, $fin)],
            'occupation' => ['occupation' => $this->stats->occupationParService()],
            default => [
                'repartitions' => $this->stats->repartitions($debut, $fin),
                'parJour' => $this->stats->admissionsParJour($debut, $fin),
            ],
        };

        return view('statistiques.index', array_merge(
            compact('onglet', 'debut', 'fin', 'synthese'),
            $donnees
        ));
    }
}
