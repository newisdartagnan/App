@extends('layouts.app')
@section('title', 'Comptes du personnel')
@section('content')
<div class="max-w-7xl mx-auto px-4 py-6">

    <div class="flex items-center gap-3 mb-1 flex-wrap">
        <a href="{{ route('parametres.index') }}" class="text-blue-700 hover:underline text-sm">← Paramétrage</a>
        <h2 class="text-2xl font-bold text-gray-800">👥 Comptes du personnel</h2>
    </div>
    <p class="text-sm text-gray-500 mb-5">
        Un agent se connecte avec son matricule ou son adresse électronique. Son profil
        décide de ce qu'il voit et de ce qu'il peut faire.
    </p>

    @foreach(['success','error'] as $t)
        @if(session($t))
        <div class="mb-4 rounded-lg px-4 py-3 text-sm border {{ $t==='success' ? 'bg-green-50 border-green-200 text-green-800' : 'bg-red-50 border-red-200 text-red-800' }}">{{ session($t) }}</div>
        @endif
    @endforeach

    @if ($errors->any())
    <div class="bg-red-50 border border-red-200 text-red-800 rounded-lg px-4 py-3 mb-4 text-sm">
        @foreach ($errors->all() as $err)<p>{{ $err }}</p>@endforeach
    </div>
    @endif

    {{-- Création --}}
    <details class="bg-white rounded-xl shadow mb-5" {{ $errors->any() ? 'open' : '' }}>
        <summary class="px-5 py-3 font-semibold text-gray-700 cursor-pointer select-none">
            ➕ Créer un compte
        </summary>
        <div class="px-5 pb-5 border-t pt-4">
            <form method="POST" action="{{ route('utilisateurs.store') }}" class="grid md:grid-cols-3 gap-3">
                @csrf
                <div>
                    <label for="u-nom" class="block text-xs font-semibold text-gray-600 mb-1">Nom <span class="text-red-500">*</span></label>
                    <input id="u-nom" name="nom" required maxlength="100" value="{{ old('nom') }}"
                           class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                </div>
                <div>
                    <label for="u-prenom" class="block text-xs font-semibold text-gray-600 mb-1">Prénom <span class="text-red-500">*</span></label>
                    <input id="u-prenom" name="prenom" required maxlength="100" value="{{ old('prenom') }}"
                           class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                </div>
                <div>
                    <label for="u-role" class="block text-xs font-semibold text-gray-600 mb-1">Profil d'utilisation <span class="text-red-500">*</span></label>
                    <select id="u-role" name="role" required class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                        @foreach($roles as $role)
                        <option value="{{ $role }}" @selected(old('role') === $role)>{{ $libelles[$role] ?? $role }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="u-matricule" class="block text-xs font-semibold text-gray-600 mb-1">Matricule</label>
                    <input id="u-matricule" name="matricule" maxlength="50" value="{{ old('matricule') }}"
                           placeholder="MED-014"
                           class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                </div>
                <div>
                    <label for="u-email" class="block text-xs font-semibold text-gray-600 mb-1">Adresse électronique</label>
                    <input id="u-email" name="email" type="email" maxlength="255" value="{{ old('email') }}"
                           class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                </div>
                <div>
                    <label for="u-tel" class="block text-xs font-semibold text-gray-600 mb-1">Téléphone</label>
                    <input id="u-tel" name="telephone" maxlength="50" value="{{ old('telephone') }}"
                           class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                </div>
                <div>
                    <label for="u-specialite" class="block text-xs font-semibold text-gray-600 mb-1">Spécialité (médecins)</label>
                    <select id="u-specialite" name="specialite" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                        <option value="">Médecine générale</option>
                        @foreach($specialites as $specialite)
                        <option value="{{ $specialite }}" @selected(old('specialite') === $specialite)>{{ $specialite }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="md:col-span-2">
                    <label for="u-password" class="block text-xs font-semibold text-gray-600 mb-1">Mot de passe provisoire <span class="text-red-500">*</span></label>
                    <input id="u-password" name="password" type="text" required minlength="8" maxlength="100"
                           class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm font-mono">
                    <p class="text-xs text-gray-500 mt-1">
                        Huit caractères au minimum. À communiquer à l'agent, qui devra le changer.
                    </p>
                </div>
                <div class="md:col-span-3">
                    <button class="bg-blue-700 hover:bg-blue-800 text-white rounded-lg px-5 py-2 text-sm font-semibold">
                        Créer le compte
                    </button>
                    <span class="ml-3 text-xs text-gray-500">
                        Matricule ou adresse électronique : au moins l'un des deux, c'est l'identifiant de connexion.
                    </span>
                </div>
            </form>
        </div>
    </details>

    {{-- Filtres --}}
    <form method="GET" class="bg-white rounded-xl shadow p-4 mb-4 flex flex-wrap gap-3 items-end">
        <div>
            <label for="f-role" class="block text-xs font-semibold text-gray-600 mb-1">Profil</label>
            <select id="f-role" name="role" class="border border-gray-300 rounded-lg px-3 py-2 text-sm">
                <option value="">Tous les profils</option>
                @foreach($roles as $role)
                <option value="{{ $role }}" @selected($roleFiltre === $role)>{{ $libelles[$role] ?? $role }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="f-recherche" class="block text-xs font-semibold text-gray-600 mb-1">Nom ou matricule</label>
            <input id="f-recherche" name="recherche" value="{{ $recherche }}"
                   class="border border-gray-300 rounded-lg px-3 py-2 text-sm">
        </div>
        <button class="bg-blue-700 hover:bg-blue-800 text-white px-4 py-2 rounded-lg text-sm">Filtrer</button>
    </form>

    {{-- Liste --}}
    <div class="bg-white rounded-xl shadow overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-left text-gray-600">
                    <tr>
                        <th class="px-4 py-3">Agent</th>
                        <th class="px-4 py-3">Identifiant</th>
                        <th class="px-4 py-3">Profil</th>
                        <th class="px-4 py-3">Spécialité</th>
                        <th class="px-4 py-3">Dernière connexion</th>
                        <th class="px-4 py-3">État</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse($utilisateurs as $utilisateur)
                    @php $roleCourant = $utilisateur->roles->first()?->name; @endphp
                    <tr class="align-top {{ $utilisateur->is_active ? '' : 'opacity-50' }}">
                        <td class="px-4 py-3">
                            <span class="font-medium">{{ $utilisateur->nom_complet }}</span>
                            @if($utilisateur->telephone)
                            <p class="text-xs text-gray-400">{{ $utilisateur->telephone }}</p>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-xs">
                            {{ $utilisateur->matricule ?: '—' }}
                            @if($utilisateur->email)
                            <p class="text-gray-400">{{ $utilisateur->email }}</p>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            <span class="px-2 py-0.5 rounded-full text-xs bg-blue-100 text-blue-800">
                                {{ $roleCourant ? explode(' — ', $libelles[$roleCourant] ?? $roleCourant)[0] : 'Sans profil' }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-xs text-gray-600">{{ $utilisateur->specialite ?: '—' }}</td>
                        <td class="px-4 py-3 text-xs text-gray-500 whitespace-nowrap">
                            {{ $utilisateur->last_login_at?->format('d/m/Y H:i') ?? 'jamais' }}
                        </td>
                        <td class="px-4 py-3">
                            <span class="px-2 py-0.5 rounded-full text-xs {{ $utilisateur->is_active ? 'bg-green-100 text-green-800' : 'bg-gray-200 text-gray-600' }}">
                                {{ $utilisateur->is_active ? 'Actif' : 'Désactivé' }}
                            </span>
                        </td>
                        <td class="px-4 py-3 w-96">
                            <form method="POST" action="{{ route('utilisateurs.update', $utilisateur) }}" class="flex flex-wrap gap-1 mb-2">
                                @csrf
                                <label for="r-{{ $utilisateur->id }}" class="sr-only">Profil de {{ $utilisateur->nom_complet }}</label>
                                <select id="r-{{ $utilisateur->id }}" name="role" class="flex-1 border border-gray-300 rounded px-2 py-1 text-xs">
                                    @foreach($roles as $role)
                                    <option value="{{ $role }}" @selected($roleCourant === $role)>{{ $libelles[$role] ?? $role }}</option>
                                    @endforeach
                                </select>
                                <label for="s-{{ $utilisateur->id }}" class="sr-only">Spécialité</label>
                                <select id="s-{{ $utilisateur->id }}" name="specialite" class="border border-gray-300 rounded px-2 py-1 text-xs">
                                    <option value="">Générale</option>
                                    @foreach($specialites as $specialite)
                                    <option value="{{ $specialite }}" @selected($utilisateur->specialite === $specialite)>{{ $specialite }}</option>
                                    @endforeach
                                </select>
                                <input type="hidden" name="telephone" value="{{ $utilisateur->telephone }}">
                                <button class="bg-blue-700 hover:bg-blue-800 text-white rounded px-3 py-1 text-xs font-semibold">Modifier</button>
                            </form>
                            <div class="flex flex-wrap gap-2 items-center">
                                <form method="POST" action="{{ route('utilisateurs.basculer', $utilisateur) }}">
                                    @csrf
                                    <button class="text-xs {{ $utilisateur->is_active ? 'text-red-700' : 'text-green-700' }} hover:underline">
                                        {{ $utilisateur->is_active ? 'Désactiver' : 'Réactiver' }}
                                    </button>
                                </form>
                                <form method="POST" action="{{ route('utilisateurs.mot-de-passe', $utilisateur) }}" class="flex gap-1 items-center">
                                    @csrf
                                    <label for="mp-{{ $utilisateur->id }}" class="sr-only">Nouveau mot de passe</label>
                                    <input id="mp-{{ $utilisateur->id }}" name="password" type="text" minlength="8" required
                                           placeholder="nouveau mot de passe"
                                           class="border border-gray-300 rounded px-2 py-1 text-xs font-mono w-44">
                                    <button class="text-xs text-gray-600 hover:underline">Réinitialiser</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    @empty
                    <tr><td colspan="7" class="px-4 py-10 text-center text-gray-400">Aucun compte pour ce filtre</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    <div class="mt-4">{{ $utilisateurs->links() }}</div>
</div>
@endsection
