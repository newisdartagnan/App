@extends('layouts.app')
@section('title', 'Urgences')
@section('content')
@php
    $badge = function ($triage) {
        $couleurs = [
            'red' => 'bg-red-600 text-white', 'orange' => 'bg-orange-500 text-white',
            'yellow' => 'bg-yellow-400 text-yellow-950', 'green' => 'bg-green-600 text-white',
            'blue' => 'bg-blue-600 text-white',
        ];
        return $couleurs[$triage->couleurNiveau()] ?? 'bg-gray-400 text-white';
    };
@endphp
<div class="max-w-7xl mx-auto px-4 py-6">
    <div class="flex items-center justify-between mb-6 flex-wrap gap-3">
        <h2 class="text-2xl font-bold text-gray-800">🚨 Urgences</h2>
        <a href="{{ route('urgences.registre') }}" class="text-sm text-blue-700 hover:underline">Registre des triages →</a>
    </div>

    @foreach(['success','error'] as $t)
        @if(session($t))
        <div class="mb-4 rounded-lg px-4 py-3 text-sm border {{ $t==='success' ? 'bg-green-50 border-green-200 text-green-800' : 'bg-red-50 border-red-200 text-red-800' }}">{{ session($t) }}</div>
        @endif
    @endforeach

    <div class="grid grid-cols-3 gap-3 mb-6">
        <div class="bg-white rounded-xl shadow p-4 text-center">
            <p class="text-2xl font-bold text-amber-600">{{ $aTrier->count() }}</p><p class="text-xs text-gray-500">À trier</p>
        </div>
        <div class="bg-white rounded-xl shadow p-4 text-center">
            <p class="text-2xl font-bold text-blue-700">{{ $priseEnCharge->count() }}</p><p class="text-xs text-gray-500">En prise en charge</p>
        </div>
        <div class="bg-white rounded-xl shadow p-4 text-center">
            <p class="text-2xl font-bold text-gray-500">{{ $terminees->count() }}</p><p class="text-xs text-gray-500">Terminées</p>
        </div>
    </div>

    {{-- ── File d'attente du triage ─────────────────────────────── --}}
    <div class="bg-white rounded-xl shadow mb-6 overflow-hidden">
        <div class="px-4 py-3 border-b bg-amber-50 font-semibold text-amber-900">⏳ En attente de triage</div>
        <table class="w-full text-sm">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-3 py-2 text-left">Arrivée</th>
                    <th class="px-3 py-2 text-left">Patient</th>
                    <th class="px-3 py-2 text-center">Sexe / âge</th>
                    <th class="px-3 py-2 text-left">Motif</th>
                    <th class="px-3 py-2 text-center">Attente</th>
                    <th class="px-3 py-2 text-right"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse($aTrier as $visit)
                <tr>
                    <td class="px-3 py-2 text-xs">{{ $visit->date_entree->format('d/m H:i') }}</td>
                    <td class="px-3 py-2">
                        <span class="font-semibold">{{ $visit->patient->nom_complet }}</span>
                        <span class="block text-xs text-gray-400">{{ $visit->patient->dossier_number }}</span>
                    </td>
                    <td class="px-3 py-2 text-center text-xs">{{ $visit->patient->sexe }} · {{ $visit->patient->date_naissance?->age }} ans</td>
                    <td class="px-3 py-2 text-xs text-gray-600">{{ $visit->motif_consultation ?: '—' }}</td>
                    <td class="px-3 py-2 text-center text-xs {{ $visit->date_entree->diffInMinutes(now()) > 30 ? 'text-red-700 font-semibold' : 'text-gray-500' }}">
                        {{ (int) $visit->date_entree->diffInMinutes(now()) }} min
                    </td>
                    <td class="px-3 py-2 text-right">
                        <a href="{{ route('urgences.triage', $visit) }}" class="bg-red-700 hover:bg-red-800 text-white text-xs px-3 py-1.5 rounded-lg font-semibold">Trier</a>
                    </td>
                </tr>
                @empty
                <tr><td colspan="6" class="px-4 py-8 text-center text-gray-400">Aucun patient en attente de triage</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- ── File de prise en charge, triée par gravité ───────────── --}}
    <div class="bg-white rounded-xl shadow mb-6 overflow-hidden">
        <div class="px-4 py-3 border-b bg-blue-50 font-semibold text-blue-900">🩺 Prise en charge — les plus graves d'abord</div>
        <table class="w-full text-sm">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-3 py-2 text-center">Niveau</th>
                    <th class="px-3 py-2 text-left">Patient</th>
                    <th class="px-3 py-2 text-left">Trié à</th>
                    <th class="px-3 py-2 text-left">Échéance</th>
                    <th class="px-3 py-2 text-left">Critères déterminants</th>
                    <th class="px-3 py-2 text-right"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse($priseEnCharge as $visit)
                @php $triage = $visit->triagesUrgence->first(); @endphp
                <tr class="{{ $triage->enRetard() ? 'bg-red-50' : '' }}">
                    <td class="px-3 py-2 text-center">
                        <span class="inline-block px-2.5 py-1 rounded-lg font-bold {{ $badge($triage) }}">{{ $triage->niveau }}</span>
                        <span class="block text-[10px] text-gray-500 mt-0.5">{{ $triage->libelleNiveau() }}</span>
                    </td>
                    <td class="px-3 py-2">
                        <span class="font-semibold">{{ $visit->patient->nom_complet }}</span>
                        <span class="block text-xs text-gray-400">
                            {{ $visit->patient->sexe }} · {{ $visit->patient->date_naissance?->age }} ans
                            @if($triage->atr) · <span class="text-red-700 font-semibold">ATR</span>@endif
                        </span>
                    </td>
                    <td class="px-3 py-2 text-xs">{{ $triage->triage_at->format('d/m H:i') }}</td>
                    <td class="px-3 py-2 text-xs {{ $triage->enRetard() ? 'text-red-700 font-semibold' : 'text-gray-600' }}">
                        {{ $triage->delai_cible_minutes === 0 ? 'immédiate' : $triage->echeance()->format('H:i') }}
                        @if($triage->enRetard()) ⚠ dépassée @endif
                    </td>
                    <td class="px-3 py-2 text-xs text-gray-600">
                        @foreach(($triage->criteres_declencheurs ?? []) as $critere)
                        <span class="inline-block bg-gray-100 rounded px-1.5 py-0.5 mr-1 mb-0.5">
                            {{ app(\App\Services\TriageUrgenceService::class)->libelleCritere($critere) }}
                        </span>
                        @endforeach
                    </td>
                    <td class="px-3 py-2 text-right whitespace-nowrap">
                        <a href="{{ route('urgences.triage', $visit) }}" class="text-xs text-gray-500 hover:underline">Revoir triage</a>
                        <a href="{{ route('visites.show', $visit) }}" class="text-xs text-blue-700 font-semibold hover:underline ml-2">Parcours →</a>
                    </td>
                </tr>
                @empty
                <tr><td colspan="6" class="px-4 py-8 text-center text-gray-400">Aucun patient trié en attente de prise en charge</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if($terminees->isNotEmpty())
    <div class="bg-white rounded-xl shadow overflow-hidden">
        <div class="px-4 py-3 border-b font-semibold text-gray-700">Passages terminés</div>
        <table class="w-full text-sm">
            <tbody class="divide-y divide-gray-100">
                @foreach($terminees as $visit)
                <tr>
                    <td class="px-3 py-2 text-xs text-gray-500">{{ $visit->date_entree->format('d/m/Y H:i') }}</td>
                    <td class="px-3 py-2">{{ $visit->patient->nom_complet }}</td>
                    <td class="px-3 py-2 text-center">
                        @if($visit->triagesUrgence->isNotEmpty())
                        <span class="px-2 py-0.5 rounded text-xs font-bold {{ $badge($visit->triagesUrgence->first()) }}">
                            N{{ $visit->triagesUrgence->first()->niveau }}
                        </span>
                        @else <span class="text-gray-300">—</span>@endif
                    </td>
                    <td class="px-3 py-2 text-right"><a href="{{ route('visites.show', $visit) }}" class="text-xs text-blue-700 hover:underline">Voir →</a></td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @endif
</div>
@endsection
