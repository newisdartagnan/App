@extends('layouts.app')
@section('title', $domaine === 'imagerie' ? 'Techniques d\'imagerie' : 'Examens de laboratoire')
@section('content')
@include('catalogue._onglets')
<div class="flex flex-wrap items-center justify-between gap-3 mb-4">
    <h2 class="text-2xl font-bold text-gray-800">{{ $domaine === 'imagerie' ? 'Techniques d\'imagerie' : 'Examens de laboratoire' }} ({{ $types->total() }})</h2>
    <a href="{{ route($domaine === 'imagerie' ? 'catalogue.imagerie.create' : 'catalogue.examens.create') }}" class="min-h-[44px] inline-flex items-center px-5 bg-blue-700 hover:bg-blue-800 text-white rounded-lg font-semibold">⊕ Nouveau</a>
</div>
<form method="GET" class="mb-4 flex gap-2">
    <label for="c-rech" class="sr-only">Rechercher</label>
    <input id="c-rech" name="recherche" value="{{ $recherche }}" placeholder="Rechercher par désignation" class="flex-1 min-h-[44px] border rounded-lg px-3">
    <button class="min-h-[44px] px-4 bg-gray-100 hover:bg-gray-200 rounded-lg">🔎</button>
</form>
<div class="bg-white rounded-xl shadow overflow-x-auto">
    <table class="w-full text-sm">
        <thead class="bg-gray-50 text-left text-gray-600"><tr>
            <th class="px-4 py-3">Code</th><th class="px-4 py-3">Désignation</th>
            <th class="px-4 py-3">{{ $domaine === 'imagerie' ? 'Modalité' : 'Catégorie' }}</th>
            @if($domaine === 'labo')<th class="px-4 py-3">Paramètres</th>@endif
            <th class="px-4 py-3 text-right">Prix</th><th class="px-4 py-3">État</th><th></th>
        </tr></thead>
        <tbody class="divide-y">
        @forelse($types as $t)
            <tr class="{{ $t->est_actif ? '' : 'opacity-50' }}">
                <td class="px-4 py-2 font-mono text-xs">{{ $t->code }}</td>
                <td class="px-4 py-2"><a href="{{ route('catalogue.examens.edit', $t) }}" class="font-semibold text-blue-700 hover:underline">{{ $t->libelle }}</a></td>
                <td class="px-4 py-2">{{ $domaine === 'imagerie' ? $t->libelleModalite() : (\App\Models\TypeExamen::UNITES[$t->categorie] ?? $t->categorie) }}</td>
                @if($domaine === 'labo')<td class="px-4 py-2">{{ count($t->valeurs_reference['parametres'] ?? []) }}</td>@endif
                <td class="px-4 py-2 text-right">{{ number_format((float) $t->prix, 0, ',', ' ') }}</td>
                <td class="px-4 py-2"><span class="px-2 py-0.5 rounded-full text-xs {{ $t->est_actif ? 'bg-green-100 text-green-800' : 'bg-gray-200 text-gray-600' }}">{{ $t->est_actif ? 'Actif' : 'Désactivé' }}</span></td>
                <td class="px-4 py-2 text-right whitespace-nowrap">
                    <a href="{{ route('catalogue.examens.edit', $t) }}" class="text-blue-700 hover:underline text-xs mr-2">✎ Modifier</a>
                    <form method="POST" action="{{ route('catalogue.examens.basculer', $t) }}" class="inline">@csrf
                        <button class="text-xs {{ $t->est_actif ? 'text-red-700' : 'text-green-700' }} hover:underline">{{ $t->est_actif ? 'Désactiver' : 'Réactiver' }}</button></form>
                </td>
            </tr>
        @empty
            <tr><td colspan="7" class="px-4 py-8 text-center text-gray-400">Aucun élément.</td></tr>
        @endforelse
        </tbody>
    </table>
    <div class="px-4 py-3 border-t">{{ $types->links() }}</div>
</div>
@endsection
