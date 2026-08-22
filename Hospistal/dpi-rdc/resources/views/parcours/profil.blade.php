@extends('layouts.app')
@section('title', 'Temps d\'utilisation — '.$utilisateur->nom_complet)
@section('content')
@php use App\Services\ParcoursTemporelService as PT; @endphp
<div class="max-w-6xl mx-auto px-4 py-6">

    <div class="flex items-center gap-3 mb-1">
        @unless($estSoiMeme)
        <a href="{{ route('utilisateurs.index') }}" class="text-blue-700 hover:underline text-sm">← Comptes du personnel</a>
        @else
        <a href="{{ route('dashboard') }}" class="text-blue-700 hover:underline text-sm">← Accueil</a>
        @endunless
        <h2 class="text-2xl font-bold text-gray-800">
            ⏱️ {{ $estSoiMeme ? 'Mon temps d\'utilisation' : $utilisateur->nom_complet }}
        </h2>
    </div>
    <p class="text-sm text-gray-500 mb-5">
        {{ $utilisateur->libelleRoles() }}
        @if($utilisateur->matricule) · matricule {{ $utilisateur->matricule }} @endif
        — du {{ $activite['debut']->format('d/m/Y') }} au {{ $activite['fin']->format('d/m/Y') }}.
        Le temps compté est celui passé sur le parcours d'un patient, entre deux
        étapes datées du même poste.
    </p>

    @include('partials._flash')

    <form method="GET" class="bg-white rounded-xl shadow p-4 mb-5 flex flex-wrap gap-3 items-end">
        <div>
            <label for="p-debut" class="block text-xs font-semibold text-gray-600 mb-1">Du</label>
            <input id="p-debut" name="debut" type="date" value="{{ $activite['debut']->toDateString() }}"
                   class="border border-gray-300 rounded-lg px-3 py-2 text-sm">
        </div>
        <div>
            <label for="p-fin" class="block text-xs font-semibold text-gray-600 mb-1">Au</label>
            <input id="p-fin" name="fin" type="date" value="{{ $activite['fin']->toDateString() }}"
                   class="border border-gray-300 rounded-lg px-3 py-2 text-sm">
        </div>
        <button class="bg-blue-700 hover:bg-blue-800 text-white px-4 py-2 rounded-lg text-sm">Appliquer</button>
    </form>

    <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-5">
        <div class="bg-white rounded-xl shadow p-4">
            <p class="text-2xl font-bold text-blue-800">{{ PT::duree($activite['minutes']) }}</p>
            <p class="text-xs text-gray-500 mt-1">Temps mesuré auprès des patients</p>
        </div>
        <div class="bg-white rounded-xl shadow p-4">
            <p class="text-2xl font-bold text-gray-800">{{ $activite['interventions'] }}</p>
            <p class="text-xs text-gray-500 mt-1">Interventions datées</p>
        </div>
        <div class="bg-white rounded-xl shadow p-4">
            <p class="text-2xl font-bold text-gray-800">{{ $activite['patients'] }}</p>
            <p class="text-xs text-gray-500 mt-1">Patients pris en charge</p>
        </div>
        <div class="bg-white rounded-xl shadow p-4">
            <p class="text-2xl font-bold text-gray-800">{{ PT::duree($activite['minutes_par_patient']) }}</p>
            <p class="text-xs text-gray-500 mt-1">En moyenne par patient</p>
        </div>
    </div>

    @if($activite['par_poste']->isNotEmpty())
    <div class="bg-white rounded-xl shadow overflow-hidden mb-5">
        <div class="px-5 py-3 border-b font-semibold text-gray-700">Où ce temps a été passé</div>
        <div class="divide-y divide-gray-100">
            @foreach($activite['par_poste'] as $poste => $minutes)
            <div class="px-5 py-2 flex items-center justify-between gap-4">
                <span class="text-sm">{{ $poste }}</span>
                <div class="flex items-center gap-3 flex-1 max-w-md">
                    <div class="h-2 bg-gray-100 rounded-full overflow-hidden flex-1">
                        <div class="h-2 bg-blue-600 rounded-full"
                             style="width: {{ $activite['minutes'] > 0 ? round($minutes * 100 / $activite['minutes']) : 0 }}%"></div>
                    </div>
                    <span class="text-sm font-semibold text-gray-700 w-20 text-right">{{ PT::duree($minutes) }}</span>
                </div>
            </div>
            @endforeach
        </div>
    </div>
    @endif

    <div class="bg-white rounded-xl shadow overflow-hidden">
        <div class="px-5 py-3 border-b font-semibold text-gray-700">
            Parcours patients touchés
            <span class="text-gray-400 font-normal text-sm">— {{ $activite['parcours']->count() }}</span>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-left text-gray-600">
                    <tr>
                        <th class="px-4 py-2">Patient</th>
                        <th class="px-4 py-2">Ce qui a été fait</th>
                        <th class="px-4 py-2 text-center">Actes</th>
                        <th class="px-4 py-2 text-right">Temps mesuré</th>
                        <th class="px-4 py-2">Créneau</th>
                        <th class="px-4 py-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse($activite['parcours'] as $ligne)
                    <tr>
                        <td class="px-4 py-3">
                            <p class="font-medium">{{ $ligne['patient']?->nom_complet ?? 'Patient inconnu' }}</p>
                            <p class="text-xs text-gray-400">{{ $ligne['patient']?->dossier_number }}</p>
                        </td>
                        <td class="px-4 py-3 text-xs text-gray-600">{{ implode(' · ', $ligne['etapes']) }}</td>
                        <td class="px-4 py-3 text-center">{{ $ligne['interventions'] }}</td>
                        <td class="px-4 py-3 text-right font-semibold">{{ PT::duree($ligne['minutes']) }}</td>
                        <td class="px-4 py-3 text-xs whitespace-nowrap">
                            {{ $ligne['premier']->format('d/m H:i') }} → {{ $ligne['dernier']->format('H:i') }}
                        </td>
                        <td class="px-4 py-3 text-right">
                            <a href="{{ route('parcours.chronologie', $ligne['visite']) }}"
                               class="text-xs text-blue-700 hover:underline">Chronologie →</a>
                        </td>
                    </tr>
                    @empty
                    <tr><td colspan="6" class="px-4 py-12 text-center text-gray-400">
                        Aucune intervention datée sur cette période.
                    </td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <p class="text-xs text-gray-500 mt-3">
        Un geste ponctuel — un encaissement, une validation — se compte en actes
        et non en minutes : seules les étapes encadrées par deux heures connues
        donnent une durée. Ce relevé sert à répartir la charge, pas à noter les
        personnes.
    </p>
</div>
@endsection
