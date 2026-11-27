@extends('layouts.app')
@section('title', 'Bon de commande '.$commande->numero)
@section('content')
@include('grand-depot._onglets')
<div class="flex flex-wrap items-center justify-between gap-3 mb-3">
    <div>
        <h3 class="text-xl font-bold">Bon de commande {{ $commande->numero }}</h3>
        <p class="text-sm text-gray-600">{{ $commande->fournisseur?->nom }} — du {{ $commande->date_commande->format('d/m/Y') }} — <strong>{{ \App\Models\CommandeFournisseur::STATUTS[$commande->statut] }}</strong></p>
    </div>
    <div class="flex gap-2 dpi-sans-impression">
        <button type="button" onclick="window.print()" class="min-h-[40px] px-4 rounded-lg border bg-white">🖨 Imprimer</button>
        @if($commande->statut === 'brouillon')
        <form method="POST" action="{{ route('grand-depot.commandes.envoyer', $commande) }}">@csrf<button class="min-h-[40px] px-4 bg-blue-700 text-white rounded-lg font-semibold">📤 Envoyer au fournisseur</button></form>
        @endif
        @if(in_array($commande->statut, ['brouillon', 'envoyee'], true))
        <form method="POST" action="{{ route('grand-depot.commandes.annuler', $commande) }}" onsubmit="return confirm('Annuler cette commande ?');">@csrf<button class="min-h-[40px] px-4 rounded-lg border border-red-300 text-red-700">Annuler</button></form>
        @endif
    </div>
</div>

@if($commande->peutRecevoir())
<form method="POST" action="{{ route('grand-depot.commandes.recevoir', $commande) }}" class="space-y-3">
    @csrf
    <div class="bg-white rounded-xl shadow p-4 grid md:grid-cols-4 gap-3 dpi-sans-impression">
        <div><label for="r-date" class="block text-sm font-semibold mb-1">Date de réception</label><input id="r-date" type="date" name="date_reception" value="{{ today()->toDateString() }}" max="{{ today()->toDateString() }}" required class="w-full min-h-[44px] border rounded-lg px-3"></div>
        <div><label for="r-bl" class="block text-sm font-semibold mb-1">N° bon de livraison</label><input id="r-bl" name="numero_bon_livraison" class="w-full min-h-[44px] border rounded-lg px-3"></div>
        <div><label for="r-fac" class="block text-sm font-semibold mb-1">N° facture fournisseur</label><input id="r-fac" name="numero_facture_fournisseur" class="w-full min-h-[44px] border rounded-lg px-3"></div>
    </div>
@endif
<div class="bg-white rounded-xl shadow overflow-x-auto">
    <table class="w-full text-sm">
        <thead class="bg-gray-50 text-left text-gray-600"><tr>
            <th class="px-3 py-2">Produit</th><th class="px-3 py-2 text-right">Commandé</th><th class="px-3 py-2 text-right">Reçu</th><th class="px-3 py-2 text-right">PU</th><th class="px-3 py-2 text-right">Montant</th>
            @if($commande->peutRecevoir())<th class="px-3 py-2 dpi-sans-impression">Reçu maintenant</th><th class="px-3 py-2 dpi-sans-impression">Lot</th><th class="px-3 py-2 dpi-sans-impression">Péremption</th><th class="px-3 py-2 dpi-sans-impression">PU achat</th>@endif
        </tr></thead>
        <tbody class="divide-y">
        @foreach($commande->lignes as $i => $l)
            <tr>
                <td class="px-3 py-2">{{ $l->medicament?->denomination_commune }} {{ $l->medicament?->dosage }}</td>
                <td class="px-3 py-2 text-right">{{ $l->quantite_commandee + 0 }}</td>
                <td class="px-3 py-2 text-right">{{ $l->quantite_recue + 0 }}</td>
                <td class="px-3 py-2 text-right">{{ $l->prix_unitaire !== null ? number_format((float) $l->prix_unitaire, 2, ',', ' ') : '—' }}</td>
                <td class="px-3 py-2 text-right">{{ number_format((float) $l->quantite_commandee * (float) $l->prix_unitaire, 2, ',', ' ') }}</td>
                @if($commande->peutRecevoir())
                <td class="px-3 py-1 dpi-sans-impression">
                    <input type="hidden" name="lignes[{{ $i }}][ligne_commande_id]" value="{{ $l->id }}"><input type="hidden" name="lignes[{{ $i }}][medicament_id]" value="{{ $l->medicament_id }}">
                    <label class="sr-only" for="rq-{{ $i }}">Quantité reçue</label><input id="rq-{{ $i }}" name="lignes[{{ $i }}][quantite]" type="number" step="0.01" min="0" value="{{ $l->resteARecevoir() + 0 }}" class="w-24 min-h-[38px] border rounded px-2 text-right"></td>
                <td class="px-3 py-1 dpi-sans-impression"><label class="sr-only" for="rl-{{ $i }}">Lot</label><input id="rl-{{ $i }}" name="lignes[{{ $i }}][lot]" class="w-28 min-h-[38px] border rounded px-2"></td>
                <td class="px-3 py-1 dpi-sans-impression"><label class="sr-only" for="rp-{{ $i }}">Péremption</label><input id="rp-{{ $i }}" name="lignes[{{ $i }}][date_peremption]" type="date" class="min-h-[38px] border rounded px-2"></td>
                <td class="px-3 py-1 dpi-sans-impression"><label class="sr-only" for="ra-{{ $i }}">PU achat</label><input id="ra-{{ $i }}" name="lignes[{{ $i }}][prix_unitaire_achat]" type="number" step="0.01" min="0" value="{{ $l->prix_unitaire }}" class="w-24 min-h-[38px] border rounded px-2 text-right"></td>
                @endif
            </tr>
        @endforeach
        </tbody>
        <tfoot class="bg-gray-50 font-semibold"><tr><td class="px-3 py-2" colspan="4">Total</td><td class="px-3 py-2 text-right">{{ number_format($commande->montant(), 2, ',', ' ') }} {{ $commande->devise }}</td>@if($commande->peutRecevoir())<td colspan="4" class="dpi-sans-impression"></td>@endif</tr></tfoot>
    </table>
</div>
@if($commande->peutRecevoir())
<button class="mt-3 min-h-[44px] px-6 bg-green-700 hover:bg-green-800 text-white rounded-lg font-semibold dpi-sans-impression">📦 Enregistrer la réception</button>
</form>
@endif
@if($commande->receptions->isNotEmpty())
<p class="mt-4 text-sm">Réceptions : @foreach($commande->receptions as $r)<a href="{{ route('grand-depot.receptions.show', $r) }}" class="text-blue-700 hover:underline mr-2">{{ $r->numero }} ({{ $r->date_reception->format('d/m/Y') }})</a>@endforeach</p>
@endif
@endsection
