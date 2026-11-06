<?php

namespace App\Http\Controllers\Personnel\Admin;

use App\Enums\StatutRendezVous;
use App\Http\Controllers\Controller;
use App\Models\Medecin;
use App\Models\RendezVous;
use App\Models\Specialite;
use App\Models\TypeConsultation;
use App\Services\Administration\ReferentielTarifsService;
use App\Support\Montant;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** ADM-01 : spécialités et tarifs. Route réservée au rôle admin_fonctionnel (routes/admin.php). */
class TarifsController extends Controller
{
    public function __construct(private readonly ReferentielTarifsService $referentiel) {}

    public function index(): Response
    {
        $medecins = Medecin::reservables()->selectRaw('specialite_id, count(*) AS nombre')->groupBy('specialite_id')->pluck('nombre', 'specialite_id');
        // Rendez-vous à venir par type : un changement de prix ne les touche pas (RG-13), on le dit à l'écran.
        $aVenir = RendezVous::whereIn('statut', StatutRendezVous::occupants())->where('debut', '>=', CarbonImmutable::now())
            ->selectRaw('type_consultation_id, count(*) AS nombre')->groupBy('type_consultation_id')->pluck('nombre', 'type_consultation_id');

        return Inertia::render('Personnel/Admin/Tarifs', [
            'specialites' => Specialite::with(['typesConsultation' => fn ($q) => $q->orderBy('duree_minutes')->orderBy('nom')])
                ->orderBy('ordre')->orderBy('nom')->get()
                ->map(fn (Specialite $s) => [
                    'id' => $s->id,
                    'nom' => $s->nom,
                    'active' => $s->active,
                    'medecins' => (int) ($medecins[$s->id] ?? 0),
                    'types' => $s->typesConsultation->map(fn (TypeConsultation $t) => [
                        'id' => $t->id,
                        'nom' => $t->nom,
                        'duree_minutes' => $t->duree_minutes,
                        'prix' => $t->prix,
                        'prix_affiche' => $t->prix === null ? null : Montant::afficher((string) $t->prix, $t->devise),
                        'devise' => $t->devise,
                        'actif' => $t->actif,
                        'rendez_vous_a_venir' => (int) ($aVenir[$t->id] ?? 0),
                    ])->values(),
                ]),
            'durees' => [
                'min' => ReferentielTarifsService::DUREE_MIN,
                'max' => ReferentielTarifsService::DUREE_MAX,
                'pas' => ReferentielTarifsService::DUREE_PAS,
            ],
        ]);
    }

    public function etatSpecialite(Request $request, Specialite $specialite): RedirectResponse
    {
        $donnees = $request->validate(['active' => ['required', 'boolean']]);

        $change = $this->referentiel->changerEtatSpecialite($request->user(), $specialite, (bool) $donnees['active']);

        return back()->with('succes', ! $change
            ? 'Aucun changement.'
            : ($donnees['active'] ? "{$specialite->nom} est de nouveau proposée aux patients." : "{$specialite->nom} n'est plus proposée aux patients. Les rendez-vous déjà pris sont maintenus."));
    }

    public function creerType(Request $request, Specialite $specialite): RedirectResponse
    {
        $type = $this->referentiel->creerType($request->user(), $specialite, $this->donnees($request));

        return back()->with('succes', $type->prix === null
            ? "Type « {$type->nom} » créé, sans prix : il n'est pas proposé aux patients."
            : "Type « {$type->nom} » créé.");
    }

    public function modifierType(Request $request, TypeConsultation $type): RedirectResponse
    {
        $change = $this->referentiel->modifierType($request->user(), $type, $this->donnees($request));

        return back()->with('succes', $change
            ? "Type « {$type->fresh()->nom} » enregistré. Les rendez-vous déjà réservés gardent leur prix."
            : 'Aucun changement.');
    }

    private function donnees(Request $request): array
    {
        return $request->validate([
            'nom' => ['required', 'string', 'max:150'],
            'duree_minutes' => ['required', 'integer'],
            'prix' => ['nullable', 'string', 'max:20'],
            'devise' => ['required', 'string'],
            'actif' => ['required', 'boolean'],
        ], [
            'nom.required' => 'Donnez un nom au type de consultation.',
            'duree_minutes.required' => 'Indiquez une durée en minutes.',
            'duree_minutes.integer' => 'Indiquez une durée en minutes.',
            'devise.required' => 'Choisissez USD ou CDF.',
        ]);
    }
}
