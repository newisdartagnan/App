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
use App\Models\Role;

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
        'chef_facturation' => 'Chef de facturation et recouvrement — avoirs, conventions, relances',
        'agent_admin' => 'Agent administratif — accueil et dossiers',
    ];

    public function index(Request $request): View
    {
        $this->autoriser();

        $role = $request->query('role');
        $recherche = trim((string) $request->query('recherche'));
        $organiser = $request->query('organiser') === 'role' ? 'role' : 'nom';
        $tables = config('permission.table_names');

        $utilisateurs = User::with('roles')
            ->when($role, fn ($q) => $q->whereHas('roles', fn ($r) => $r->where('name', $role)))
            ->when($recherche !== '', fn ($q) => $q->where(fn ($w) => $w
                ->whereRaw('LOWER(nom) LIKE ?', ['%'.mb_strtolower($recherche).'%'])
                ->orWhereRaw('LOWER(prenom) LIKE ?', ['%'.mb_strtolower($recherche).'%'])
                ->orWhereRaw('LOWER(COALESCE(matricule, \'\')) LIKE ?', ['%'.mb_strtolower($recherche).'%'])
                ->orWhereRaw('LOWER(COALESCE(email, \'\')) LIKE ?', ['%'.mb_strtolower($recherche).'%'])))
            ->when($organiser === 'role', fn ($q) => $q->orderBy(
                \Illuminate\Support\Facades\DB::table($tables['model_has_roles'].' as mhr')
                    ->join($tables['roles'].' as r', 'r.id', '=', 'mhr.role_id')
                    ->whereColumn('mhr.model_id', 'users.id')
                    ->selectRaw('MIN(r.name)')
            ))
            ->orderBy('nom')
            ->paginate(25)
            ->withQueryString();

        return view('utilisateurs.index', [
            'utilisateurs' => $utilisateurs,
            'roles' => $this->rolesDisponibles(),
            'libelles' => $this->libelles(),
            'specialites' => $this->specialites(),
            'roleFiltre' => $role,
            'recherche' => $recherche,
            'organiser' => $organiser,
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
            $this->libelles()[$donnees['role']] ?? $donnees['role'],
            $utilisateur->matricule ?: $utilisateur->email
        ));
    }

    public function update(Request $request, User $utilisateur): RedirectResponse
    {
        $this->autoriser();

        // Un agent peut cumuler plusieurs rôles (« Médecin » et « Technicien
        // d'imagerie ») : la page de modification envoie roles[], la ligne
        // rapide de la liste un seul role.
        $donnees = $request->validate([
            'role' => ['required_without:roles', 'nullable', Rule::in($this->rolesDisponibles())],
            'roles' => 'required_without:role|array|min:1',
            'roles.*' => [Rule::in($this->rolesDisponibles())],
            'specialite' => 'nullable|string|max:100',
            'telephone' => 'nullable|string|max:50',
        ], ['roles.required_without' => 'Attribuez au moins un rôle.']);

        $roles = array_values(array_unique($donnees['roles'] ?? [$donnees['role']]));

        // Retirer le dernier administrateur fermerait la porte à clé de
        // l'intérieur : on refuse.
        if ($this->estDernierAdministrateur($utilisateur) && ! in_array('super_admin', $roles, true)) {
            return back()->with('error',
                'Ce compte est le dernier administrateur : changer son profil rendrait le paramétrage inaccessible.');
        }

        $utilisateur->syncRoles($roles);
        $utilisateur->update([
            'specialite' => in_array('medecin', $roles, true) ? (($donnees['specialite'] ?? null) ?: null) : null,
            'telephone' => ($donnees['telephone'] ?? null) ?: null,
        ]);

        if (in_array('medecin', $roles, true)) {
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

    /**
     * Rôles proposés : les rôles préréglés dans l'ordre, puis ceux que
     * l'établissement a créés lui-même (« Accoucheuse », « Comptable »…).
     *
     * @return array<int, string>
     */
    private function rolesDisponibles(): array
    {
        $existants = Role::orderBy('name')->pluck('name')->all();

        return array_values(array_merge(
            array_intersect(self::ORDRE_ROLES, $existants),
            array_diff($existants, self::ORDRE_ROLES)
        ));
    }

    /** @return array<string, string> */
    private function libelles(): array
    {
        $libelles = self::LIBELLES_ROLES;
        foreach (Role::whereNotIn('name', array_keys(self::LIBELLES_ROLES))->get() as $role) {
            $libelles[$role->name] = RoleController::libelle($role);
        }

        return $libelles;
    }

    /** Page de modification complète : photo, identité, rôles multiples. */
    public function edit(User $utilisateur): View
    {
        $this->autoriser();

        return view('utilisateurs.edit', [
            'utilisateur' => $utilisateur->load('roles'),
            'roles' => $this->rolesDisponibles(),
            'libelles' => $this->libelles(),
            'specialites' => $this->specialites(),
        ]);
    }

    /** Identité, adresse électronique et photo, depuis la page de modification. */
    public function identite(Request $request, User $utilisateur): RedirectResponse
    {
        $this->autoriser();

        $donnees = $request->validate([
            'nom' => 'required|string|max:100',
            'prenom' => 'required|string|max:100',
            'email' => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')->ignore($utilisateur->id)],
            'matricule' => ['nullable', 'string', 'max:50', Rule::unique('users', 'matricule')->ignore($utilisateur->id)],
        ]);

        if (blank($donnees['email'] ?? null) && blank($donnees['matricule'] ?? null)) {
            return back()->withErrors(['email' => 'Un matricule ou une adresse électronique est nécessaire pour se connecter.']);
        }

        $utilisateur->update($donnees);

        return back()->with('success', 'Informations de '.$utilisateur->nom_complet.' enregistrées.');
    }

    /**
     * « Supprimer » un compte : il est retiré du système — désactivé, sans
     * rôle — mais jamais effacé. Le journal d'audit est inviolable et cite
     * l'agent : effacer le compte priverait les actes signés de leur auteur.
     */
    public function destroy(User $utilisateur): RedirectResponse
    {
        $this->autoriser();

        if ($utilisateur->id === auth()->id()) {
            return back()->with('error', 'Vous ne pouvez pas supprimer votre propre compte.');
        }
        if ($this->estDernierAdministrateur($utilisateur)) {
            return back()->with('error', 'Ce compte est le dernier administrateur.');
        }

        $utilisateur->syncRoles([]);
        $utilisateur->update(['is_active' => false, 'remember_token' => null]);

        return back()->with('success',
            'Compte de '.$utilisateur->nom_complet.' retiré : il ne peut plus se connecter et n\'a plus aucun rôle. Son nom reste sur les actes qu\'il a signés.');
    }

    /** Journal d'activité d'un agent, ou de tous (page « Voir les activités »). */
    public function activites(Request $request, ?User $utilisateur = null): View
    {
        $estSoi = $utilisateur && $utilisateur->id === auth()->id();
        if (! $estSoi) {
            $this->autoriser();
        }

        $du = $request->query('du');
        $au = $request->query('au');
        $service = app(\App\Services\ActiviteService::class);

        return view('utilisateurs.activites', [
            'utilisateur' => $utilisateur,
            'journal' => $service->journal($utilisateur, $du, $au),
            'activites' => $service,
            'du' => $du,
            'au' => $au,
        ]);
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
            auth()->user()?->hasAnyRole(['super_admin', 'directeur']) || auth()->user()?->can('user.manage'),
            403,
            'Gestion des comptes réservée à la direction.'
        );
    }
}
