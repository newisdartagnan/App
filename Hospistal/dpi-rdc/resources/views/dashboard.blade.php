@extends('layouts.app')

@section('title', 'Tableau de bord')

@section('content')
<div class="space-y-6">
    <h2 class="text-2xl font-bold text-gray-800">Accueil</h2>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div class="bg-blue-50 border border-blue-200 rounded-xl p-6">
            <p class="text-sm text-blue-600 font-medium">Consultations du jour</p>
            <p class="text-3xl font-bold text-blue-900 mt-2">{{ $stats['consultations'] ?? 0 }}</p>
        </div>
        <div class="bg-green-50 border border-green-200 rounded-xl p-6">
            <p class="text-sm text-green-600 font-medium">Admissions du jour</p>
            <p class="text-3xl font-bold text-green-900 mt-2">{{ $stats['admissions'] ?? 0 }}</p>
        </div>
        <div class="bg-purple-50 border border-purple-200 rounded-xl p-6">
            <p class="text-sm text-purple-600 font-medium">Lits occupés</p>
            <p class="text-3xl font-bold text-purple-900 mt-2">{{ $stats['lits_occupes'] ?? 0 }}</p>
        </div>
    </div>

    <div class="bg-white border rounded-xl p-6 shadow-sm">
        <h3 class="text-lg font-semibold mb-4">Recherche patient</h3>
        <livewire:patient-search />
    </div>
</div>
@endsection
