@extends('layouts.app')
@section('title', 'Inventaires')
@section('content')
@include('grand-depot._onglets')
<form method="POST" action="{{ route('grand-depot.inventaires.demarrer') }}" class="bg-white rounded-xl shadow p-4 mb-4 flex flex-wrap items-end gap-3">
    @csrf
    <div class="flex-1"><label for="inv-obs" class="block text-sm font-semibold mb-1">Observations</label><input id="inv-obs" name="observations" placeholder="Inventaire mensuel, de fin d'année…" class="w-full min-h-[44px] border rounded-lg px-3"></div>
    <button class="min-h-[44px] px-5 bg-blue-700 hover:bg-blue-800 text-white rounded-lg font-semibold">🧮 Ouvrir un inventaire</button>
</form>
<div class="bg-white rounded-xl shadow overflow-x-auto">
    <table class="w-full text-sm">
        <thead class="bg-gray-50 text-left text-gray-600"><tr><th class="px-4 py-2">N°</th><th class="px-4 py-2">Date</th><th class="px-4 py-2">Lignes</th><th class="px-4 py-2">Par</th><th class="px-4 py-2">Statut</th></tr></thead>
        <tbody class="divide-y">
        @forelse($inventaires as $inv)
            <tr><td class="px-4 py-2 font-mono text-xs"><a href="{{ route('grand-depot.inventaires.show', $inv) }}" class="text-blue-700 hover:underline">{{ $inv->numero }}</a></td><td class="px-4 py-2">{{ $inv->date_inventaire->format('d/m/Y') }}</td><td class="px-4 py-2">{{ $inv->lignes_count }}</td><td class="px-4 py-2">{{ $inv->auteur?->nom_complet }}</td><td class="px-4 py-2">{{ ['en_cours' => 'En cours', 'valide' => 'Validé', 'annule' => 'Annulé'][$inv->statut] }}</td></tr>
        @empty
            <tr><td colspan="5" class="px-4 py-8 text-center text-gray-400">Aucun inventaire.</td></tr>
        @endforelse
        </tbody>
    </table>
    <div class="px-4 py-3 border-t">{{ $inventaires->links() }}</div>
</div>
@endsection
