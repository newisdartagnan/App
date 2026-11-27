<?php

namespace App\Http\Controllers;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Spatie\Permission\Models\Permission;
use App\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Rôles (fonctions du personnel) et leurs permissions.
 *
 * Deux façons de régler un rôle : cocher les permissions une à une, ou coller
 * une « configuration en chaîne » — les noms des permissions séparés par un
 * antislash — qui s'échange d'un établissement à l'autre par copier-coller.
 * Un rôle encore attribué à un agent ne se supprime pas.
 */
class RoleController extends Controller
{
    public const SEPARATEUR = '\\';

    public function index(): View
    {
        $this->autoriser();

        $roles = Role::withCount(['users', 'permissions'])->orderBy('name')->get();

        return view('roles.index', ['roles' => $roles]);
    }

    public function create(): View
    {
        $this->autoriser();

        return view('roles.form', [
            'role' => new Role,
            'permissions' => $this->catalogue(),
            'cochees' => [],
            'chaine' => '',
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->autoriser();
        $donnees = $this->valider($request);

        $nom = Str::slug($donnees['libelle'], '_');
        if (Role::where('name', $nom)->exists()) {
            return back()->withInput()->withErrors(['libelle' => 'Un rôle porte déjà ce nom.']);
        }

        $role = Role::create([
            'name' => $nom,
            'guard_name' => 'web',
            'libelle' => $donnees['libelle'],
            'description' => $donnees['description'] ?? null,
        ]);
        $role->syncPermissions($this->permissionsDemandees($request));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return redirect()->route('roles.index')
            ->with('success', "Rôle « {$donnees['libelle']} » créé avec ".$role->permissions()->count().' permission(s).');
    }

    public function edit(Role $role): View
    {
        $this->autoriser();
        $cochees = $role->permissions->pluck('name')->all();

        return view('roles.form', [
            'role' => $role,
            'permissions' => $this->catalogue(),
            'cochees' => $cochees,
            'chaine' => $this->enChaine($cochees),
        ]);
    }

    public function update(Request $request, Role $role): RedirectResponse
    {
        $this->autoriser();
        $donnees = $this->valider($request);

        // L'administrateur garde tout : lui retirer un droit fermerait
        // l'application de l'intérieur.
        $permissions = $role->name === 'super_admin'
            ? RolePermissionSeeder::PERMISSIONS
            : $this->permissionsDemandees($request);

        $role->update([
            'libelle' => $donnees['libelle'],
            'description' => $donnees['description'] ?? null,
        ]);
        $role->syncPermissions($permissions);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return redirect()->route('roles.index')
            ->with('success', "Rôle « {$donnees['libelle']} » enregistré — ".count($permissions).' permission(s).');
    }

    public function destroy(Role $role): RedirectResponse
    {
        $this->autoriser();

        if ($role->name === 'super_admin') {
            return back()->with('error', 'Le rôle Super administrateur ne se supprime pas.');
        }
        if ($role->users()->count() > 0) {
            return back()->with('error', 'Ce rôle est attribué à des utilisateurs : retirez-le d\'abord de leurs comptes.');
        }

        $libelle = self::libelle($role);
        $role->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return back()->with('success', "Rôle « {$libelle} » supprimé.");
    }

    /** Libellé lisible d'un rôle (préréglé ou créé par l'établissement). */
    public static function libelle(\Spatie\Permission\Models\Role $role): string
    {
        return $role->libelle
            ?: (User::NOMS_ROLES[$role->name] ?? Str::of($role->name)->replace('_', ' ')->ucfirst());
    }

    /** @return array<int, string> */
    public function permissionsDemandees(Request $request): array
    {
        $connues = Permission::pluck('name')->all();
        $chaine = trim((string) $request->input('chaine', ''));

        $demandees = $chaine !== ''
            ? array_filter(array_map('trim', explode(self::SEPARATEUR, $chaine)))
            : (array) $request->input('permissions', []);

        return array_values(array_intersect(array_unique($demandees), $connues));
    }

    /** @param  array<int, string>  $noms */
    public function enChaine(array $noms): string
    {
        sort($noms);

        return $noms === [] ? '' : implode(self::SEPARATEUR, $noms).self::SEPARATEUR;
    }

    /** Catalogue des permissions, groupées par domaine, avec leur description. */
    private function catalogue(): array
    {
        $existantes = Permission::orderBy('name')->pluck('name');

        return $existantes
            ->groupBy(fn ($n) => Str::before($n, '.'))
            ->map(fn ($noms) => $noms->mapWithKeys(fn ($n) => [$n => RolePermissionSeeder::DESCRIPTIONS[$n] ?? $n]))
            ->all();
    }

    private function valider(Request $request): array
    {
        return $request->validate([
            'libelle' => 'required|string|max:150',
            'description' => 'nullable|string|max:1000',
            'permissions' => 'nullable|array',
            'permissions.*' => 'string',
            'chaine' => 'nullable|string|max:20000',
        ], ['libelle.required' => 'Donnez la désignation du rôle.']);
    }

    private function autoriser(): void
    {
        abort_unless(
            auth()->user()?->can('user.manage') || auth()->user()?->hasRole('super_admin'),
            403,
            'Gestion des rôles réservée aux administrateurs.'
        );
    }
}
