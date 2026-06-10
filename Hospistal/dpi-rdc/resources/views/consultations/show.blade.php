@extends('layouts.app')
@section('title', 'Consultation')
@section('content')
<div class="max-w-4xl mx-auto px-4 py-6">
    <div class="flex items-center gap-3 mb-6">
        <a href="{{ route('patients.show', $consultation->visit->patient) }}" class="text-blue-700 hover:underline text-sm">← Dossier patient</a>
        <h2 class="text-2xl font-bold text-gray-800">Consultation du {{ $consultation->date_consultation->format('d/m/Y à H:i') }}</h2>
    </div>

    @if(session('success'))
    <div class="bg-green-50 border border-green-200 text-green-800 rounded-lg px-4 py-3 mb-4">{{ session('success') }}</div>
    @endif

    {{-- En-tête patient --}}
    <div class="bg-blue-50 border border-blue-200 rounded-xl p-4 mb-4 flex justify-between items-center">
        <div>
            <p class="font-bold text-blue-900">{{ $consultation->visit->patient->nom_complet }}</p>
            <p class="text-sm text-blue-700">{{ $consultation->visit->patient->dossier_number }} —
                {{ $consultation->visit->patient->date_naissance?->format('d/m/Y') ?? 'DDN inconnue' }}</p>
        </div>
        <span class="px-3 py-1 rounded-full text-sm font-medium
            {{ $consultation->statut === 'valide' ? 'bg-green-100 text-green-700' : 'bg-amber-100 text-amber-700' }}">
            {{ ucfirst($consultation->statut) }}
        </span>
    </div>

    {{-- Constantes vitales --}}
    @if($consultation->visit->tension_systolique || $consultation->visit->temperature)
    <div class="bg-white rounded-xl shadow p-6 mb-4">
        <h3 class="font-semibold text-gray-700 mb-3">Constantes vitales</h3>
        <div class="grid grid-cols-4 gap-4 text-sm">
            @if($consultation->visit->tension_systolique)
            <div class="text-center p-3 bg-gray-50 rounded-lg">
                <p class="text-xs text-gray-500 mb-1">Tension</p>
                <p class="font-bold text-lg {{ $consultation->visit->tension_systolique > 140 ? 'text-red-600' : 'text-gray-800' }}">
                    {{ $consultation->visit->tension_systolique }}/{{ $consultation->visit->tension_diastolique }}
                </p>
                <p class="text-xs text-gray-400">mmHg</p>
            </div>
            @endif
            @if($consultation->visit->temperature)
            <div class="text-center p-3 bg-gray-50 rounded-lg">
                <p class="text-xs text-gray-500 mb-1">Température</p>
                <p class="font-bold text-lg {{ $consultation->visit->temperature > 38.5 ? 'text-red-600' : 'text-gray-800' }}">
                    {{ $consultation->visit->temperature }}°C
                </p>
            </div>
            @endif
            @if($consultation->visit->frequence_cardiaque)
            <div class="text-center p-3 bg-gray-50 rounded-lg">
                <p class="text-xs text-gray-500 mb-1">Fréq. cardiaque</p>
                <p class="font-bold text-lg text-gray-800">{{ $consultation->visit->frequence_cardiaque }} bpm</p>
            </div>
            @endif
            @if($consultation->visit->saturation_o2)
            <div class="text-center p-3 bg-gray-50 rounded-lg">
                <p class="text-xs text-gray-500 mb-1">SpO₂</p>
                <p class="font-bold text-lg {{ $consultation->visit->saturation_o2 < 95 ? 'text-red-600' : 'text-gray-800' }}">
                    {{ $consultation->visit->saturation_o2 }}%
                </p>
            </div>
            @endif
        </div>
        @if($consultation->visit->poids_kg)
        <p class="text-sm text-gray-500 mt-2">
            Poids : {{ $consultation->visit->poids_kg }} kg
            @if($consultation->visit->taille_cm) — Taille : {{ $consultation->visit->taille_cm }} cm @endif
            @if($consultation->visit->imc) — IMC : {{ $consultation->visit->imc }} @endif
        </p>
        @endif
    </div>
    @endif

    {{-- Données cliniques --}}
    <div class="bg-white rounded-xl shadow p-6 mb-4 space-y-4">
        <h3 class="font-semibold text-gray-700 pb-2 border-b">Données cliniques</h3>
        @if($consultation->histoire_maladie)
        <div>
            <p class="text-xs font-medium text-gray-500 uppercase tracking-wide mb-1">Histoire de la maladie</p>
            <p class="text-sm text-gray-800">{{ $consultation->histoire_maladie }}</p>
        </div>
        @endif
        @if($consultation->antecedents_personnels)
        <div>
            <p class="text-xs font-medium text-gray-500 uppercase tracking-wide mb-1">Antécédents personnels</p>
            <p class="text-sm text-gray-800">{{ $consultation->antecedents_personnels }}</p>
        </div>
        @endif
        @if($consultation->allergies)
        <div>
            <p class="text-xs font-medium text-gray-500 uppercase tracking-wide mb-1">Allergies</p>
            <p class="text-sm text-red-700 font-medium">⚠️ {{ $consultation->allergies }}</p>
        </div>
        @endif
        @if($consultation->examen_general)
        <div>
            <p class="text-xs font-medium text-gray-500 uppercase tracking-wide mb-1">Examen général</p>
            <p class="text-sm text-gray-800">{{ $consultation->examen_general }}</p>
        </div>
        @endif
    </div>

    {{-- Diagnostics --}}
    @if(count($consultation->diagnostics ?? []) > 0)
    <div class="bg-white rounded-xl shadow p-6 mb-4">
        <h3 class="font-semibold text-gray-700 pb-2 border-b mb-3">Diagnostics</h3>
        <ul class="space-y-2">
            @foreach($consultation->diagnostics as $diag)
            <li class="flex items-center gap-3 text-sm">
                @if($diag['code_cim10'])
                <span class="font-mono text-xs bg-blue-100 text-blue-700 px-2 py-1 rounded">{{ $diag['code_cim10'] }}</span>
                @endif
                <span class="flex-1">{{ $diag['libelle'] }}</span>
                <span class="text-xs px-2 py-1 rounded-full
                    {{ $diag['type'] === 'principal' ? 'bg-blue-100 text-blue-700' : 'bg-gray-100 text-gray-600' }}">
                    {{ $diag['type'] }}
                </span>
            </li>
            @endforeach
        </ul>
    </div>
    @endif

    {{-- Conclusion --}}
    @if($consultation->conclusion || $consultation->conduite_a_tenir)
    <div class="bg-white rounded-xl shadow p-6">
        <h3 class="font-semibold text-gray-700 pb-2 border-b mb-3">Conclusion & conduite à tenir</h3>
        @if($consultation->conclusion)
        <p class="text-sm text-gray-800 mb-3">{{ $consultation->conclusion }}</p>
        @endif
        @if($consultation->conduite_a_tenir)
        <div class="bg-blue-50 rounded-lg p-3">
            <p class="text-xs font-medium text-blue-700 mb-1">CONDUITE À TENIR</p>
            <p class="text-sm text-blue-900">{{ $consultation->conduite_a_tenir }}</p>
        </div>
        @endif
    </div>
    @endif
</div>
@endsection