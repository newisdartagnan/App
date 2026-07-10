@extends('layouts.app')
@section('title', 'Consultation')
@section('content')
<div class="max-w-4xl mx-auto px-4 py-6">

    {{-- En-tête --}}
    <div class="flex items-center justify-between mb-6">
        <div class="flex items-center gap-3">
            <a href="{{ route('patients.show', $consultation->visit->patient) }}"
               class="text-blue-700 hover:underline text-sm">← Dossier patient</a>
            <h2 class="text-2xl font-bold text-gray-800">
                Consultation du {{ $consultation->date_consultation->format('d/m/Y à H:i') }}
            </h2>
        </div>
        @can('prescription.create')
        <a href="{{ route('prescriptions.create', $consultation) }}"
           class="bg-green-600 hover:bg-green-700 text-white font-semibold px-5 py-2 rounded-lg transition text-sm">
            💊 Prescrire
        </a>
        @endcan
        <a href="{{ route('visites.show', $consultation->visit) }}"
           class="bg-indigo-600 hover:bg-indigo-700 text-white font-semibold px-5 py-2 rounded-lg transition text-sm">
            Parcours complet →
        </a>
        @if(!$factureConsult || $factureConsult->statut !== 'payee')
        <form method="POST" action="{{ route('consultations.facturer', $consultation) }}" class="inline">
            @csrf
            <button type="submit" class="bg-amber-600 hover:bg-amber-700 text-white font-semibold px-5 py-2 rounded-lg transition text-sm">
                🧾 Facturer consultation
            </button>
        </form>
        @else
        <span class="text-sm text-green-700 bg-green-100 px-3 py-2 rounded-lg">✓ Consultation payée</span>
        @endif
    </div>

    @if(session('success'))
    <div class="bg-green-50 border border-green-200 text-green-800 rounded-lg px-4 py-3 mb-4">
        {{ session('success') }}
    </div>
    @endif

    @if(session('prescription_ok'))
    <div class="bg-blue-50 border border-blue-200 text-blue-800 rounded-lg px-4 py-3 mb-4">
        💊 {{ session('prescription_ok') }}
    </div>
    @endif

    {{-- Patient --}}
    <div class="bg-blue-50 border border-blue-200 rounded-xl p-4 mb-4 flex justify-between items-center">
        <div>
            <p class="font-bold text-blue-900">{{ $consultation->visit->patient->nom_complet }}</p>
            <p class="text-sm text-blue-700">
                {{ $consultation->visit->patient->dossier_number }} —
                {{ $consultation->visit->patient->date_naissance?->format('d/m/Y') ?? 'DDN inconnue' }}
            </p>
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
                <span class="font-mono text-xs bg-blue-100 text-blue-700 px-2 py-1 rounded">
                    {{ $diag['code_cim10'] }}
                </span>
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
    <div class="bg-white rounded-xl shadow p-6 mb-4">
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

    {{-- Ordonnances liées --}}
    @php
        $prescriptions = \App\Models\Prescription::with(['lignes.medicament', 'prescripteur'])
            ->where('consultation_id', $consultation->id)
            ->get();
    @endphp
    @if($prescriptions->count() > 0)
    <div class="bg-white rounded-xl shadow overflow-hidden mb-4">
        <div class="px-6 py-4 border-b flex justify-between items-center">
            <h3 class="font-semibold text-gray-700">💊 Ordonnances ({{ $prescriptions->count() }})</h3>
        </div>
        @foreach($prescriptions as $prescription)
        <div class="px-6 py-4 border-b">
            <div class="flex justify-between items-center mb-3">
                <div>
                    <p class="text-sm font-medium text-gray-700">
                        Dr {{ $prescription->prescripteur->nom }} {{ $prescription->prescripteur->prenom }}
                        — {{ $prescription->date_prescription->format('d/m/Y H:i') }}
                    </p>
                </div>
                <div class="flex items-center gap-2">
                    <span class="px-2 py-1 rounded-full text-xs font-medium
                        @switch($prescription->statut)
                            @case('en_attente') bg-amber-100 text-amber-700 @break
                            @case('dispensee') bg-green-100 text-green-700 @break
                            @case('partiellement_dispensee') bg-blue-100 text-blue-700 @break
                            @case('annulee') bg-red-100 text-red-700 @break
                            @default bg-gray-100 text-gray-600
                        @endswitch">
                        @switch($prescription->statut)
                            @case('en_attente_paiement') ⏳ En attente paiement @break
                            @case('en_attente') ⏳ Prête à dispenser @break
                            @case('dispensee') ✅ Dispensée @break
                            @case('partiellement_dispensee') 🔄 Partielle @break
                            @case('annulee') ❌ Annulée @break
                            @default {{ $prescription->statut }}
                        @endswitch
                    </span>
                    <a href="{{ route('pharmacie.prescription', $prescription) }}"
                       class="text-blue-700 hover:underline text-xs font-medium">
                        Voir →
                    </a>
                    @if($prescription->statut === 'brouillon')
                    <a href="{{ route('caisse.facturer', $prescription) }}"
                       class="text-amber-700 hover:underline text-xs font-medium">
                        Facturer →
                    </a>
                    @endif
                </div>
            </div>
            <ul class="space-y-1">
                @foreach($prescription->lignes as $ligne)
                <li class="text-sm text-gray-600 flex items-center gap-2">
                    <span class="w-1.5 h-1.5 rounded-full bg-gray-400 flex-shrink-0"></span>
                    <span class="font-medium">{{ $ligne->medicament->denomination_commune }}</span>
                    <span class="text-gray-400">—</span>
                    <span>{{ $ligne->dose }}, {{ $ligne->frequence }}</span>
                    @if($ligne->duree_jours)
                    <span class="text-gray-400">pendant {{ $ligne->duree_jours }}j</span>
                    @endif
                    <span class="text-xs bg-gray-100 px-1.5 py-0.5 rounded">
                        {{ $ligne->quantite_totale }} {{ $ligne->medicament->unite_dispensation }}
                    </span>
                </li>
                @endforeach
            </ul>
        </div>
        @endforeach
    </div>
    @endif

</div>
@endsection