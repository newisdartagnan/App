@extends('layouts.app')
@section('title', 'Médicaments')
@section('content')
<div class="max-w-7xl mx-auto px-4 py-6">
    <div class="flex items-center justify-between mb-6">
        <h2 class="text-2xl font-bold">Catalogue médicaments</h2>
        <a href="{{ route('pharmacie.stock') }}" class="text-blue-700 text-sm">← Stock</a>
    </div>
    @if(session('success'))<div class="bg-green-50 border border-green-200 text-green-800 rounded-lg px-4 py-3 mb-4">{{ session('success') }}</div>@endif
    @if(session('error'))<div class="bg-red-50 border border-red-200 text-red-800 rounded-lg px-4 py-3 mb-4">{{ session('error') }}</div>@endif
    @include('pharmacie._medicament-form')
    @include('pharmacie._stock-table')
</div>
@endsection
