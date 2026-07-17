@extends('layouts.app')
@section('title', 'Pharmacie')
@section('content')
<div class="max-w-7xl mx-auto px-4 py-6">
    <div class="flex items-center justify-between mb-6">
        <h2 class="text-2xl font-bold text-gray-800">Pharmacie</h2>
        <div class="flex gap-3">
            <a href="{{ route('pharmacie.stock') }}"
               class="bg-blue-700 hover:bg-blue-800 text-white font-semibold px-4 py-2 rounded-lg transition text-sm">
                Stock & médicaments
            </a>
            <a href="{{ route('pharmacie.prescriptions') }}"
               class="bg-green-600 hover:bg-green-700 text-white font-semibold px-4 py-2 rounded-lg transition text-sm">
                Ordonnances
            </a>
        </div>
    </div>
    @if(session('success'))<div class="bg-green-50 border border-green-200 text-green-800 rounded-lg px-4 py-3 mb-4">{{ session('success') }}</div>@endif
    @if(session('error'))<div class="bg-red-50 border border-red-200 text-red-800 rounded-lg px-4 py-3 mb-4">{{ session('error') }}</div>@endif
    @include('pharmacie._stock-table')
</div>
@endsection
