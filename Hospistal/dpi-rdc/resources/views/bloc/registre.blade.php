@extends('layouts.app')
@section('title', 'Registre du bloc opératoire')
@section('content')
<div class="max-w-full mx-auto px-4 py-6">

    <h2 class="text-2xl font-bold text-gray-800 mb-1">🏥 Bloc opératoire</h2>
    <p class="text-sm text-gray-500 mb-5">
        Registre des interventions réalisées : ce qui a été fait, par qui, sous
        quelle anesthésie, pour quel patient et à la charge de qui.
    </p>

    @include('bloc._onglets')
    @include('bloc._flash')

    <form method="GET" class="bg-white rounded-xl shadow p-4 mb-4 flex flex-wrap gap-3 items-end no-print">
        <div>
            <label for="f-debut" class="block text-xs font-semibold text-gray-600 mb-1">Du</label>
            <input id="f-debut" name="debut" type="date" value="{{ $debut }}"
                   class="border border-gray-300 rounded-lg px-3 py-2 text-sm">
        </div>
        <div>
            <label for="f-fin" class="block text-xs font-semibold text-gray-600 mb-1">Au</label>
            <input id="f-fin" name="fin" type="date" value="{{ $fin }}"
                   class="border border-gray-300 rounded-lg px-3 py-2 text-sm">
        </div>
        <button class="bg-blue-700 hover:bg-blue-800 text-white px-4 py-2 rounded-lg text-sm">Appliquer</button>
    </form>

    {{-- Chiffres de tête --}}
    <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-5">
        <div class="bg-white rounded-xl shadow p-4">
            <p class="text-2xl font-bold text-gray-800">{{ $interventions->count() }}</p>
            <p class="text-xs text-gray-500 mt-1">Interventions réalisées</p>
        </div>
        <div class="bg-white rounded-xl shadow p-4">
            <p class="text-2xl font-bold text-gray-800">{{ $interventions->where('urgence', true)->count() }}</p>
            <p class="text-xs text-gray-500 mt-1">Dont urgences</p>
        </div>
        <div class="bg-white rounded-xl shadow p-4">
            <p class="text-2xl font-bold text-gray-800">
                {{ $dureeMoyenne ? round($dureeMoyenne).' min' : '—' }}
            </p>
            <p class="text-xs text-gray-500 mt-1">Temps moyen en salle</p>
        </div>
        <div class="bg-white rounded-xl shadow p-4">
            <p class="text-2xl font-bold text-gray-800">{{ $parSalle->count() }}</p>
            <p class="text-xs text-gray-500 mt-1">Salles utilisées</p>
        </div>
    </div>

    <div class="bg-white rounded-xl shadow overflow-hidden mb-5">
        <div class="overflow-x-auto">
            <table class="w-full text-xs">
                <thead class="bg-gray-50 text-left text-gray-600">
                    <tr>
                        <th class="px-3 py-3">Date</th>
                        <th class="px-3 py-3">Patient</th>
                        <th class="px-3 py-3">Sexe</th>
                        <th class="px-3 py-3">Âge</th>
                        <th class="px-3 py-3">Service</th>
                        <th class="px-3 py-3">Prise en charge</th>
                        <th class="px-3 py-3">Intervention</th>
                        <th class="px-3 py-3">Diagnostic</th>
                        <th class="px-3 py-3">Anesthésie</th>
                        <th class="px-3 py-3">Chirurgien</th>
                        <th class="px-3 py-3">Anesthésiste</th>
                        <th class="px-3 py-3">Salle</th>
                        <th class="px-3 py-3 text-right">Durée</th>
                        <th class="px-3 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse($interventions as $acte)
                    <tr class="{{ $acte->urgence ? 'bg-red-50/50' : '' }}">
                        <td class="px-3 py-2 whitespace-nowrap">{{ $acte->date_realisation?->format('d/m/Y H:i') }}</td>
                        <td class="px-3 py-2 font-medium">{{ $acte->patient->nom_complet }}</td>
                        <td class="px-3 py-2">{{ $acte->patient->sexe }}</td>
                        <td class="px-3 py-2">{{ $acte->patient->date_naissance?->age ?? '—' }}</td>
                        <td class="px-3 py-2">{{ $acte->visit?->service?->nom ?? '—' }}</td>
                        <td class="px-3 py-2">{{ $acte->patient->libellePriseEnCharge() }}</td>
                        <td class="px-3 py-2">{{ $acte->libelle }}</td>
                        <td class="px-3 py-2">{{ $acte->diagnostic_postop ?: ($acte->diagnostic_preop ?: '—') }}</td>
                        <td class="px-3 py-2">{{ $acte->libelleAnesthesie() }}</td>
                        <td class="px-3 py-2">{{ $acte->operateur?->nom_complet ?? '—' }}</td>
                        <td class="px-3 py-2">{{ $acte->anesthesiste?->nom_complet ?? '—' }}</td>
                        <td class="px-3 py-2">{{ $acte->salle?->nom ?? '—' }}</td>
                        <td class="px-3 py-2 text-right whitespace-nowrap">
                            {{ $acte->dureeReelleMinutes() !== null ? $acte->dureeReelleMinutes().' min' : '—' }}
                        </td>
                        <td class="px-3 py-2 text-right">
                            <a href="{{ route('bloc.feuille', $acte) }}" target="_blank"
                               class="text-blue-700 hover:underline">🖨️</a>
                        </td>
                    </tr>
                    @empty
                    <tr><td colspan="14" class="px-4 py-12 text-center text-gray-400">
                        Aucune intervention réalisée sur cette période.
                    </td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="grid md:grid-cols-3 gap-4">
        @foreach([
            'Par salle' => $parSalle,
            'Par type d\'anesthésie' => $parAnesthesie,
            'Par chirurgien' => $parChirurgien,
        ] as $titre => $donnees)
        <div class="bg-white rounded-xl shadow overflow-hidden">
            <div class="px-4 py-3 border-b font-semibold text-gray-700 text-sm">{{ $titre }}</div>
            <div class="p-4 space-y-1 max-h-64 overflow-y-auto">
                @forelse($donnees as $libelle => $nombre)
                <div class="flex justify-between text-xs">
                    <span class="text-gray-700">{{ $libelle }}</span>
                    <span class="font-semibold">{{ $nombre }}</span>
                </div>
                @empty
                <p class="text-xs text-gray-400 text-center py-3">Aucune donnée</p>
                @endforelse
            </div>
        </div>
        @endforeach
    </div>
</div>
@endsection
