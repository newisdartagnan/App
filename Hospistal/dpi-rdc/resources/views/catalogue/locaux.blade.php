@extends('layouts.app')
@section('title', 'Locaux de services')
@section('content')
@include('catalogue._onglets')
<div class="flex flex-wrap items-center justify-between gap-3 mb-4">
    <h2 class="text-2xl font-bold text-gray-800">Locaux de services ({{ $locaux->count() }})</h2>
    <a href="{{ route('catalogue.locaux.create', $serviceId ? ['service' => $serviceId] : []) }}" class="min-h-[44px] inline-flex items-center px-5 bg-blue-700 hover:bg-blue-800 text-white rounded-lg font-semibold">⊕ Nouveau</a>
</div>
<form method="GET" class="mb-4 flex gap-2 items-end">
    <div><label for="l-srv" class="block text-xs text-gray-600 mb-1">Service</label>
        <select id="l-srv" name="service" class="min-h-[44px] border rounded-lg px-3"><option value="">Tous</option>
            @foreach($services as $s)<option value="{{ $s->id }}" @selected($serviceId === $s->id)>{{ $s->nom }}</option>@endforeach</select></div>
    <button class="min-h-[44px] px-4 bg-gray-100 hover:bg-gray-200 rounded-lg">Filtrer</button>
</form>
<div class="bg-white rounded-xl shadow overflow-x-auto">
    <table class="w-full text-sm">
        <thead class="bg-gray-50 text-left text-gray-600"><tr><th class="px-4 py-3">Service</th><th class="px-4 py-3">Local</th><th class="px-4 py-3">Type</th><th class="px-4 py-3">Étage</th><th class="px-4 py-3">Lits</th><th class="px-4 py-3">État</th><th></th></tr></thead>
        <tbody class="divide-y">
        @forelse($locaux as $l)
            <tr class="{{ $l->is_active ? '' : 'opacity-50' }}">
                <td class="px-4 py-2">{{ $l->service?->nom }}</td>
                <td class="px-4 py-2"><a href="{{ route('catalogue.locaux.edit', $l) }}" class="font-semibold text-blue-700 hover:underline">{{ $l->nom }}</a></td>
                <td class="px-4 py-2">{{ \App\Models\Local::TYPES[$l->type] ?? $l->type }}</td>
                <td class="px-4 py-2">{{ $l->etage }}</td>
                <td class="px-4 py-2">{{ $l->lits_count }}</td>
                <td class="px-4 py-2"><span class="px-2 py-0.5 rounded-full text-xs {{ $l->is_active ? 'bg-green-100 text-green-800' : 'bg-gray-200 text-gray-600' }}">{{ $l->is_active ? 'Actif' : 'Désactivé' }}</span></td>
                <td class="px-4 py-2 text-right whitespace-nowrap">
                    <a href="{{ route('catalogue.locaux.edit', $l) }}" class="text-blue-700 hover:underline text-xs mr-2">✎ Modifier</a>
                    <form method="POST" action="{{ route('catalogue.locaux.basculer', $l) }}" class="inline">@csrf<button class="text-xs {{ $l->is_active ? 'text-red-700' : 'text-green-700' }} hover:underline">{{ $l->is_active ? 'Désactiver' : 'Réactiver' }}</button></form>
                </td>
            </tr>
        @empty
            <tr><td colspan="7" class="px-4 py-8 text-center text-gray-400">Aucun local enregistré.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@endsection
