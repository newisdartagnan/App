@extends('layouts.app')

@section('title', 'Accès rapides')

@section('content')
<div class="space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex items-center gap-3">
            <h2 class="text-2xl font-bold text-gray-800">Accès rapides</h2>
            <span class="px-2 py-1 rounded-full bg-gray-600 text-white text-xs font-semibold">{{ auth()->user()->libelleRoles() }}</span>
        </div>
        <p class="text-sm text-gray-600">📅 {{ now()->format('d/m/Y') }} &nbsp; 🕒 {{ now()->format('H:i') }}</p>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div class="bg-blue-50 border border-blue-200 rounded-xl p-5">
            <p class="text-sm text-blue-600 font-medium">Consultations du jour</p>
            <p class="text-3xl font-bold text-blue-900 mt-1">{{ $stats['consultations'] ?? 0 }}</p>
        </div>
        <div class="bg-green-50 border border-green-200 rounded-xl p-5">
            <p class="text-sm text-green-600 font-medium">Admissions du jour</p>
            <p class="text-3xl font-bold text-green-900 mt-1">{{ $stats['admissions'] ?? 0 }}</p>
        </div>
        <div class="bg-purple-50 border border-purple-200 rounded-xl p-5">
            <p class="text-sm text-purple-600 font-medium">Lits occupés</p>
            <p class="text-3xl font-bold text-purple-900 mt-1">{{ $stats['lits_occupes'] ?? 0 }}</p>
        </div>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        @foreach($tuiles as $t)
        <div class="bg-white border rounded-xl shadow-sm p-4 flex items-center gap-4">
            <span class="text-4xl" aria-hidden="true">{{ $t->icone }}</span>
            <div class="min-w-0">
                <h3 class="font-semibold text-gray-800">
                    {{ $t->titre }}
                    @if($t->titre === 'Mes tâches' && $nbTaches > 0)
                    <span class="ml-1 px-2 py-0.5 rounded-full bg-red-600 text-white text-xs">{{ $nbTaches }}</span>
                    @endif
                </h3>
                <div class="flex flex-wrap gap-2 mt-2">
                    @if($t->nouveau)
                    <a href="{{ $t->nouveau }}" class="inline-flex items-center min-h-[40px] px-3 rounded-lg border text-sm text-blue-700 hover:bg-blue-50">⊕ {{ $t->titre === 'Mes tâches' ? 'Créer' : 'Nouveau' }}</a>
                    @endif
                    @if($t->gerer)
                    <a href="{{ $t->gerer }}" class="inline-flex items-center min-h-[40px] px-3 rounded-lg border text-sm text-blue-700 hover:bg-blue-50">Gérer →</a>
                    @endif
                </div>
            </div>
        </div>
        @endforeach
    </div>

    @if($services->isNotEmpty())
    <div>
        <h3 class="text-2xl font-bold text-blue-800 mb-3">Services</h3>
        <div class="flex flex-wrap gap-2">
            @foreach($services as $s)
            <a href="{{ route('services.show', $s) }}" class="inline-flex items-center min-h-[40px] px-4 rounded-lg border bg-white text-sm text-blue-700 hover:bg-blue-50">{{ $s->nom }} →</a>
            @endforeach
        </div>
    </div>
    @endif

    <div class="bg-white border rounded-xl p-6 shadow-sm">
        <h3 class="text-lg font-semibold mb-4">Recherche patient</h3>
        @include('partials.recherche-patient')
    </div>
</div>
@endsection
