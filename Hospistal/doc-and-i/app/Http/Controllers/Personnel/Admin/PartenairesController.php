<?php

namespace App\Http\Controllers\Personnel\Admin;

use App\Http\Controllers\Controller;
use App\Models\Partenaire;
use App\Services\Administration\PartenairesService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** ADM-01 : laboratoires et pharmacies partenaires. Route réservée au rôle admin_fonctionnel. */
class PartenairesController extends Controller
{
    public function __construct(private readonly PartenairesService $partenaires) {}

    public function index(Request $request): Response
    {
        $type = in_array($request->query('type'), Partenaire::TYPES, true) ? $request->query('type') : null;

        return Inertia::render('Personnel/Admin/Partenaires', [
            'filtre' => $type,
            'types' => Partenaire::TYPES,
            'partenaires' => Partenaire::query()
                ->when($type, fn ($q) => $q->where('type', $type))
                ->orderByDesc('actif')->orderBy('type')->orderBy('nom')
                ->get()
                ->map(fn (Partenaire $p) => $p->only(['id', 'type', 'nom', 'commune', 'adresse', 'telephone', 'email', 'actif'])),
        ]);
    }

    public function creer(Request $request): RedirectResponse
    {
        $partenaire = $this->partenaires->creer($request->user(), $this->donnees($request));

        return back()->with('succes', "« {$partenaire->nom} » ajouté.");
    }

    public function modifier(Request $request, Partenaire $partenaire): RedirectResponse
    {
        $change = $this->partenaires->modifier($request->user(), $partenaire, $this->donnees($request));

        return back()->with('succes', $change ? 'Partenaire enregistré.' : 'Aucun changement.');
    }

    public function etat(Request $request, Partenaire $partenaire): RedirectResponse
    {
        $donnees = $request->validate(['actif' => ['required', 'boolean']]);
        $change = $this->partenaires->changerEtat($request->user(), $partenaire, (bool) $donnees['actif']);

        return back()->with('succes', ! $change ? 'Aucun changement.' : ($donnees['actif'] ? 'Partenaire réactivé.' : 'Partenaire désactivé : il n\'est plus proposé, son historique reste.'));
    }

    private function donnees(Request $request): array
    {
        return $request->validate([
            'type' => ['required', Rule::in(Partenaire::TYPES)],
            'nom' => ['required', 'string', 'max:150'],
            'commune' => ['nullable', 'string', 'max:100'],
            'adresse' => ['nullable', 'string', 'max:255'],
            'telephone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
        ], [
            'type.required' => 'Choisissez laboratoire ou pharmacie.',
            'type.in' => 'Choisissez laboratoire ou pharmacie.',
            'nom.required' => 'Donnez le nom du partenaire.',
            'email.email' => 'Adresse de courriel invalide.',
        ]);
    }
}
