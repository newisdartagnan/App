@extends('layouts.app')
@section('title', 'Mon compte')
@section('content')
<div class="space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <h2 class="text-2xl font-bold text-blue-900">Mon compte</h2>
        <a href="{{ route('utilisateurs.activites.agent', $user) }}" class="min-h-[44px] inline-flex items-center px-4 rounded-lg border bg-white text-blue-700 hover:bg-blue-50 text-sm font-semibold">〰 Voir mes activités</a>
    </div>

    @if ($errors->any())
    <div class="bg-red-50 border border-red-200 text-red-800 rounded-lg px-4 py-3 text-sm">@foreach ($errors->all() as $err)<p>{{ $err }}</p>@endforeach</div>
    @endif

    <div class="grid md:grid-cols-2 gap-4 items-start">
        <div class="space-y-4">
            <details open class="bg-white border rounded-xl shadow-sm">
                <summary class="px-4 py-3 bg-gray-50 rounded-t-xl font-semibold text-green-800 cursor-pointer">Photo de profil</summary>
                <form method="POST" action="{{ route('compte.photo.store') }}" enctype="multipart/form-data" class="p-4 flex items-center gap-4">
                    @csrf
                    @include('compte._avatar', ['u' => $user, 'taille' => 56])
                    <div class="flex-1">
                        <label for="c-photo" class="block text-sm font-medium mb-1">Choisir photo de profil</label>
                        <input id="c-photo" type="file" name="photo" accept="image/*" required class="block w-full text-sm border rounded-lg">
                        <p class="text-xs text-gray-500 mt-1">L'image sera réduite automatiquement.</p>
                        <button class="mt-2 min-h-[40px] px-4 bg-blue-700 hover:bg-blue-800 text-white rounded-lg text-sm font-semibold">💾 Enregistrer</button>
                    </div>
                </form>
            </details>

            <details open class="bg-white border rounded-xl shadow-sm">
                <summary class="px-4 py-3 bg-gray-50 rounded-t-xl font-semibold text-green-800 cursor-pointer">Informations générales</summary>
                <form method="POST" action="{{ route('compte.informations') }}" class="p-4 grid sm:grid-cols-2 gap-3">
                    @csrf
                    <div><label for="c-nom" class="block text-sm font-medium mb-1">Nom</label>
                        <input id="c-nom" name="nom" required maxlength="100" value="{{ old('nom', $user->nom) }}" class="w-full min-h-[44px] border rounded-lg px-3"></div>
                    <div><label for="c-prenom" class="block text-sm font-medium mb-1">Prénom</label>
                        <input id="c-prenom" name="prenom" required maxlength="100" value="{{ old('prenom', $user->prenom) }}" class="w-full min-h-[44px] border rounded-lg px-3"></div>
                    <div class="sm:col-span-2"><label for="c-email" class="block text-sm font-medium mb-1">Adresse email</label>
                        <input id="c-email" name="email" type="email" maxlength="255" value="{{ old('email', $user->email) }}" class="w-full min-h-[44px] border rounded-lg px-3"></div>
                    <div><button class="min-h-[40px] px-4 bg-blue-700 hover:bg-blue-800 text-white rounded-lg text-sm font-semibold">✎ Modifier</button></div>
                </form>
            </details>

            <details open class="bg-white border rounded-xl shadow-sm">
                <summary class="px-4 py-3 bg-gray-50 rounded-t-xl font-semibold text-green-800 cursor-pointer">Modifier mon mot de passe</summary>
                <form method="POST" action="{{ route('compte.mot-de-passe') }}" class="p-4 grid sm:grid-cols-2 gap-3">
                    @csrf
                    <div><label for="c-mdp" class="block text-sm font-medium mb-1">Nouveau mot de passe</label>
                        <input id="c-mdp" name="password" type="password" required minlength="8" autocomplete="new-password" placeholder="Entrer le nouveau mot de passe" class="w-full min-h-[44px] border rounded-lg px-3"></div>
                    <div><label for="c-mdp2" class="block text-sm font-medium mb-1">Confirmer mot de passe</label>
                        <input id="c-mdp2" name="password_confirmation" type="password" required minlength="8" autocomplete="new-password" placeholder="Entrer encore le nouveau" class="w-full min-h-[44px] border rounded-lg px-3"></div>
                    <div><button class="min-h-[40px] px-4 bg-blue-700 hover:bg-blue-800 text-white rounded-lg text-sm font-semibold">✎ Modifier</button></div>
                </form>
            </details>
        </div>

        <div class="space-y-4">
            <details open class="bg-white border rounded-xl shadow-sm">
                <summary class="px-4 py-3 bg-gray-50 rounded-t-xl font-semibold text-green-800 cursor-pointer">Mes rôles ({{ $user->roles->count() }})</summary>
                <div class="p-4 flex flex-wrap gap-2">
                    @foreach($tousRoles as $role)
                        @php $aCeRole = $user->roles->contains('id', $role->id); @endphp
                        <span class="px-3 py-2 rounded-lg border text-sm {{ $aCeRole ? 'border-green-500 text-green-800 bg-green-50 font-semibold' : 'text-blue-700' }}">
                            {{ $aCeRole ? '✅ ' : '' }}{{ \App\Http\Controllers\RoleController::libelle($role) }}
                        </span>
                    @endforeach
                </div>
            </details>
            <details open class="bg-white border rounded-xl shadow-sm">
                <summary class="px-4 py-3 bg-gray-50 rounded-t-xl font-semibold text-green-800 cursor-pointer">Mes permissions</summary>
                <div class="p-4 text-sm">
                    @if($permissions === null)
                        <p class="text-green-700">Toutes les permissions accordées</p>
                    @elseif($permissions->isEmpty())
                        <p class="text-amber-700">Vous ne pouvez rien faire dans le système, vous ne disposez d'aucun rôle ni d'aucune permission.</p>
                    @else
                        <ul class="space-y-1">
                        @foreach($permissions as $p)
                            <li>✔ {{ \Database\Seeders\RolePermissionSeeder::DESCRIPTIONS[$p] ?? $p }} <span class="font-mono text-xs text-gray-400">{{ $p }}</span></li>
                        @endforeach
                        </ul>
                    @endif
                </div>
            </details>
        </div>
    </div>
</div>
@endsection
