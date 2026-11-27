@extends('layouts.app')
@section('title', $role->exists ? 'Modifier un rôle' : 'Nouveau rôle')
@section('content')
<div class="space-y-4">
    <div>
        <a href="{{ route('roles.index') }}" class="text-blue-700 hover:underline text-sm">← Rôles des utilisateurs</a>
        <h2 class="text-2xl font-bold text-gray-800">{{ $role->exists ? 'Modifier le rôle « '.\App\Http\Controllers\RoleController::libelle($role).' »' : 'Nouveau rôle' }}</h2>
    </div>

    @if ($errors->any())
    <div class="bg-red-50 border border-red-200 text-red-800 rounded-lg px-4 py-3 text-sm">@foreach ($errors->all() as $err)<p>{{ $err }}</p>@endforeach</div>
    @endif

    <form method="POST" action="{{ $role->exists ? route('roles.update', $role) : route('roles.store') }}" class="space-y-4">
        @csrf
        @if($role->exists) @method('PUT') @endif

        <div class="bg-white rounded-xl shadow p-4 grid md:grid-cols-2 gap-4">
            <div>
                <label for="r-libelle" class="block text-sm font-semibold text-gray-700 mb-1">Désignation du rôle <span class="text-red-500">*</span></label>
                <input id="r-libelle" name="libelle" required maxlength="150" value="{{ old('libelle', $role->exists ? \App\Http\Controllers\RoleController::libelle($role) : '') }}"
                       placeholder="Ex. : Accoucheuse, Archiviste, Comptable" class="w-full min-h-[44px] border border-gray-300 rounded-lg px-3">
            </div>
            <div>
                <label for="r-description" class="block text-sm font-semibold text-gray-700 mb-1">Description</label>
                <input id="r-description" name="description" maxlength="1000" value="{{ old('description', $role->description) }}"
                       class="w-full min-h-[44px] border border-gray-300 rounded-lg px-3">
            </div>
        </div>

        @if($role->name === 'super_admin')
        <div class="bg-green-50 border border-green-200 text-green-800 rounded-xl p-4">Le Super administrateur possède toutes les permissions : elles ne se décochent pas.</div>
        @else
        <div class="bg-white rounded-xl shadow p-4">
            <h3 class="font-semibold text-gray-800 mb-1">Méthode 1 — Cocher les permissions</h3>
            <p class="text-xs text-gray-500 mb-3">Chaque permission cochée ouvre la fonctionnalité décrite.</p>
            <div class="grid md:grid-cols-2 gap-4">
            @foreach($permissions as $groupe => $liste)
                <fieldset class="border rounded-lg p-3">
                    <legend class="px-1 text-sm font-semibold text-blue-800">{{ ucfirst($groupe) }}</legend>
                    @foreach($liste as $nom => $description)
                    <label class="flex items-start gap-2 py-1 text-sm cursor-pointer">
                        <input type="checkbox" name="permissions[]" value="{{ $nom }}" class="mt-1 h-4 w-4" @checked(in_array($nom, old('permissions', $cochees), true))>
                        <span><span class="font-mono text-xs text-gray-500">{{ $nom }}</span><br>{{ $description }}</span>
                    </label>
                    @endforeach
                </fieldset>
            @endforeach
            </div>
        </div>

        <div class="bg-white rounded-xl shadow p-4">
            <h3 class="font-semibold text-gray-800 mb-1">Méthode 2 — Utiliser la configuration en chaîne</h3>
            <p class="text-xs text-gray-500 mb-2">Noms exacts des permissions séparés par un antislash « \ ». Si ce champ est rempli, il remplace les cases cochées.</p>
            <label for="r-chaine" class="sr-only">Configuration en chaîne</label>
            <textarea id="r-chaine" name="chaine" rows="3" class="w-full border border-gray-300 rounded-lg px-3 py-2 font-mono text-xs"
                      placeholder="patient.view\consultation.view\soin.execute\">{{ old('chaine') }}</textarea>
            @if($role->exists && $chaine !== '')
            <details class="mt-2">
                <summary class="text-sm text-blue-700 cursor-pointer">Afficher la configuration en chaîne</summary>
                <pre class="mt-2 p-3 bg-gray-50 border rounded text-xs whitespace-pre-wrap break-all select-all">{{ $chaine }}</pre>
            </details>
            @endif
        </div>
        @endif

        <button class="min-h-[44px] px-6 bg-blue-700 hover:bg-blue-800 text-white rounded-lg font-semibold">💾 Enregistrer</button>
    </form>
</div>
@endsection
