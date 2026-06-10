@extends('layouts.app')
@section('title', 'Nouveau patient')
@section('content')
<div class="max-w-4xl mx-auto px-4 py-6">
    <div class="flex items-center gap-3 mb-6">
        <a href="{{ route('patients.index') }}" class="text-blue-700 hover:underline text-sm">← Retour</a>
        <h2 class="text-2xl font-bold text-gray-800">Nouveau patient</h2>
    </div>
    <livewire:patients.patient-create />
</div>
@endsection