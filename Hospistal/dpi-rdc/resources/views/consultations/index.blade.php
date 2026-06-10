@extends('layouts.app')
@section('title', 'Consultations')
@section('content')
<div class="max-w-7xl mx-auto px-4 py-6">
    <div class="flex items-center justify-between mb-6">
        <h2 class="text-2xl font-bold text-gray-800">Consultations</h2>
        <a href="{{ route('patients.index') }}"
           class="bg-blue-700 hover:bg-blue-800 text-white font-semibold px-5 py-2 rounded-lg transition">
            + Nouvelle consultation
        </a>
    </div>
    <livewire:consultations.consultation-list />
</div>
@endsection