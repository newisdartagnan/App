@extends('layouts.app')
@section('title', 'Nouvelle consultation')
@section('content')
<div class="max-w-4xl mx-auto px-4 py-6">
    <div class="flex items-center gap-3 mb-6">
        <a href="{{ route('patients.show', $patient) }}" class="text-blue-700 hover:underline text-sm">← Retour</a>
        <h2 class="text-2xl font-bold text-gray-800">Consultation — {{ $patient->nom_complet }}</h2>
        <span class="text-sm text-gray-500 bg-gray-100 px-3 py-1 rounded-full">{{ $patient->dossier_number }}</span>
    </div>
    <livewire:consultations.consultation-create :patient="$patient" />
</div>
@endsection