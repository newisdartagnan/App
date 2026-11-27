@extends('layouts.app')
@section('title', 'Patients')
@section('content')
<div class="max-w-7xl mx-auto px-4 py-6">
    <div class="flex items-center justify-between mb-6">
        <h2 class="text-2xl font-bold text-gray-800">Tous les patients <span class="text-base font-normal text-gray-500">({{ $patients->total() }})</span></h2>
        <a href="{{ route('patients.create') }}"
           class="bg-blue-700 hover:bg-blue-800 text-white font-semibold px-5 py-2 rounded-lg transition">
            + Nouveau
        </a>
    </div>

    @if (session('success'))
        <div class="bg-green-50 border border-green-200 text-green-800 rounded-lg px-4 py-3 mb-4">
            {{ session('success') }}
        </div>
    @endif

    {{-- Recherche au fil de la frappe (JS pur, indépendante de Livewire) --}}
    <div class="bg-white rounded-xl shadow p-4 mb-4">
        @include('partials.recherche-patient')
    </div>

    {{-- Filtres : service, sexe, épisode, âge. Un simple formulaire GET :
         il fonctionne sans JavaScript et l'adresse se partage. --}}
    <form method="GET" action="{{ route('patients.index') }}" class="bg-white rounded-xl shadow p-4 mb-4 flex flex-wrap gap-3 items-end">
        <div class="flex-1 min-w-[12rem]">
            <label for="filtre-search" class="block text-xs font-medium text-gray-600 mb-1">Nom ou numéro de dossier</label>
            <input id="filtre-search" name="search" type="search" value="{{ $search }}"
                placeholder="Retrouver un patient par son nom ou numéro dossier"
                class="w-full min-h-[44px] rounded-lg border border-gray-300 px-4 py-2 focus:border-blue-500">
        </div>
        <div>
            <label for="filtre-service" class="block text-xs font-medium text-gray-600 mb-1">Dernier service</label>
            <select id="filtre-service" name="service" class="min-h-[44px] rounded-lg border border-gray-300 px-3">
                <option value="">Tous</option>
                @foreach($services as $s)
                <option value="{{ $s->id }}" @selected($serviceId === $s->id)>{{ $s->nom }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="filtre-sexe" class="block text-xs font-medium text-gray-600 mb-1">Sexe</label>
            <select id="filtre-sexe" name="sexe" class="min-h-[44px] rounded-lg border border-gray-300 px-3">
                <option value="">Tous</option>
                <option value="M" @selected($sexe === 'M')>Masculin</option>
                <option value="F" @selected($sexe === 'F')>Féminin</option>
            </select>
        </div>
        <div>
            <label for="filtre-episode" class="block text-xs font-medium text-gray-600 mb-1">Épisode</label>
            <select id="filtre-episode" name="episode" class="min-h-[44px] rounded-lg border border-gray-300 px-3">
                <option value="">Tous</option>
                <option value="en_cours" @selected($episode === 'en_cours')>En cours</option>
                <option value="cloture" @selected($episode === 'cloture')>Clôturé</option>
                <option value="aucun" @selected($episode === 'aucun')>Aucun épisode</option>
            </select>
        </div>
        <div>
            <span class="block text-xs font-medium text-gray-600 mb-1">Âge (ans)</span>
            <div class="flex gap-1">
                <label for="filtre-age-min" class="sr-only">Âge minimum</label>
                <input id="filtre-age-min" name="age_min" type="number" min="0" max="130" value="{{ $ageMin }}" placeholder="de"
                       class="w-20 min-h-[44px] rounded-lg border border-gray-300 px-2">
                <label for="filtre-age-max" class="sr-only">Âge maximum</label>
                <input id="filtre-age-max" name="age_max" type="number" min="0" max="130" value="{{ $ageMax }}" placeholder="à"
                       class="w-20 min-h-[44px] rounded-lg border border-gray-300 px-2">
            </div>
        </div>
        <button type="submit" class="min-h-[44px] px-4 py-2 bg-blue-700 hover:bg-blue-800 text-white rounded-lg text-sm font-semibold">Filtrer</button>
        @if($search || $sexe || $serviceId || $episode || $ageMin !== null || $ageMax !== null)
        <a href="{{ route('patients.index') }}" class="min-h-[44px] inline-flex items-center px-3 text-sm text-gray-600 hover:underline">Effacer</a>
        @endif
    </form>

    <div class="bg-white rounded-xl shadow overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 border-b">
                <tr>
                    <th class="text-left px-4 py-3 font-medium text-gray-600">N° Dossier</th>
                    <th class="text-left px-4 py-3 font-medium text-gray-600">Nom complet</th>
                    <th class="text-left px-4 py-3 font-medium text-gray-600">Sexe</th>
                    <th class="text-left px-4 py-3 font-medium text-gray-600">Âge</th>
                    <th class="text-left px-4 py-3 font-medium text-gray-600">Dernier service</th>
                    <th class="text-left px-4 py-3 font-medium text-gray-600">Épisode</th>
                    <th class="text-left px-4 py-3 font-medium text-gray-600">Début épisode</th>
                    <th class="text-left px-4 py-3 font-medium text-gray-600">Fin épisode</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse($patients as $patient)
                @php
                    $enCours = in_array($patient->dernier_statut, ['en_attente', 'en_cours'], true);
                    $debut = $patient->dernier_debut ? \Illuminate\Support\Carbon::parse($patient->dernier_debut) : null;
                    $fin = $patient->dernier_fin ? \Illuminate\Support\Carbon::parse($patient->dernier_fin) : null;
                @endphp
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3 font-mono text-xs text-gray-500">{{ $patient->dossier_number }}</td>
                    <td class="px-4 py-3 font-medium">
                        <a href="{{ route('patients.show', $patient) }}" class="text-blue-700 hover:underline">{{ $patient->nom_complet }}</a>
                    </td>
                    <td class="px-4 py-3">{{ ['M' => 'Masculin', 'F' => 'Féminin'][$patient->sexe] ?? $patient->sexe }}</td>
                    <td class="px-4 py-3 whitespace-nowrap">{{ $patient->libelleAge() }}</td>
                    <td class="px-4 py-3">{{ $nomsServices[$patient->dernier_service_id] ?? '' }}</td>
                    <td class="px-4 py-3">
                        @if($patient->dernier_statut)
                            @if($enCours)
                            <span class="px-2 py-1 rounded-full text-xs font-semibold bg-amber-100 text-amber-800">⏳ En cours</span>
                            @else
                            <span class="px-2 py-1 rounded-full text-xs font-semibold bg-green-100 text-green-800">✓ Clôturé</span>
                            @endif
                        @endif
                    </td>
                    <td class="px-4 py-3 whitespace-nowrap">{{ $debut?->format('d/m/Y H:i') }}</td>
                    <td class="px-4 py-3 whitespace-nowrap">{{ $enCours ? '' : $fin?->format('d/m/Y H:i') }}</td>
                    <td class="px-4 py-3">
                        <a href="{{ route('patients.show', $patient) }}"
                           class="inline-flex items-center min-h-[36px] px-4 bg-blue-700 hover:bg-blue-800 text-white rounded-lg text-xs font-semibold">Gérer →</a>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="9" class="px-4 py-8 text-center text-gray-400">
                        {{ $search ? 'Aucun patient trouvé pour « '.$search.' »' : 'Aucun patient ne correspond' }}
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>
        <div class="px-4 py-3 border-t">{{ $patients->links() }}</div>
    </div>
</div>
@endsection
