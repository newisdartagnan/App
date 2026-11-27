@extends('layouts.app')
@section('title', 'Modifier '.$utilisateur->nom_complet)
@section('content')
<div class="space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex items-center gap-3">
            @include('compte._avatar', ['u' => $utilisateur, 'taille' => 48])
            <div>
                <a href="{{ route('utilisateurs.index') }}" class="text-blue-700 hover:underline text-sm">← Utilisateurs</a>
                <h2 class="text-2xl font-bold text-gray-800">{{ $utilisateur->nom_complet }}</h2>
            </div>
        </div>
        <a href="{{ route('utilisateurs.activites.agent', $utilisateur) }}" class="min-h-[44px] inline-flex items-center px-4 rounded-lg border bg-white text-blue-700 hover:bg-blue-50 text-sm font-semibold">〰 Activités</a>
    </div>

    @if ($errors->any())
    <div class="bg-red-50 border border-red-200 text-red-800 rounded-lg px-4 py-3 text-sm">@foreach ($errors->all() as $err)<p>{{ $err }}</p>@endforeach</div>
    @endif

    <div class="grid md:grid-cols-2 gap-4 items-start">
        <form method="POST" action="{{ route('utilisateurs.identite', $utilisateur) }}" class="bg-white border rounded-xl shadow-sm p-4 grid sm:grid-cols-2 gap-3">
            @csrf
            <h3 class="sm:col-span-2 font-semibold text-green-800">Informations générales</h3>
            <div><label for="e-nom" class="block text-sm font-medium mb-1">Nom</label>
                <input id="e-nom" name="nom" required value="{{ old('nom', $utilisateur->nom) }}" class="w-full min-h-[44px] border rounded-lg px-3"></div>
            <div><label for="e-prenom" class="block text-sm font-medium mb-1">Prénom</label>
                <input id="e-prenom" name="prenom" required value="{{ old('prenom', $utilisateur->prenom) }}" class="w-full min-h-[44px] border rounded-lg px-3"></div>
            <div><label for="e-email" class="block text-sm font-medium mb-1">Adresse email</label>
                <input id="e-email" name="email" type="email" value="{{ old('email', $utilisateur->email) }}" class="w-full min-h-[44px] border rounded-lg px-3"></div>
            <div><label for="e-matricule" class="block text-sm font-medium mb-1">Matricule</label>
                <input id="e-matricule" name="matricule" value="{{ old('matricule', $utilisateur->matricule) }}" class="w-full min-h-[44px] border rounded-lg px-3"></div>
            <div><button class="min-h-[40px] px-4 bg-blue-700 hover:bg-blue-800 text-white rounded-lg text-sm font-semibold">✎ Modifier</button></div>
        </form>

        <form method="POST" action="{{ route('utilisateurs.update', $utilisateur) }}" class="bg-white border rounded-xl shadow-sm p-4 space-y-3">
            @csrf
            <h3 class="font-semibold text-green-800">Rôles de l'utilisateur</h3>
            <p class="text-xs text-gray-500">Cliquez sur un rôle pour l'activer ou le désactiver. Un agent peut en cumuler plusieurs.</p>
            <div class="flex flex-wrap gap-2">
                @foreach($roles as $role)
                <label class="cursor-pointer">
                    <input type="checkbox" name="roles[]" value="{{ $role }}" class="peer sr-only" @checked($utilisateur->hasRole($role))>
                    <span class="inline-block px-3 py-2 rounded-lg border text-sm text-blue-700 peer-checked:border-green-500 peer-checked:bg-green-50 peer-checked:text-green-800 peer-checked:font-semibold peer-focus:ring-2">
                        {{ explode(' — ', $libelles[$role] ?? $role)[0] }}
                    </span>
                </label>
                @endforeach
            </div>
            <div>
                <label for="e-specialite" class="block text-sm font-medium mb-1">Spécialité (si médecin)</label>
                <select id="e-specialite" name="specialite" class="w-full min-h-[44px] border rounded-lg px-3">
                    <option value="">Médecine générale</option>
                    @foreach($specialites as $sp)<option value="{{ $sp }}" @selected($utilisateur->specialite === $sp)>{{ $sp }}</option>@endforeach
                </select>
            </div>
            <input type="hidden" name="telephone" value="{{ $utilisateur->telephone }}">
            <button class="min-h-[40px] px-4 bg-blue-700 hover:bg-blue-800 text-white rounded-lg text-sm font-semibold">💾 Enregistrer les rôles</button>
        </form>

        <form method="POST" action="{{ route('utilisateurs.mot-de-passe', $utilisateur) }}" class="bg-white border rounded-xl shadow-sm p-4 space-y-3">
            @csrf
            <h3 class="font-semibold text-green-800">Réinitialiser le mot de passe</h3>
            <label for="e-mdp" class="sr-only">Nouveau mot de passe</label>
            <input id="e-mdp" name="password" type="text" minlength="8" required placeholder="Nouveau mot de passe (8 caractères minimum)" class="w-full min-h-[44px] border rounded-lg px-3 font-mono">
            <button class="min-h-[40px] px-4 bg-blue-700 hover:bg-blue-800 text-white rounded-lg text-sm font-semibold">✎ Modifier</button>
        </form>
    </div>
</div>
@endsection
