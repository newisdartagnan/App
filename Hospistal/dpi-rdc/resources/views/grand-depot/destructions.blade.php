@extends('layouts.app')
@section('title', 'Périmés et destructions')
@section('content')
@include('grand-depot._onglets')
<h3 class="text-xl font-semibold mb-2">Lots périmés ou à péremption proche (≤ {{ \App\Services\GrandDepotService::JOURS_PEREMPTION }} jours)</h3>
@if($peremptions->isEmpty())
<p class="text-sm text-gray-500 mb-6">Aucun lot concerné.</p>
@else
<form method="POST" action="{{ route('grand-depot.destructions.store') }}" class="space-y-3 mb-6">
    @csrf
    <div class="bg-white rounded-xl shadow overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-left"><tr><th class="px-3 py-2">Produit</th><th class="px-3 py-2">Lot</th><th class="px-3 py-2">Péremption</th><th class="px-3 py-2 text-right">Stock</th><th class="px-3 py-2 text-right">Valeur</th><th class="px-3 py-2">À détruire</th></tr></thead>
            <tbody class="divide-y">
            @foreach($peremptions as $s)
                <tr class="{{ $s->date_peremption->isPast() ? 'bg-red-50' : '' }}">
                    <td class="px-3 py-1">{{ $s->medicament?->denomination_commune }} {{ $s->medicament?->dosage }}</td>
                    <td class="px-3 py-1">{{ $s->lot ?? '—' }}</td>
                    <td class="px-3 py-1 {{ $s->date_peremption->isPast() ? 'text-red-700 font-semibold' : 'text-amber-700' }}">{{ $s->date_peremption->format('d/m/Y') }} {{ $s->date_peremption->isPast() ? '(périmé)' : '' }}</td>
                    <td class="px-3 py-1 text-right">{{ $s->quantite_disponible + 0 }}</td>
                    <td class="px-3 py-1 text-right">{{ number_format((float) $s->quantite_disponible * (float) $s->prix_unitaire_achat, 0, ',', ' ') }}</td>
                    <td class="px-3 py-1"><label class="sr-only" for="d-{{ $s->id }}">Quantité à détruire</label><input id="d-{{ $s->id }}" name="quantites[{{ $s->id }}]" type="number" step="0.01" min="0" max="{{ $s->quantite_disponible }}" value="{{ $s->date_peremption->isPast() ? $s->quantite_disponible + 0 : '' }}" class="w-24 min-h-[36px] border rounded px-2 text-right"></td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
    <div class="bg-white rounded-xl shadow p-4 grid md:grid-cols-3 gap-3 items-end">
        <div><label for="d-motif" class="block text-sm font-semibold mb-1">Motif <span class="text-red-500">*</span></label><input id="d-motif" name="motif" required value="Produits périmés" class="w-full min-h-[44px] border rounded-lg px-3"></div>
        <div><label for="d-temoins" class="block text-sm font-semibold mb-1">Témoins</label><input id="d-temoins" name="temoins" placeholder="Noms et fonctions" class="w-full min-h-[44px] border rounded-lg px-3"></div>
        <div><button class="min-h-[44px] px-5 bg-red-700 hover:bg-red-800 text-white rounded-lg font-semibold">Établir le procès-verbal de destruction</button></div>
    </div>
</form>
@endif
<h3 class="text-xl font-semibold mb-2">Procès-verbaux</h3>
<div class="bg-white rounded-xl shadow overflow-x-auto">
    <table class="w-full text-sm">
        <thead class="bg-gray-50 text-left"><tr><th class="px-4 py-2">N°</th><th class="px-4 py-2">Date</th><th class="px-4 py-2">Motif</th><th class="px-4 py-2">Lignes</th><th class="px-4 py-2 text-right">Valeur</th></tr></thead>
        <tbody class="divide-y">
        @forelse($destructions as $d)
            <tr><td class="px-4 py-2 font-mono text-xs"><a href="{{ route('grand-depot.destructions.show', $d) }}" class="text-blue-700 hover:underline">{{ $d->numero }}</a></td><td class="px-4 py-2">{{ $d->date_destruction->format('d/m/Y') }}</td><td class="px-4 py-2">{{ $d->motif }}</td><td class="px-4 py-2">{{ $d->lignes_count }}</td><td class="px-4 py-2 text-right">{{ number_format((float) $d->valeur_totale, 0, ',', ' ') }}</td></tr>
        @empty
            <tr><td colspan="5" class="px-4 py-6 text-center text-gray-400">Aucun procès-verbal.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@endsection
