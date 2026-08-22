<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Visit;
use App\Services\ParcoursTemporelService;
use App\Services\StatistiquesAttenteService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Le temps du parcours : celui du patient, et celui des équipes.
 *
 * Un hôpital ne se juge pas seulement à ce qu'il fait, mais à ce qu'il fait
 * attendre. Les heures étaient toutes en base ; il manquait l'écran qui les
 * met bout à bout.
 */
class ParcoursController extends Controller
{
    public function __construct(private readonly ParcoursTemporelService $parcours) {}

    /**
     * L'attente à l'échelle de l'hôpital.
     *
     * La chronologie dit ce qu'un patient a attendu ; elle ne dit pas qu'il
     * manque un caissier le lundi matin. Ici on empile les parcours.
     */
    public function attente(Request $request): View
    {
        abort_unless(
            auth()->user()?->hasAnyRole(['super_admin', 'directeur', 'infirmier_chef']),
            403,
            'Le pilotage de l\'attente relève de la direction et de l\'encadrement.'
        );

        $debut = $request->query('debut', now()->startOfMonth()->toDateString());
        $fin = $request->query('fin', now()->toDateString());
        $type = $request->query('type') ?: null;

        return view('parcours.attente', [
            'analyse' => app(StatistiquesAttenteService::class)
                ->analyse($debut, $fin, auth()->user()?->establishment_id, $type),
            'debut' => $debut,
            'fin' => $fin,
            'type' => $type,
        ]);
    }

    /** Chronologie d'un séjour, jalon par jalon. */
    public function chronologie(Visit $visit): View
    {
        return view('parcours.chronologie', [
            'visit' => $visit->load('patient', 'service'),
            'jalons' => $this->parcours->jalons($visit),
            'segments' => $this->parcours->segments($visit),
            'synthese' => $this->parcours->synthese($visit),
        ]);
    }

    /**
     * Temps d'utilisation d'un agent, sur une période.
     *
     * Chacun voit le sien ; l'encadrement voit celui de tous. Un relevé de
     * temps est un outil d'organisation, pas de surveillance : il reste donc
     * à la direction et à l'intéressé.
     */
    public function profil(Request $request, ?User $utilisateur = null): View
    {
        $utilisateur ??= $request->user();

        abort_unless($this->peutVoir($utilisateur), 403,
            'Ce relevé est réservé à l\'intéressé et à la direction.');

        return view('parcours.profil', [
            'utilisateur' => $utilisateur,
            'activite' => $this->parcours->activiteDe(
                $utilisateur,
                $request->query('debut'),
                $request->query('fin'),
            ),
            'estSoiMeme' => $request->user()?->id === $utilisateur->id,
        ]);
    }

    private function peutVoir(User $cible): bool
    {
        $moi = auth()->user();

        return $moi !== null
            && ($moi->id === $cible->id
                || $moi->hasAnyRole(['super_admin', 'directeur', 'infirmier_chef']));
    }
}
