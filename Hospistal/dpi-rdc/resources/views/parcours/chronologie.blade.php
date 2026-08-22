@extends('layouts.app')
@section('title', 'Chronologie du parcours')
@section('content')
@php use App\Services\ParcoursTemporelService as PT; @endphp
<div class="max-w-5xl mx-auto px-4 py-6">

    <div class="flex items-center gap-3 mb-1 flex-wrap">
        <a href="{{ route('visites.show', $visit) }}" class="text-blue-700 hover:underline text-sm">← Le séjour</a>
        <h2 class="text-2xl font-bold text-gray-800">⏱️ Chronologie du parcours</h2>
    </div>
    <p class="text-sm text-gray-500 mb-5">
        {{ $visit->patient->nom_complet }} · {{ $visit->patient->dossier_number }}
        · {{ ucfirst(str_replace('_', ' ', $visit->type)) }}
        @if($visit->service) · {{ $visit->service->nom }} @endif
        — entré le {{ $visit->date_entree->format('d/m/Y à H:i') }}
    </p>

    @if($synthese['jalons'] < 2)
    <div class="bg-white rounded-xl shadow px-5 py-12 text-center text-gray-500">
        Ce séjour ne compte qu'une étape datée : il n'y a pas encore de durée à mesurer.
    </div>
    @else

    {{-- Ce que le séjour a coûté en temps --}}
    <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-5">
        <div class="bg-white rounded-xl shadow p-4">
            <p class="text-2xl font-bold text-gray-800">{{ PT::duree($synthese['total_minutes']) }}</p>
            <p class="text-xs text-gray-500 mt-1">Du premier au dernier acte</p>
        </div>
        <div class="bg-white rounded-xl shadow p-4">
            <p class="text-2xl font-bold text-green-700">{{ PT::duree($synthese['prise_en_charge_minutes']) }}</p>
            <p class="text-xs text-gray-500 mt-1">Passées avec un soignant</p>
        </div>
        <div class="bg-white rounded-xl shadow p-4">
            <p class="text-2xl font-bold {{ $synthese['part_attente'] >= 50 ? 'text-red-700' : 'text-amber-700' }}">
                {{ PT::duree($synthese['attente_minutes']) }}
            </p>
            <p class="text-xs text-gray-500 mt-1">Passées à attendre — {{ $synthese['part_attente'] }} % du séjour</p>
        </div>
        <div class="bg-white rounded-xl shadow p-4">
            <p class="text-2xl font-bold text-gray-800">{{ $synthese['jalons'] }}</p>
            <p class="text-xs text-gray-500 mt-1">Étapes datées</p>
        </div>
    </div>

    @if($synthese['pire_attente'])
    <div class="bg-amber-50 border border-amber-200 rounded-xl px-4 py-3 mb-5 text-sm text-amber-900">
        <strong>La plus longue attente :</strong>
        {{ PT::duree($synthese['pire_attente']['minutes']) }} —
        {{ lcfirst($synthese['pire_attente']['libelle']) }},
        de {{ $synthese['pire_attente']['depuis']->format('H:i') }}
        à {{ $synthese['pire_attente']['jusqua']->format('H:i') }}.
        C'est là qu'il faut aller regarder.
    </div>
    @endif

    {{-- Répartition par poste --}}
    <div class="bg-white rounded-xl shadow overflow-hidden mb-5">
        <div class="px-5 py-3 border-b font-semibold text-gray-700">Temps par poste</div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-left text-gray-600">
                    <tr>
                        <th class="px-4 py-2">Poste</th>
                        <th class="px-4 py-2 text-right">Prise en charge</th>
                        <th class="px-4 py-2 text-right">Attente</th>
                        <th class="px-4 py-2 w-1/3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach($synthese['par_poste'] as $ligne)
                    @php
                        $cumul = $ligne['prise_en_charge'] + $ligne['attente'];
                        $part = $synthese['total_minutes'] > 0
                            ? round($cumul * 100 / $synthese['total_minutes']) : 0;
                    @endphp
                    <tr>
                        <td class="px-4 py-2 font-medium">{{ $ligne['libelle'] }}</td>
                        <td class="px-4 py-2 text-right text-green-700">{{ PT::duree($ligne['prise_en_charge']) }}</td>
                        <td class="px-4 py-2 text-right text-amber-700">{{ PT::duree($ligne['attente']) }}</td>
                        <td class="px-4 py-2">
                            <div class="h-2 bg-gray-100 rounded-full overflow-hidden">
                                <div class="h-2 bg-blue-600 rounded-full" style="width: {{ $part }}%"></div>
                            </div>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    {{-- La chronologie proprement dite --}}
    <div class="bg-white rounded-xl shadow overflow-hidden">
        <div class="px-5 py-3 border-b font-semibold text-gray-700">Déroulé</div>
        <ol class="divide-y divide-gray-100">
            @foreach($jalons as $index => $jalon)
            @php $segment = $index > 0 ? $segments->firstWhere('jusqua', $jalon['moment']) : null; @endphp

            @if($segment)
            <li class="px-5 py-2 text-xs {{ $segment['attente'] ? 'bg-amber-50/60 text-amber-800' : 'bg-green-50/50 text-green-800' }}">
                {{ $segment['attente'] ? '⏳' : '🩺' }}
                {{ PT::duree($segment['minutes']) }} — {{ $segment['libelle'] }}
            </li>
            @endif

            <li class="px-5 py-3 flex flex-wrap items-start justify-between gap-3">
                <div>
                    <p class="text-sm font-medium text-gray-800">{{ $jalon['libelle'] }}</p>
                    <p class="text-xs text-gray-500">
                        {{ PT::POSTES[$jalon['poste']] ?? $jalon['poste'] }}
                        @if($jalon['acteur'])
                            · {{ $jalon['acteur']->nom_complet }}
                            <span class="text-gray-400">({{ $jalon['role'] }})</span>
                        @else
                            · <span class="text-gray-400">agent non tracé</span>
                        @endif
                    </p>
                </div>
                <p class="text-sm font-mono text-gray-600 whitespace-nowrap">
                    {{ $jalon['moment']->format('d/m H:i') }}
                </p>
            </li>
            @endforeach
        </ol>
    </div>

    <p class="text-xs text-gray-500 mt-3">
        Les durées sont reconstituées à partir des heures déjà enregistrées par
        chaque écran. Une étape sans heure de début reste un point sur la ligne :
        mieux vaut un trou franc qu'une durée inventée.
    </p>
    @endif
</div>
@endsection
