<?php

namespace App\Http\Controllers;

use App\Models\TauxChange;
use App\Services\AvoirService;
use App\Services\DeviseService;
use App\Services\ParametreService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Paramétrage de l'établissement : taux de change et réglages généraux.
 */
class ParametreController extends Controller
{
    public function __construct(
        private readonly ParametreService $parametres,
        private readonly DeviseService $devises,
    ) {}

    public function index(): View
    {
        $this->autoriser();

        return view('parametres.index', [
            'devises' => $this->devises->referentiel(),
            'pivot' => $this->devises->pivot(),
            'historique' => TauxChange::with('auteur')
                ->orderByDesc('applique_a')
                ->limit(30)
                ->get(),
            'plafondRemise' => (float) $this->parametres->lire(
                AvoirService::CLE_PLAFOND_REMISE,
                AvoirService::PLAFOND_REMISE_DEFAUT
            ),
        ]);
    }

    /**
     * Limite des remises que le chef de facturation accorde seul, en
     * pourcentage de la part du patient. Au-delà, la direction décide.
     */
    public function plafondRemise(Request $request): RedirectResponse
    {
        $this->autoriser();

        $donnees = $request->validate([
            'plafond' => 'required|numeric|min:0|max:100',
        ], [
            'plafond.required' => 'Indiquez le pourcentage.',
            'plafond.max' => 'Une remise ne dépasse pas 100 %.',
        ]);

        $this->parametres->ecrire(AvoirService::CLE_PLAFOND_REMISE, (float) $donnees['plafond']);

        return back()->with('success', 'Le chef de facturation peut désormais remettre jusqu\'à '
            .rtrim(rtrim(number_format((float) $donnees['plafond'], 2, ',', ' '), '0'), ',')
            .' % de la part du patient ; au-delà, la remise revient à la direction.');
    }

    /**
     * Révise le taux d'une devise.
     *
     * Les écritures passées ne bougent pas : acomptes, encaissements et
     * factures portent chacun le taux qui leur a été appliqué. Seules les
     * opérations à venir utilisent le nouveau taux.
     */
    public function reviserTaux(Request $request): RedirectResponse
    {
        $this->autoriser();

        $donnees = $request->validate([
            'devise' => 'required|'.$this->devises->regleValidation(),
            'taux_cdf' => 'required|numeric|min:0.0001|max:1000000',
            'motif' => 'nullable|string|max:500',
        ], [
            'taux_cdf.required' => 'Indiquez le nouveau taux.',
            'taux_cdf.min' => 'Le taux doit être strictement positif.',
        ]);

        if ($donnees['devise'] === $this->devises->pivot()) {
            return back()->with('error',
                'Le franc congolais est la monnaie de compte : son taux vaut 1 par définition.');
        }

        $ancien = $this->devises->taux($donnees['devise']);
        $nouveau = (float) $donnees['taux_cdf'];

        if (abs($ancien - $nouveau) < 0.0001) {
            return back()->with('info', 'Ce taux est déjà celui en vigueur.');
        }

        $revision = $this->parametres->reviserTaux($donnees['devise'], $nouveau, $donnees['motif'] ?? null);

        $variation = $revision->variation();

        return back()->with('success', sprintf(
            '1 %s vaut désormais %s CDF (%s%s%%). Les opérations déjà enregistrées gardent leur taux d\'origine.',
            $donnees['devise'],
            number_format($nouveau, 2, ',', ' '),
            $variation > 0 ? '+' : '',
            number_format((float) $variation, 2, ',', ' ')
        ));
    }

    /** Le paramétrage engage la comptabilité : il reste à la direction. */
    private function autoriser(): void
    {
        abort_unless(
            auth()->user()?->hasAnyRole(['super_admin', 'directeur']),
            403,
            'Paramétrage réservé à la direction.'
        );
    }
}
