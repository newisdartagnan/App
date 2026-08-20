<?php

namespace App\Http\Controllers;

use App\Models\Establishment;
use App\Models\TypeConsultation;
use App\Models\User;
use Database\Seeders\DisponibiliteMedecinSeeder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Spatie\Permission\Models\Role;

/**
 * Comptes du personnel et profils d'utilisation.
 *
 * Un agent se connecte avec son matricule ou son courriel ; son rôle décide
 * de ce qu'il voit et de ce qu'il peut faire. Jusqu'ici les comptes ne se
 * créaient qu'en base, à la main.
 */
class UtilisateurController extends Controller
{
    /** Rôles ordonnés du plus large au plus restreint, pour l'affichage. */
    public const ORDRE_ROLES = [
        'super_admin', 'directeur', 'medecin', 'infirmier_chef', 'infirmier',
        'laborantin', 'radiologue', 'pharmacien', 'caissier', 'agent_admin',
    ];

    public const LIBELLES_ROLES = [
        'super_admin' => 'Administrateur — accès complet',
        'directeur' => 'Directeur — pilotage, finances, paramétrage',
        'medecin' => 'Médecin — consultations, prescriptions, bilans',
        'infirmier_chef' => 'Infirmier chef — soins et dispensation',
        'infirmier' => 'Infirmier — triage et surveillance',
        'laborantin' => 'Laborantin — examens et résultats',
        'radiologue' => 'Radiologue — imagerie et comptes rendus',
        'pharmacien' => 'Pharmacien — stock et dispensation',
        'caissier' => 'Caissier — guichet, encaissements, billetage',
        'agent_admin' => 'Agent administratif — accueil et dossiers',
    ];

    public function index(Request $request): View
    {
        $this->autoriser();

        $role = $request->query('role');
        $recherche = trim((string) $request->query('recherche'));

        $utilisateurs = User::with('roles')
            ->when($role, fn ($q) => $q->whereHas('roles', fn ($r) => $r->where('name', $role)))
            ->when($recherche !== '', fn ($q) => $q->where(fn ($w) => $w
                ->whereRaw('LOWER(nom) LIKE ?', ['%'.mb_strtolower($recherche).'%'])
                ->orWhereRaw('LOWER(prenom) LIKE ?', ['%'.mb_strtolower($recherche).'%'])
                ->orWhereRaw('LOWER(COALESCE(matricule, \'\')) LIKE ?', ['%'.mb_strtolower($recherche).'%'])))
            ->orderBy('nom')
            ->paginate(25)
            ->withQueryString();

        return view('utilisateurs.index', [
            'utilisateurs' => $utilisateurs,
            'roles' => $this->rolesDisponibles(),
            'libelles' => self::LIBELLES_ROLES,
            'specialites' => $this->specialites(),
            'roleFiltre' => $role,
            'recherche' => $recherche,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->autoriser();

        $donnees = $request->validate([
            'nom' => 'required|string|max:100',
            'prenom' => 'required|string|max:100',
            'matricule' => 'nullable|string|max:50|unique:users,matricule',
            'email' => 'nullable|email|max:255|unique:users,email',
            'telephone' => 'nullable|string|max:50',
            'password' => 'required|string|min:8|max:100',
            'role' => ['required', Rule::in($this->rolesDisponibles())],
            'specialite' => 'nullable|string|max:100',
        ], [
            'password.min' => 'Le mot de passe doit faire au moins 8 caractères.',
            'matricule.unique' => 'Ce matricule est déjà attribué.',
            'email.unique' => 'Cette adresse est déjà utilisée.',
        ]);

        // On se connecte par matricule ou par courriel : sans l'un ni l'autre,
        // le compte serait créé mais inutilisable.
        if (blank($donnees['matricule'] ?? null) && blank($donnees['email'] ?? null)) {
            return back()->withInput()->withErrors([
                'matricule' => 'Renseignez au moins un matricule ou une adresse électronique : c\'est l\'identifiant de connexion.',
            ]);
        }

        // Un médecin sans spécialité tombe en médecine générale : la file
        // d'attente et la couverture par spécialité s'appuient dessus.
        $specialite = $donnees['role'] === 'medecin'
            ? (($donnees['specialite'] ?? null) ?: null)
            : null;

        $utilisateur = User::create([
            'establishment_id' => auth()->user()->establishment_id
                ?? Establishment::orderBy('created_at')->value('id'),
            'nom' => mb_strtoupper($donnees['nom']),
            'prenom' => $donnees['prenom'],
            'matricule' => $donnees['matricule'] ?? null,
            'email' => $donnees['email'] ?? null,
            'telephone' => $donnees['telephone'] ?? null,
            'password' => Hash::make($donnees['password']),
            'specialite' => $specialite,
            'is_active' => true,
        ]);

        $utilisateur->assignRole($donnees['role']);

        // Un médecin nouvellement créé reçoit ses plages de présence, sinon
        // il serait réputé disponible en permanence.
        if ($donnees['role'] === 'medecin') {
            DisponibiliteMedecinSeeder::installerPour($utilisateur);
        }

        return back()->with('success', sprintf(
            'Compte de %s créé — profil « %s ». Identifiant de connexion : %s.',
            $utilisateur->nom_complet,
            self::LIBELLES_ROLES[$donnees['role']] ?? $donnees['role'],
            $utilisateur->matricule ?: $utilisateur->email
        ));
    }

    public function update(Request $request, User $utilisateur): RedirectResponse
    {
        $this->autoriser();

        $donnees = $request->validate([
            'role' => ['required', Rule::in($this->rolesDisponibles())],
            'specialite' => 'nullable|string|max:100',
            'telephone' => 'nullable|string|max:50',
        ]);

        // Retirer le dernier administrateur fermerait la porte à clé de
        // l'intérieur : on refuse.
        if ($this->estDernierAdministrateur($utilisateur) && $donnees['role'] !== 'super_admin') {
            return back()->with('error',
                'Ce compte est le dernier administrateur : changer son profil rendrait le paramétrage inaccessible.');
        }

        $utilisateur->syncRoles([$donnees['role']]);
        $utilisateur->update([
            'specialite' => $donnees['role'] === 'medecin' ? (($donnees['specialite'] ?? null) ?: null) : null,
            'telephone' => ($donnees['telephone'] ?? null) ?: null,
        ]);

        if ($donnees['role'] === 'medecin') {
            DisponibiliteMedecinSeeder::installerPour($utilisateur);
        }

        return back()->with('success', 'Profil de '.$utilisateur->nom_complet.' mis à jour.');
    }

    /** Active ou désactive un compte, sans jamais le supprimer. */
    public function basculer(User $utilisateur): RedirectResponse
    {
        $this->autoriser();

        if ($utilisateur->id === auth()->id()) {
            return back()->with('error', 'Vous ne pouvez pas désactiver votre propre compte.');
        }

        if ($utilisateur->is_active && $this->estDernierAdministrateur($utilisateur)) {
            return back()->with('error', 'Ce compte est le dernier administrateur actif.');
        }

        $utilisateur->update(['is_active' => ! $utilisateur->is_active]);

        return back()->with('success', $utilisateur->is_active
            ? 'Compte de '.$utilisateur->nom_complet.' réactivé.'
            : 'Compte de '.$utilisateur->nom_complet.' désactivé — il ne peut plus se connecter.');
    }

    public function motDePasse(Request $request, User $utilisateur): RedirectResponse
    {
        $this->autoriser();

        $request->validate([
            'password' => 'required|string|min:8|max:100',
        ], ['password.min' => 'Le mot de passe doit faire au moins 8 caractères.']);

        $utilisateur->update(['password' => Hash::make($request->password)]);

        return back()->with('success', 'Mot de passe de '.$utilisateur->nom_complet.' réinitialisé.');
    }

    /** @return array<int, string> */
    private function rolesDisponibles(): array
    {
        $existants = Role::pluck('name')->all();

        return array_values(array_intersect(self::ORDRE_ROLES, $existants));
    }

    /** @return array<int, string> */
    private function specialites(): array
    {
        return TypeConsultation::where('est_actif', true)
            ->pluck('specialite')
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    private function estDernierAdministrateur(User $utilisateur): bool
    {
        if (! $utilisateur->hasRole('super_admin')) {
            return false;
        }

        return User::role('super_admin')->where('is_active', true)->count() <= 1;
    }

    private function autoriser(): void
    {
        abort_unless(
            auth()->user()?->hasAnyRole(['super_admin', 'directeur']),
            403,
            'Gestion des comptes réservée à la direction.'
        );
    }
}
