@extends('layouts.app')
@section('title', 'Réception '.$reception->numero)
@section('content')
@include('grand-depot._onglets')
<div class="flex flex-wrap items-center justify-between gap-3 mb-3">
    <div><h3 class="text-xl font-bold">Réception {{ $reception->numero }}</h3>
        <p class="text-sm text-gray-600">{{ \App\Models\ReceptionStock::ORIGINES[$reception->origine] }} — {{ $reception->fournisseur?->nom ?? 'sans fournisseur' }} — {{ $reception->date_reception->format('d/m/Y') }} @if($reception->commande)— commande <a href="{{ route('grand-depot.commandes.show', $reception->commande) }}" class="text-blue-700 hover:underline">{{ $reception->commande->numero }}</a>@endif</p>
        <p class="text-xs text-gray-500">BL : {{ $reception->numero_bon_livraison ?? '—' }} · Facture : {{ $reception->numero_facture_fournisseur ?? '—' }} · Réceptionné par {{ $reception->auteur?->nom_complet }}</p></div>
    <button type="button" onclick="window.print()" class="min-h-[40px] px-4 rounded-lg border bg-white dpi-sans-impression">🖨 Imprimer</button>
</div>
<div class="bg-white rounded-xl shadow overflow-x-auto">
    <table class="w-full text-sm">
        <thead class="bg-gray-50 text-left"><tr><th class="px-3 py-2">Produit</th><th class="px-3 py-2 text-right">Quantité</th><th class="px-3 py-2">Lot</th><th class="px-3 py-2">Péremption</th><th class="px-3 py-2 text-right">PU achat</th><th class="px-3 py-2 text-right">Montant</th></tr></thead>
        <tbody class="divide-y">
        @foreach($reception->lignes as $l)
            <tr><td class="px-3 py-2">{{ $l->medicament?->denomination_commune }} {{ $l->medicament?->dosage }}</td><td class="px-3 py-2 text-right">{{ $l->quantite + 0 }}</td><td class="px-3 py-2">{{ $l->lot ?? '—' }}</td><td class="px-3 py-2">{{ $l->date_peremption?->format('d/m/Y') ?? '—' }}</td>
                <td class="px-3 py-2 text-right">{{ $l->prix_unitaire_achat !== null ? number_format((float) $l->prix_unitaire_achat, 2, ',', ' ') : '—' }}</td><td class="px-3 py-2 text-right">{{ number_format((float) $l->quantite * (float) $l->prix_unitaire_achat, 2, ',', ' ') }}</td></tr>
        @endforeach
        </tbody>
        <tfoot class="bg-gray-50 font-semibold"><tr><td colspan="5" class="px-3 py-2">Total</td><td class="px-3 py-2 text-right">{{ number_format((float) $reception->montant_total, 2, ',', ' ') }} {{ $reception->devise }}</td></tr></tfoot>
    </table>
</div>
@if(Route::has('finance.depenses.create') && $reception->resteAPayer() > 0)
<a href="{{ route('finance.depenses.create', ['reception' => $reception->id]) }}" class="mt-3 inline-flex items-center min-h-[44px] px-5 bg-blue-700 text-white rounded-lg font-semibold dpi-sans-impression">💸 Payer le fournisseur ({{ number_format($reception->resteAPayer(), 2, ',', ' ') }} {{ $reception->devise }})</a>
@endif
@endsection
