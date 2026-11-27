@extends('layouts.app')
@section('title', 'Journal — '.$compte->nom)
@section('content')
@include('finance._onglets')
<h3 class="text-xl font-bold mb-2">Journal de trésorerie : {{ $compte->nom }}</h3>
<form method="GET" class="flex flex-wrap items-end gap-2 mb-3 dpi-sans-impression">
    <div><label for="jo-du" class="block text-xs text-gray-600 mb-1">Du</label><input id="jo-du" type="date" name="du" value="{{ $du }}" class="min-h-[44px] border rounded-lg px-3"></div>
    <div><label for="jo-au" class="block text-xs text-gray-600 mb-1">Au</label><input id="jo-au" type="date" name="au" value="{{ $au }}" class="min-h-[44px] border rounded-lg px-3"></div>
    <button class="min-h-[44px] px-4 bg-gray-100 hover:bg-gray-200 rounded-lg">Filtrer</button>
    <button type="button" onclick="window.print()" class="min-h-[44px] px-4 rounded-lg border bg-white">🖨 Imprimer</button>
</form>
<div class="bg-white rounded-xl shadow overflow-x-auto">
    <table class="w-full text-sm">
        <thead class="bg-gray-50 text-left"><tr><th class="px-3 py-2">Date</th><th class="px-3 py-2">Libellé</th><th class="px-3 py-2">Pièce</th><th class="px-3 py-2 text-right">Entrée</th><th class="px-3 py-2 text-right">Sortie</th><th class="px-3 py-2 text-right">Solde</th></tr></thead>
        <tbody class="divide-y">
        @foreach($lignes as $l)
            <tr class="{{ $loop->first ? 'bg-gray-50 font-semibold' : '' }}">
                <td class="px-3 py-2">{{ \Illuminate\Support\Carbon::parse($l->date)->format('d/m/Y') }}</td>
                <td class="px-3 py-2">{{ $l->libelle }}</td><td class="px-3 py-2 font-mono text-xs">{{ $l->piece }}</td>
                <td class="px-3 py-2 text-right text-green-700">{{ $l->entree > 0 ? number_format($l->entree, 0, ',', ' ') : '' }}</td>
                <td class="px-3 py-2 text-right text-red-700">{{ $l->sortie > 0 ? number_format($l->sortie, 0, ',', ' ') : '' }}</td>
                <td class="px-3 py-2 text-right font-semibold">{{ number_format($l->solde, 0, ',', ' ') }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
</div>
@endsection
