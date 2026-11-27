@extends('layouts.app')
@section('title', 'PV '.$pv->numero)
@section('content')
@include('grand-depot._onglets')
<div class="bg-white rounded-xl shadow p-6">
    <div class="flex justify-between items-start mb-4">
        <div><h3 class="text-xl font-bold uppercase">Procès-verbal de destruction</h3><p class="text-sm">N° {{ $pv->numero }} — {{ $pv->date_destruction->format('d/m/Y') }} — {{ config('dpi.establishment_name') }}</p></div>
        <button type="button" onclick="window.print()" class="min-h-[40px] px-4 rounded-lg border bg-white dpi-sans-impression">🖨 Imprimer</button>
    </div>
    <p class="text-sm mb-3"><strong>Motif :</strong> {{ $pv->motif }}<br><strong>Témoins :</strong> {{ $pv->temoins ?? '—' }}<br><strong>Établi par :</strong> {{ $pv->auteur?->nom_complet }}</p>
    <table class="w-full text-sm border">
        <thead class="bg-gray-50 text-left"><tr><th class="px-3 py-2">Produit</th><th class="px-3 py-2">Lot</th><th class="px-3 py-2">Péremption</th><th class="px-3 py-2 text-right">Quantité</th><th class="px-3 py-2 text-right">PU achat</th><th class="px-3 py-2 text-right">Valeur</th></tr></thead>
        <tbody class="divide-y">
        @foreach($pv->lignes as $l)
            <tr><td class="px-3 py-2">{{ $l->medicament?->denomination_commune }} {{ $l->medicament?->dosage }}</td><td class="px-3 py-2">{{ $l->lot ?? '—' }}</td><td class="px-3 py-2">{{ $l->date_peremption?->format('d/m/Y') ?? '—' }}</td><td class="px-3 py-2 text-right">{{ $l->quantite + 0 }}</td><td class="px-3 py-2 text-right">{{ number_format((float) $l->prix_unitaire_achat, 2, ',', ' ') }}</td><td class="px-3 py-2 text-right">{{ number_format((float) $l->quantite * (float) $l->prix_unitaire_achat, 2, ',', ' ') }}</td></tr>
        @endforeach
        </tbody>
        <tfoot class="bg-gray-50 font-semibold"><tr><td colspan="5" class="px-3 py-2">Valeur totale détruite</td><td class="px-3 py-2 text-right">{{ number_format((float) $pv->valeur_totale, 2, ',', ' ') }} FC</td></tr></tfoot>
    </table>
    <div class="grid grid-cols-3 gap-6 mt-12 text-sm text-center"><div class="border-t pt-2">Le pharmacien</div><div class="border-t pt-2">Le directeur</div><div class="border-t pt-2">Les témoins</div></div>
</div>
@endsection
