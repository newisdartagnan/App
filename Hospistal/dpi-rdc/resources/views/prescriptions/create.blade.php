@extends('layouts.app')
@section('title', 'Ordonnance')
@section('content')
<div class="max-w-4xl mx-auto px-4 py-6">
    <div class="flex items-center gap-3 mb-6">
        <a href="{{ route('consultations.show', $consultation) }}"
           class="text-blue-700 hover:underline text-sm">← Consultation</a>
        <h2 class="text-2xl font-bold text-gray-800">Ordonnance</h2>
        <span class="text-sm text-gray-500 bg-gray-100 px-3 py-1 rounded-full">
            {{ $consultation->visit->patient->nom_complet }}
        </span>
    </div>

    {{-- Rappel allergies --}}
    @if($consultation->allergies)
    <div class="bg-red-50 border border-red-300 rounded-xl px-4 py-3 mb-4 text-sm">
        <span class="font-semibold text-red-700">⚠️ Allergies connues :</span>
        <span class="text-red-800 ml-1">{{ $consultation->allergies }}</span>
    </div>
    @endif

    <livewire:prescriptions.prescription-create :consultation="$consultation" />
</div>
@endsection