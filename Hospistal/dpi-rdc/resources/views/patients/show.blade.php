@extends('layouts.app')
@section('title', $patient->nom_complet)
@section('content')
<div class="max-w-4xl mx-auto px-4 py-6">

    {{-- En-tête --}}
    <div class="flex items-center justify-between mb-6">
        <div class="flex items-center gap-3">
            <a href="{{ route('patients.index') }}" class="text-blue-700 hover:underline text-sm">← Retour</a>
            <h2 class="text-2xl font-bold text-gray-800">{{ $patient->nom_complet }}</h2>
            <span class="text-sm text-gray-500 bg-gray-100 px-3 py-1 rounded-full">{{ $patient->dossier_number }}</span>
        </div>
        @can('consultation.create')
        <a href="{{ route('consultations.create', $patient) }}"
           class="bg-blue-700 hover:bg-blue-800 text-white font-semibold px-5 py-2 rounded-lg transition">
            + Nouvelle consultation
        </a>
        @endcan
    </div>

    {{-- Données patient --}}
    <div class="bg-white rounded-xl shadow p-6 grid grid-cols-2 gap-4 text-sm mb-6">
        <div><span class="font-medium text-gray-600">Sexe :</span> {{ $patient->sexe }}</div>
        <div><span class="font-medium text-gray-600">Date de naissance :</span> {{ $patient->date_naissance?->format('d/m/Y') ?? '—' }}</div>
        <div><span class="font-medium text-gray-600">Lieu de naissance :</span> {{ $patient->lieu_naissance ?? '—' }}</div>
        <div><span class="font-medium text-gray-600">Téléphone :</span> {{ $patient->telephone ?? '—' }}</div>
        <div><span class="font-medium text-gray-600">Province :</span> {{ $patient->province ?? '—' }}</div>
        <div><span class="font-medium text-gray-600">Territoire :</span> {{ $patient->territoire ?? '—' }}</div>
        <div><span class="font-medium text-gray-600">Adresse :</span> {{ $patient->adresse ?? '—' }}</div>
        <div><span class="font-medium text-gray-600">Profession :</span> {{ $patient->profession ?? '—' }}</div>
        <div><span class="font-medium text-gray-600">Situation matrimoniale :</span> {{ $patient->situation_matrimoniale }}</div>
        <div><span class="font-medium text-gray-600">Niveau d'instruction :</span> {{ $patient->niveau_instruction }}</div>
        <div><span class="font-medium text-gray-600">Prise en charge :</span> {{ $patient->type_prise_en_charge }}</div>
        @if($patient->assurance_nom)
        <div><span class="font-medium text-gray-600">Assurance :</span> {{ $patient->assurance_nom }} — {{ $patient->assurance_numero }}</div>
        @endif
        @if($patient->contact_urgence_nom)
        <div class="col-span-2"><span class="font-medium text-gray-600">Contact urgence :</span>
            {{ $patient->contact_urgence_nom }} ({{ $patient->contact_urgence_lien }}) — {{ $patient->contact_urgence_telephone }}</div>
        @endif
    </div>

    {{-- Historique des consultations --}}
    <div class="bg-white rounded-xl shadow overflow-hidden">
        <div class="px-6 py-4 border-b">
            <h3 class="font-semibold text-gray-700">Historique des consultations</h3>
        </div>
        @forelse($patient->visits()->with(['consultations', 'user'])->orderByDesc('date_entree')->get() as $visit)
        <div class="px-6 py-4 border-b hover:bg-gray-50 transition">
            <div class="flex items-center justify-between">
                <div>
                    <p class="font-medium text-sm">{{ $visit->date_entree->format('d/m/Y à H:i') }}</p>
                    <p class="text-sm text-gray-600">{{ $visit->motif_consultation }}</p>
                    <p class="text-xs text-gray-400 mt-1">
                        {{ $visit->type === 'urgence' ? '🚨 Urgence' : 'Ambulatoire' }}
                        @if($visit->user) — Dr {{ $visit->user->nom }} {{ $visit->user->prenom }} @endif
                    </p>
                </div>
                <div class="flex items-center gap-3">
                    <span class="px-2 py-1 rounded-full text-xs
                        {{ $visit->statut === 'termine' ? 'bg-green-100 text-green-700' : 'bg-blue-100 text-blue-700' }}">
                        {{ ucfirst(str_replace('_', ' ', $visit->statut)) }}
                    </span>
                    @if($visit->consultations->first())
                    <a href="{{ route('consultations.show', $visit->consultations->first()) }}"
                       class="text-blue-700 hover:underline text-xs font-medium">Voir →</a>
                    @endif
                </div>
            </div>
        </div>
        @empty
        <div class="px-6 py-8 text-center text-gray-400 text-sm">Aucune consultation enregistrée</div>
        @endforelse
    </div>

</div>
@endsection