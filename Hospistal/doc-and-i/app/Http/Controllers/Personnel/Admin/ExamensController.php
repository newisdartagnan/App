<?php

namespace App\Http\Controllers\Personnel\Admin;

use App\Http\Controllers\Controller;
use App\Models\Examen;
use App\Services\Laboratoire\CatalogueExamens;
use App\Support\Montant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** PRE-04 : catalogue des examens (administration). Route réservée au rôle admin_fonctionnel ; chaque écriture est journalisée. */
class ExamensController extends Controller
{
    public function __construct(private readonly CatalogueExamens $catalogue) {}

    public function index(): Response
    {
        return Inertia::render('Personnel/Admin/Examens', [
            'categories' => Examen::CATEGORIES,
            'devises' => config('doc-and-i.paiement.devises'),
            'examens' => Examen::orderByDesc('actif')->orderBy('categorie')->orderBy('libelle')->get()
                ->map(fn (Examen $e) => $e->only(['id', 'code', 'libelle', 'categorie', 'devise', 'preparation', 'actif']) + [
                    'prix' => $e->prix === null ? null : (string) $e->prix,
                    'prixAffiche' => $e->prix === null ? null : Montant::afficher((string) $e->prix, $e->devise),
                ]),
        ]);
    }

    public function creer(Request $request): RedirectResponse
    {
        $examen = $this->catalogue->creer($request->user(), $request->only(['code', 'libelle', 'categorie', 'prix', 'devise', 'preparation']));

        return back()->with('succes', "« {$examen->libelle} » ajouté au catalogue.");
    }

    public function modifier(Request $request, Examen $examen): RedirectResponse
    {
        $change = $this->catalogue->modifier($request->user(), $examen, $request->only(['code', 'libelle', 'categorie', 'prix', 'devise', 'preparation']));

        return back()->with('succes', $change ? 'Examen enregistré. Les bons déjà prescrits gardent leur prix.' : 'Aucun changement.');
    }

    public function etat(Request $request, Examen $examen): RedirectResponse
    {
        $donnees = $request->validate(['actif' => ['required', 'boolean']]);
        $change = $this->catalogue->changerEtat($request->user(), $examen, (bool) $donnees['actif']);

        return back()->with('succes', ! $change ? 'Aucun changement.' : ($donnees['actif'] ? 'Examen réactivé.' : 'Examen désactivé : il n\'est plus proposé aux médecins.'));
    }
}
