@extends('layouts.app')
@section('title', 'Catalogue des actes')
@section('content')
@include('tarification._onglets')
<div class="flex flex-wrap items-center justify-between gap-3 mb-4">
    <h2 class="text-2xl font-bold text-gray-800">Catalogue des actes ({{ $actes->total() }})</h2>
    <a href="{{ route('tarification.actes.create') }}" class="min-h-[44px] inline-flex items-center px-5 bg-blue-700 hover:bg-blue-800 text-white rounded-lg font-semibold">⊕ Nouvel acte</a>
</div>
<form method="GET" class="flex flex-wrap gap-2 items-end mb-4">
    <div class="flex-1 min-w-[14rem]"><label for="ac-r" class="block text-xs text-gray-600 mb-1">Libellé ou identifiant</label><input id="ac-r" name="recherche" value="{{ $recherche }}" class="w-full min-h-[44px] border rounded-lg px-3"></div>
    <div><label for="ac-s" class="block text-xs text-gray-600 mb-1">Spécialité</label>
        <select id="ac-s" name="specialite" class="min-h-[44px] border rounded-lg px-3"><option value="">Toutes</option>@foreach($specialites as $s)<option value="{{ $s }}" @selected($specialite === $s)>{{ $s }}</option>@endforeach</select></div>
    <button class="min-h-[44px] px-4 bg-gray-100 hover:bg-gray-200 rounded-lg">Filtrer</button>
</form>
<div class="bg-white rounded-xl shadow overflow-x-auto">
    <table class="w-full text-sm">
        <thead class="bg-gray-50 text-left text-gray-600"><tr><th class="px-3 py-2">Id</th><th class="px-3 py-2">Libellé</th><th class="px-3 py-2">Spécialité</th><th class="px-3 py-2 text-center">Fonction</th><th class="px-3 py-2 text-right">Normal</th><th class="px-3 py-2 text-right">Urgent</th><th class="px-3 py-2 text-right">Externe</th><th class="px-3 py-2">Disponible</th></tr></thead>
        <tbody class="divide-y">
        @forelse($actes as $a)
            <tr class="{{ $a->estDisponible() ? '' : 'text-gray-500' }}">
                <td class="px-3 py-2 font-mono text-xs">{{ $a->numero }}</td>
                <td class="px-3 py-2"><a href="{{ route('tarification.actes.edit', $a) }}" class="text-blue-700 hover:underline font-medium">{{ $a->libelle }}</a><div class="text-xs text-gray-500">{{ $a->categorie_acte }}</div></td>
                <td class="px-3 py-2">{{ $a->specialite }}</td>
                <td class="px-3 py-2 text-center">{{ $a->fonction_tarifaire }}</td>
                <td class="px-3 py-2 text-right">{{ number_format((float) $a->prix_normal, 2, ',', ' ') }} {{ $a->devise === 'USD' ? '$' : 'FC' }}</td>
                <td class="px-3 py-2 text-right">{{ $a->prix_urgent !== null ? number_format((float) $a->prix_urgent, 2, ',', ' ') : '—' }}</td>
                <td class="px-3 py-2 text-right">{{ $a->prix_externe !== null ? number_format((float) $a->prix_externe, 2, ',', ' ') : '—' }}</td>
                <td class="px-3 py-2"><span class="px-2 py-0.5 rounded-full text-xs {{ $a->estDisponible() ? 'bg-green-100 text-green-800' : ($a->disponibilite === 'non_temporaire' ? 'bg-amber-100 text-amber-800' : 'bg-gray-200 text-gray-700') }}">{{ \App\Models\ActeCatalogue::DISPONIBILITES[$a->disponibilite] }}</span></td>
            </tr>
        @empty
            <tr><td colspan="8" class="px-3 py-8 text-center text-gray-400">Aucun acte au catalogue.</td></tr>
        @endforelse
        </tbody>
    </table>
    <div class="px-4 py-3 border-t">{{ $actes->links() }}</div>
</div>
@endsection
