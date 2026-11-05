<?php

namespace App\Http\Controllers\Personnel\Caisse;

use App\Models\TauxChange;
use App\Services\Caisse\TauxDuJourService;
use App\Services\Paiement\TarificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** PAY-01 : taux du jour, bornes, confirmation par un second agent, remplacement. */
class TauxController extends ControleurCaisse
{
    private const STATUTS = ['actif' => 'Actif', 'a_confirmer' => 'À confirmer', 'remplace' => 'Remplacé'];

    public function __construct(private readonly TauxDuJourService $taux) {}

    public function index(Request $request, TarificationService $tarification): Response
    {
        $aujourdhui = $tarification->tauxDuJour();
        $enAttente = $this->taux->enAttente();

        return Inertia::render('Personnel/Caisse/Taux', [
            'aujourdhui' => $aujourdhui ? [
                'taux' => (string) $aujourdhui->cdf_par_usd,
                'utilise' => $this->taux->estUtilise($aujourdhui),
                'saisiPar' => self::nom($aujourdhui->saisiPar),
            ] : null,
            'enAttente' => $enAttente ? [
                'id' => $enAttente->id,
                'taux' => (string) $enAttente->cdf_par_usd,
                'saisiPar' => self::nom($enAttente->saisiPar),
                // Quatre yeux : celui qui l'a saisi ne voit pas de bouton « Confirmer ».
                'peutConfirmer' => $enAttente->saisi_par !== $request->user()->id,
            ] : null,
            'bornes' => ['minimum' => config('caisse.taux.minimum'), 'maximum' => config('caisse.taux.maximum'), 'ecart' => config('caisse.taux.ecart_confirmation_pourcent')],
            'historique' => TauxChange::with('saisiPar')->orderByDesc('jour')->orderByDesc('created_at')->limit(30)->get()->map(fn (TauxChange $t) => [
                'id' => $t->id,
                'jour' => $t->jour->format('d/m/Y'),
                'taux' => (string) $t->cdf_par_usd,
                'statut' => self::STATUTS[$t->statut] ?? $t->statut,
                'saisiPar' => self::nom($t->saisiPar),
            ])->all(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate(['taux' => ['required', 'string', 'max:20']]);

        return $this->action(function () use ($request) {
            $taux = $this->taux->enregistrer($request->input('taux'), $request->user());

            return $taux->statut === 'a_confirmer'
                ? 'Écart important avec le dernier taux : un autre agent de caisse doit le confirmer.'
                : 'Taux du jour enregistré.';
        }, 'taux', 'Taux du jour enregistré.', 'personnel.caisse.taux');
    }

    public function confirmer(Request $request, string $taux): RedirectResponse
    {
        $t = TauxChange::findOrFail($taux);

        return $this->action(fn () => $this->taux->confirmer($t, $request->user()), 'confirmation', 'Taux du jour confirmé.', 'personnel.caisse.taux');
    }
}
