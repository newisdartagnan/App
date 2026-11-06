<?php

namespace App\Http\Controllers\Personnel\Admin;

use App\Http\Controllers\Controller;
use App\Models\Medecin;
use App\Services\Personnel\ComptePersonnelService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** CPT-05 : vérification à l'Ordre et profil du médecin, par l'administrateur. */
class MedecinAdminController extends Controller
{
    public function __construct(private readonly ComptePersonnelService $comptes) {}

    public function verifierOrdre(Request $request, string $user): RedirectResponse
    {
        $medecin = Medecin::where('user_id', $user)->firstOrFail();
        $this->comptes->verifierOrdreParAdministrateur($medecin, $request->user());

        return redirect()->route('personnel.admin.personnel.fiche', $user)
            ->with('succes', 'Inscription à l\'Ordre vérifiée : le médecin peut être proposé aux patients.');
    }

    public function modifier(Request $request, string $user): RedirectResponse
    {
        $medecin = Medecin::where('user_id', $user)->firstOrFail();
        $this->comptes->modifierMedecin($medecin, $request->user(), $request->only(['specialite', 'presentation']));

        return redirect()->route('personnel.admin.personnel.fiche', $user)->with('succes', 'Profil du médecin enregistré.');
    }
}
