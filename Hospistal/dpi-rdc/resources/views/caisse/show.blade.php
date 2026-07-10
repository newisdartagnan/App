@extends('layouts.app')
@section('title', 'Facture')
@section('content')
<div class="max-w-4xl mx-auto px-4 py-6">
    <div class="flex items-center gap-3 mb-6">
        <a href="{{ route('caisse.index') }}" class="text-blue-700 hover:underline text-sm">← Caisse</a>
        <h2 class="text-2xl font-bold text-gray-800">Facture {{ $facture->numero_facture }}</h2>
    </div>
    @if(session('success'))
    <div class="bg-green-50 border border-green-200 text-green-800 rounded-lg px-4 py-3 mb-4">
        {{ session('success') }}
    </div>
    @endif
    <livewire:caisse.facture-show :facture="$facture" />
</div>
@endsection