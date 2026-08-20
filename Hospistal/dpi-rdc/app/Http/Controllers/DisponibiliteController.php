<?php

namespace App\Http\Controllers;

use App\Models\AbsenceMedecin;
use App\Models\DisponibiliteMedecin;
use App\Models\TypeConsultation;
use App\Models\User;
use App\Services\DisponibiliteService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Disponibilité des médecins par spécialité : qui consulte quoi et quand.
 * L'accueil s'y réfère avant d'envoyer un patient régler une consultation.
 */
class DisponibiliteController extends Controller
{
    public function __construct(private readonly DisponibiliteService $service) {}

    public function index(Request $request): View
    {
        $jour = $request->query('jour', now()->toDateString());
        $heure = $request->query('heure', now()->format('H:i'));

        $medecins = User::role('medecin')
            ->where('is_active', true)
            ->with(['disponibilites' => fn ($q) => $q->orderBy('jour_semaine')->orderBy('heure_debut'), 'absences'])
            ->orderBy('nom')
            ->get();

        $specialites = TypeConsultation::where('est_actif', true)
            ->get()
            ->pluck('specialite')
            ->filter()
            ->unique()
            ->sort()
            ->values();

        return view('disponibilites.index', [
            'tableau' => $this->service->tableauDesSpecialites($jour, $heure),
            'medecins' => $medecins,
            'specialites' => $specialites,
            'jour' => $jour,
            'heure' => $heure,
            'jours' => DisponibiliteMedecin::JOURS,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $donnees = $request->validate([
            'user_id' => 'required|uuid|exists:users,id',
            'jour_semaine' => 'required|integer|min:1|max:7',
            'heure_debut' => 'required|date_format:H:i',
            'heure_fin' => 'required|date_format:H:i|after:heure_debut',
            'lieu' => 'nullable|string|max:100',
        ]);

        $chevauche = DisponibiliteMedecin::where('user_id', $donnees['user_id'])
            ->where('jour_semaine', $donnees['jour_semaine'])
            ->where('is_active', true)
            ->where('heure_debut', '<', $donnees['heure_fin'])
            ->where('heure_fin', '>', $donnees['heure_debut'])
            ->exists();

        if ($chevauche) {
            return back()->withInput()->with('error', 'Ce médecin a déjà une plage sur ce créneau.');
        }

        DisponibiliteMedecin::create($donnees + ['is_active' => true]);

        return back()->with('success', 'Plage de présence enregistrée.');
    }

    public function destroy(DisponibiliteMedecin $disponibilite): RedirectResponse
    {
        $disponibilite->delete();

        return back()->with('success', 'Plage de présence supprimée.');
    }

    public function absence(Request $request): RedirectResponse
    {
        $donnees = $request->validate([
            'user_id' => 'required|uuid|exists:users,id',
            'debut' => 'required|date',
            'fin' => 'required|date|after_or_equal:debut',
            'motif' => 'nullable|string|max:150',
        ]);

        AbsenceMedecin::create($donnees);

        return back()->with('success', 'Absence enregistrée — le médecin ne sera plus proposé sur cette période.');
    }

    public function supprimerAbsence(AbsenceMedecin $absence): RedirectResponse
    {
        $absence->delete();

        return back()->with('success', 'Absence levée.');
    }
}
