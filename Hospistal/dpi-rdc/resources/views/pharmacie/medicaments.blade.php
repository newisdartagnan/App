@extends('layouts.app')
@section('title', 'Médicaments')
@section('content')
<div class="max-w-6xl mx-auto px-4 py-6">
    <div class="flex items-center justify-between mb-6">
        <h2 class="text-2xl font-bold">Catalogue médicaments</h2>
        <a href="{{ route('pharmacie.stock') }}" class="text-blue-700 text-sm">← Stock</a>
    </div>
    <livewire:pharmacie.medicament-form />
</div>
@endsection
