@extends('layouts.app')
@section('title', 'Services')
@section('content')
@include('catalogue._onglets')
<div class="flex flex-wrap items-center justify-between gap-3 mb-4">
    <h2 class="text-2xl font-bold text-gray-800">Services / unités de soins ({{ $services->count() }})</h2>
    <a href="{{ route('catalogue.services.create') }}" class="min-h-[44px] inline-flex items-center px-5 bg-blue-700 hover:bg-blue-800 text-white rounded-lg font-semibold">⊕ Nouveau</a>
</div>
<div class="bg-white rounded-xl shadow overflow-x-auto">
    <table class="w-full text-sm">
        <thead class="bg-gray-50 text-left text-gray-600"><tr><th class="px-4 py-3">Code</th><th class="px-4 py-3">Nom</th><th class="px-4 py-3">Type</th><th class="px-4 py-3">Locaux</th><th class="px-4 py-3">Lits</th><th class="px-4 py-3">État</th><th></th></tr></thead>
        <tbody class="divide-y">
        @foreach($services as $s)
            <tr class="{{ $s->is_active ? '' : 'opacity-50' }}">
                <td class="px-4 py-2 font-mono text-xs">{{ $s->code }}</td>
                <td class="px-4 py-2"><a href="{{ route('catalogue.services.edit', $s) }}" class="font-semibold text-blue-700 hover:underline">{{ $s->nom }}</a></td>
                <td class="px-4 py-2">{{ ucfirst($s->type) }}</td>
                <td class="px-4 py-2"><a href="{{ route('catalogue.locaux.index', ['service' => $s->id]) }}" class="text-blue-700 hover:underline">{{ $s->locaux_count }}</a></td>
                <td class="px-4 py-2"><a href="{{ route('catalogue.lits.index', ['service' => $s->id]) }}" class="text-blue-700 hover:underline">{{ $s->lits_count }}</a></td>
                <td class="px-4 py-2"><span class="px-2 py-0.5 rounded-full text-xs {{ $s->is_active ? 'bg-green-100 text-green-800' : 'bg-gray-200 text-gray-600' }}">{{ $s->is_active ? 'Actif' : 'Désactivé' }}</span></td>
                <td class="px-4 py-2 text-right whitespace-nowrap">
                    <a href="{{ route('catalogue.services.edit', $s) }}" class="text-blue-700 hover:underline text-xs mr-2">✎ Modifier</a>
                    <form method="POST" action="{{ route('catalogue.services.basculer', $s) }}" class="inline">@csrf<button class="text-xs {{ $s->is_active ? 'text-red-700' : 'text-green-700' }} hover:underline">{{ $s->is_active ? 'Désactiver' : 'Réactiver' }}</button></form>
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>
</div>
@endsection
