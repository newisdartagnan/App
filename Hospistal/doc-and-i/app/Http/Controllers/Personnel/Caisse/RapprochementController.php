<?php

namespace App\Http\Controllers\Personnel\Caisse;

use App\Models\EcartRapprochement;
use App\Services\Caisse\RapprochementService;
use App\Support\Montant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** PAY-06 : écarts du rapprochement quotidien, à traiter avec un commentaire. */
class RapprochementController extends ControleurCaisse
{
    private const NATURES = [
        'absent_chez_nous' => 'Au relevé de l\'opérateur, inconnu chez nous',
        'absent_du_releve' => 'Chez nous, absent du relevé de l\'opérateur',
        'montant_different' => 'Montant différent',
        'statut_different' => 'Statut différent',
    ];

    public function index(): Response
    {
        $presenter = fn (EcartRapprochement $e) => [
            'id' => $e->id,
            'jour' => $e->jour->format('d/m/Y'),
            'nature' => self::NATURES[$e->nature] ?? $e->nature,
            'operation' => $e->remboursement_id ? 'Remboursement' : 'Paiement',
            'reference' => $e->reference_fournisseur,
            'attendu' => $e->montant_attendu !== null && $e->devise ? Montant::afficher((string) $e->montant_attendu, $e->devise) : null,
            'releve' => $e->montant_releve !== null && $e->devise ? Montant::afficher((string) $e->montant_releve, $e->devise) : null,
            'commentaire' => $e->commentaire,
            'traiteLe' => self::quand($e->traite_le),
        ];

        return Inertia::render('Personnel/Caisse/Rapprochement', [
            'ouverts' => EcartRapprochement::where('statut', 'ouvert')->orderBy('jour')->orderBy('created_at')->limit(200)->get()->map($presenter)->all(),
            'traites' => EcartRapprochement::where('statut', 'traite')->orderByDesc('traite_le')->limit(30)->get()->map($presenter)->all(),
        ]);
    }

    public function traiter(Request $request, string $ecart, RapprochementService $rapprochement): RedirectResponse
    {
        $e = EcartRapprochement::findOrFail($ecart);
        $request->validate(['commentaire' => ['required', 'string', 'max:500']]);

        return $this->action(
            fn () => $rapprochement->traiter($e, $request->user(), $request->input('commentaire')),
            'commentaire', 'Écart traité.',
        );
    }
}
