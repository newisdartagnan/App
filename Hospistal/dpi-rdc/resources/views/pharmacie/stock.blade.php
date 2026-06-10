@extends('layouts.app')
@section('title', 'Stock médicaments')
@section('content')
<div class="max-w-7xl mx-auto px-4 py-6">
    <div class="flex items-center justify-between mb-6">
        <div class="flex items-center gap-3">
            <a href="{{ route('pharmacie.dashboard') }}" class="text-blue-700 hover:underline text-sm">← Pharmacie</a>
            <h2 class="text-2xl font-bold text-gray-800">Stock & médicaments</h2>
        </div>
    </div>
    @if(session('success'))
    <div class="bg-green-50 border border-green-200 text-green-800 rounded-lg px-4 py-3 mb-4">{{ session('success') }}</div>
    @endif
    <livewire:pharmacie.medicament-form />
    <div class="mt-4">
        <livewire:pharmacie.stock-dashboard />
    </div>
</div>
@endsection