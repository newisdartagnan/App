<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\Image;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Mon compte : photo de profil, nom, adresse électronique, mot de passe,
 * et la liste de ses rôles et de ses permissions — ce que l'agent peut voir
 * et gérer, dit en clair.
 */
class CompteController extends Controller
{
    public function show(Request $request): View
    {
        $user = $request->user()->load('roles');
        $tousRoles = \App\Models\Role::orderBy('name')->get();

        return view('compte.index', [
            'user' => $user,
            'tousRoles' => $tousRoles,
            'permissions' => $user->hasRole('super_admin')
                ? null
                : $user->getAllPermissions()->pluck('name')->sort()->values(),
        ]);
    }

    public function photo(Request $request): RedirectResponse
    {
        $request->validate([
            'photo' => 'required|image|max:8192',
        ], ['photo.required' => 'Choisissez une image.', 'photo.image' => 'Le fichier doit être une image.']);

        $user = $request->user();
        $ancien = $user->photo_path;
        $user->update(['photo_path' => Image::enregistrerReduite($request->file('photo'), 'photos/utilisateurs', 160)]);
        if ($ancien) {
            Storage::disk('local')->delete($ancien);
        }

        return back()->with('success', 'Photo de profil enregistrée.');
    }

    public function informations(Request $request): RedirectResponse
    {
        $user = $request->user();
        $donnees = $request->validate([
            'nom' => 'required|string|max:100',
            'prenom' => 'required|string|max:100',
            'email' => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
        ]);

        if (blank($donnees['email'] ?? null) && blank($user->matricule)) {
            return back()->withErrors(['email' => 'Sans matricule, l\'adresse électronique est votre identifiant de connexion : elle ne peut pas rester vide.']);
        }

        $user->update($donnees);

        return back()->with('success', 'Informations enregistrées.');
    }

    public function motDePasse(Request $request): RedirectResponse
    {
        $request->validate([
            'password' => 'required|string|min:8|max:100|confirmed',
        ], [
            'password.min' => 'Le mot de passe doit faire au moins 8 caractères.',
            'password.confirmed' => 'Les deux mots de passe ne sont pas identiques.',
        ]);

        $request->user()->update(['password' => Hash::make($request->password)]);

        return back()->with('success', 'Mot de passe modifié.');
    }

    /** La photo passe par l'application : elle n'est pas publique. */
    public function voirPhoto(User $user): Response
    {
        abort_unless($user->photo_path && Storage::disk('local')->exists($user->photo_path), 404);

        return response(Storage::disk('local')->get($user->photo_path), 200, [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }
}
