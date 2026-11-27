<?php

namespace App\Http\Controllers;

use App\Models\Lit;
use App\Models\Service;
use App\Models\Visit;
use App\Services\TacheService;
use Illuminate\Support\Facades\Route;
use Illuminate\View\View;

/**
 * « Accès rapides » : la page d'arrivée après connexion.
 *
 * Des raccourcis Nouveau / Gérer vers ce que l'agent peut réellement faire.
 * Chaque tuile est gardée par une permission (ou un rôle) : la réceptionniste
 * ne voit pas la maintenance, le laborantin ne voit pas les utilisateurs.
 */
class DashboardController extends Controller
{
    public function index(TacheService $taches): View
    {
        $user = auth()->user();

        $stats = [
            'consultations' => Visit::whereDate('date_entree', today())
                ->where('type', 'consultation_externe')
                ->count(),
            'admissions' => Visit::whereDate('date_entree', today())->count(),
            'lits_occupes' => Lit::where('statut', 'occupe')->count(),
        ];

        $admin = $user->hasAnyRole(['super_admin', 'directeur']);
        $peut = fn (string ...$droits) => $admin || collect($droits)->contains(fn ($d) => $user->can($d));

        // [titre, icône, lien Nouveau|null, lien Gérer|null, visible]
        $tuiles = collect([
            ['Mes tâches', '💼', null, 'taches.index', true],
            ['Patients', '🧑‍🤝‍🧑', 'patients.create', 'patients.index', $peut('patient.view', 'patient.create')],
            ['Produits et intrants', '💊', 'pharmacie.medicaments', 'pharmacie.medicaments', $peut('stock.view', 'stock.adjust')],
            ['Examens de laboratoire', '🔬', 'catalogue.examens.create', 'catalogue.examens.index', $peut('labo.validate', 'settings.manage')],
            ['Techniques d\'imagerie', '🩻', 'catalogue.imagerie.create', 'catalogue.imagerie.index', $peut('labo.validate', 'settings.manage')],
            ['Services', '🏥', 'catalogue.services.create', 'catalogue.services.index', $peut('settings.manage')],
            ['Locaux de services', '🚪', 'catalogue.locaux.create', 'catalogue.locaux.index', $peut('settings.manage')],
            ['Lits de locaux', '🛏️', 'catalogue.lits.create', 'catalogue.lits.index', $peut('settings.manage', 'soin.execute')],
            ['Utilisateurs', '🧑‍⚕️', 'utilisateurs.index', 'utilisateurs.index', $peut('user.manage')],
            ['Rôles d\'utilisateurs', '🛡️', 'roles.create', 'roles.index', $peut('user.manage')],
            ['Maintenance', '🧰', null, 'maintenance.index', $user->hasRole('super_admin')],
        ])
            ->filter(fn ($t) => $t[4])
            ->map(fn ($t) => (object) [
                'titre' => $t[0],
                'icone' => $t[1],
                'nouveau' => $t[2] && Route::has($t[2]) ? route($t[2]) : null,
                'gerer' => $t[3] && Route::has($t[3]) ? route($t[3]) : null,
            ])
            ->filter(fn ($t) => $t->nouveau || $t->gerer)
            ->values();

        $nbTaches = $taches->total($taches->pour($user));
        $services = Service::where('is_active', true)->orderBy('nom')->get(['id', 'nom']);

        return view('dashboard', compact('stats', 'tuiles', 'nbTaches', 'services'));
    }
}
