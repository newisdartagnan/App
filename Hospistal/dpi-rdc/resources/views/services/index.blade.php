@extends('layouts.app')
@section('title', 'Services d\'hospitalisation')
@section('content')
<div class="max-w-6xl mx-auto px-4 py-6">
    <h2 class="text-2xl font-bold text-gray-800 mb-6">🏥 Services d'hospitalisation</h2>

    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
        @forelse($services as $service)
        @php $taux = $service->capacite_lits > 0 ? min(100, round($service->patients_actuels / $service->capacite_lits * 100)) : 0; @endphp
        <a href="{{ route('services.show', $service) }}" class="bg-white rounded-xl shadow p-5 hover:shadow-md transition block">
            <div class="flex items-center justify-between mb-1">
                <h3 class="font-bold text-blue-900">{{ $service->nom }}</h3>
                <span class="text-xs bg-gray-100 text-gray-600 px-2 py-0.5 rounded-full">{{ $service->code }}</span>
            </div>
            <p class="text-sm text-gray-500 mb-3 capitalize">{{ str_replace('_', ' ', $service->type) }}</p>
            <div class="flex justify-between text-sm mb-2">
                <span class="text-blue-700 font-semibold">{{ $service->patients_actuels }} patient(s)</span>
                <span class="text-gray-500">{{ $service->lits_count }} lit(s)</span>
            </div>
            <div class="h-2 bg-gray-100 rounded-full overflow-hidden">
                <div class="h-full {{ $taux >= 90 ? 'bg-red-600' : ($taux >= 70 ? 'bg-amber-500' : 'bg-blue-600') }}" style="width: {{ $taux }}%"></div>
            </div>
            <p class="text-xs text-gray-400 mt-1">{{ $taux }} % d'occupation</p>
        </a>
        @empty
        <p class="text-gray-400 col-span-3 text-center py-10">Aucun service configuré.</p>
        @endforelse
    </div>
</div>
@endsection
