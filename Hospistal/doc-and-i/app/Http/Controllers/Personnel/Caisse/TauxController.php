<?php

namespace App\Http\Controllers\Personnel\Caisse;

use App\Models\TauxChange;
use App\Services\Caisse\TauxDuJourService;
use App\Services\Paiement\TarificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** PAY-01 : taux du jour. */
class TauxController extends ControleurCaisse
{
    public function __construct(private readonly TauxDuJourService $taux) {}

    public function index(TarificationService $tarification): Response
    {
        $aujourdhui = $tarification->tauxDuJour();

        return Inertia::render('Personnel/Caisse/Taux', [
            'aujourdhui' => $aujourdhui ? [
                'taux' => (string) $aujourdhui->cdf_par_usd,
                'modifiable' => ! $this->taux->estUtilise($aujourdhui),
                'saisiPar' => self::nom($aujourdhui->saisiPar),
            ] : null,
            'historique' => TauxChange::with('saisiPar')->orderByDesc('jour')->limit(30)->get()->map(fn (TauxChange $t) => [
                'id' => $t->id,
                'jour' => $t->jour->format('d/m/Y'),
                'taux' => (string) $t->cdf_par_usd,
                'saisiPar' => self::nom($t->saisiPar),
            ])->all(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate(['taux' => ['required', 'string', 'max:20']]);

        return $this->action(
            fn () => $this->taux->enregistrer($request->input('taux'), $request->user()),
            'taux', 'Taux du jour enregistré.', 'personnel.caisse.taux',
        );
    }
}
