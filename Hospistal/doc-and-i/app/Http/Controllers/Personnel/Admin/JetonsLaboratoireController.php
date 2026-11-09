<?php

namespace App\Http\Controllers\Personnel\Admin;

use App\Http\Controllers\Controller;
use App\Models\JetonLaboratoire;
use App\Models\Partenaire;
use App\Services\Laboratoire\JetonsLaboratoire;
use App\Services\RendezVous\Affichage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * PRE-06 : jetons d'API des laboratoires. La valeur d'un jeton n'est montrée
 * qu'une fois, juste après son émission (donnée flash de la session) ; on ne
 * garde que son empreinte.
 */
class JetonsLaboratoireController extends Controller
{
    public function __construct(private readonly JetonsLaboratoire $jetons) {}

    public function index(Request $request): Response
    {
        return Inertia::render('Personnel/Admin/JetonsLaboratoire', [
            'laboratoires' => Partenaire::where('type', 'laboratoire')->where('actif', true)->orderBy('nom')->get(['id', 'nom']),
            'jetons' => JetonLaboratoire::with(['laboratoire:id,nom'])->orderByRaw('revoque_le IS NOT NULL')->orderByDesc('created_at')->get()
                ->map(fn (JetonLaboratoire $j) => [
                    'id' => $j->id, 'libelle' => $j->libelle, 'laboratoire' => $j->laboratoire?->nom,
                    'creeLe' => Affichage::dateHeure($j->created_at),
                    'dernierUsage' => $j->dernier_usage_le ? Affichage::dateHeure($j->dernier_usage_le) : null,
                    'revoque' => $j->revoque_le !== null,
                ]),
            // Une seule fois : juste après l'émission.
            'jetonClair' => $request->session()->get('jeton_laboratoire_clair'),
        ])->toResponse($request)->withHeaders(['Cache-Control' => 'private, no-store, max-age=0']);
    }

    public function emettre(Request $request): RedirectResponse
    {
        $donnees = $request->validate(['partenaire_id' => ['required', 'uuid'], 'libelle' => ['required', 'string', 'max:100']], [
            'partenaire_id.required' => 'Choisissez le laboratoire.',
            'libelle.required' => 'Donnez un nom au jeton.',
        ]);
        $laboratoire = Partenaire::findOrFail($donnees['partenaire_id']);
        [, $clair] = $this->jetons->emettre($request->user(), $laboratoire, $donnees['libelle']);

        return back()->with('jeton_laboratoire_clair', $clair)
            ->with('succes', 'Jeton émis : copiez-le maintenant, il ne sera plus affiché.');
    }

    public function revoquer(Request $request, JetonLaboratoire $jeton): RedirectResponse
    {
        $fait = $this->jetons->revoquer($request->user(), $jeton);

        return back()->with('succes', $fait ? 'Jeton révoqué : il ne permet plus aucun envoi.' : 'Ce jeton était déjà révoqué.');
    }
}
