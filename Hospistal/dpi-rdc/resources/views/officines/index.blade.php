@extends('layouts.app')
@section('title', 'Pharmacie — officines')
@section('content')
<div class="max-w-5xl mx-auto px-4 py-6">
    <div class="flex items-center justify-between mb-6 flex-wrap gap-3">
        <h2 class="text-2xl font-bold text-gray-800">💊 Pharmacie — choix de l'officine</h2>
        <a href="{{ route('officines.depot') }}" class="text-sm text-blue-700 hover:underline">Dépôt central →</a>
    </div>

    @foreach(['success','error'] as $t)
        @if(session($t))
        <div class="mb-4 rounded-lg px-4 py-3 text-sm border {{ $t==='success' ? 'bg-green-50 border-green-200 text-green-800' : 'bg-red-50 border-red-200 text-red-800' }}">{{ session($t) }}</div>
        @endif
    @endforeach

    <p class="text-sm text-gray-600 mb-4">
        Le choix de l'officine est un préalable : aucun stock n'est affiché tant qu'elle n'est pas sélectionnée.
        @if($active)<span class="ml-1 font-semibold text-blue-800">Officine active : {{ $active->nom }}.</span>@endif
    </p>

    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
        @foreach($officines as $officine)
        @php
            $icone = match($officine->type) {
                'depot_central' => '🏛', 'ambulatoire' => '🚶', default => '🛏',
            };
            $estActive = $active && $active->id === $officine->id;
        @endphp
        <div class="bg-white rounded-xl shadow p-5 {{ $estActive ? 'ring-2 ring-blue-600' : '' }}">
            <div class="flex items-start justify-between mb-2">
                <h3 class="font-bold text-blue-900">{{ $icone }} {{ $officine->nom }}</h3>
                @if($estActive)<span class="text-[10px] font-bold bg-blue-600 text-white px-2 py-0.5 rounded">ACTIVE</span>@endif
            </div>
            <p class="text-xs text-gray-500 mb-4">
                {{ match($officine->type) {
                    'depot_central' => 'Dépôt central — approvisionne les officines',
                    'ambulatoire' => 'Délivrance aux patients ambulatoires',
                    default => 'Officine de service' . ($officine->service ? ' — ' . $officine->service->nom : ''),
                } }}
            </p>
            <form method="POST" action="{{ route('officines.activer', $officine) }}">
                @csrf
                <button class="w-full bg-blue-700 hover:bg-blue-800 text-white text-sm py-2 rounded-lg font-semibold">
                    {{ $estActive ? 'Ouvrir le stock' : 'Travailler sur cette officine' }}
                </button>
            </form>
        </div>
        @endforeach
    </div>
</div>
@endsection
