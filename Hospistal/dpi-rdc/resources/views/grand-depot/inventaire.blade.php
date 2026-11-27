@extends('layouts.app')
@section('title', 'Inventaire '.$inventaire->numero)
@section('content')
@include('grand-depot._onglets')
@php $ouvert = $inventaire->statut === 'en_cours'; @endphp
<div class="flex flex-wrap items-center justify-between gap-3 mb-3">
    <div><h3 class="text-xl font-bold">Inventaire {{ $inventaire->numero }}</h3><p class="text-sm text-gray-600">{{ $inventaire->date_inventaire->format('d/m/Y') }} — {{ ['en_cours' => 'En cours de comptage', 'valide' => 'Validé', 'annule' => 'Annulé'][$inventaire->statut] }}</p></div>
    <button type="button" onclick="window.print()" class="min-h-[40px] px-4 rounded-lg border bg-white dpi-sans-impression">🖨 Imprimer la feuille de comptage</button>
</div>
<form method="POST" action="{{ route('grand-depot.inventaires.saisir', $inventaire) }}">
    @csrf
    <div class="bg-white rounded-xl shadow overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-left"><tr><th class="px-3 py-2">Produit</th><th class="px-3 py-2">Lot</th><th class="px-3 py-2">Péremption</th><th class="px-3 py-2 text-right">Théorique</th><th class="px-3 py-2 text-right">Compté</th><th class="px-3 py-2 text-right">Écart</th><th class="px-3 py-2">Motif de l'écart</th></tr></thead>
            <tbody class="divide-y">
            @foreach($inventaire->lignes->sortBy(fn ($l) => $l->medicament?->denomination_commune) as $l)
                @php $e = $l->ecart(); @endphp
                <tr>
                    <td class="px-3 py-1">{{ $l->medicament?->denomination_commune }} {{ $l->medicament?->dosage }}</td>
                    <td class="px-3 py-1">{{ $l->lot ?? '—' }}</td>
                    <td class="px-3 py-1">{{ $l->date_peremption?->format('d/m/Y') ?? '—' }}</td>
                    <td class="px-3 py-1 text-right">{{ $l->quantite_theorique + 0 }}</td>
                    <td class="px-3 py-1 text-right">@if($ouvert)<label class="sr-only" for="ic-{{ $l->id }}">Compté</label><input id="ic-{{ $l->id }}" name="comptes[{{ $l->id }}]" type="number" step="0.01" min="0" value="{{ $l->quantite_comptee !== null ? $l->quantite_comptee + 0 : '' }}" class="w-24 min-h-[36px] border rounded px-2 text-right">@else{{ $l->quantite_comptee !== null ? $l->quantite_comptee + 0 : '—' }}@endif</td>
                    <td class="px-3 py-1 text-right font-semibold {{ $e === null || abs($e) < 0.001 ? '' : ($e < 0 ? 'text-red-700' : 'text-green-700') }}">{{ $e === null ? '' : ($e > 0 ? '+' : '').($e + 0) }}</td>
                    <td class="px-3 py-1">@if($ouvert)<label class="sr-only" for="im-{{ $l->id }}">Motif</label><input id="im-{{ $l->id }}" name="motifs[{{ $l->id }}]" value="{{ $l->motif_ecart }}" placeholder="casse, vol, erreur de saisie…" class="w-full min-h-[36px] border rounded px-2">@else{{ $l->motif_ecart }}@endif</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
    @if($ouvert)
    <div class="flex gap-3 mt-3 dpi-sans-impression">
        <button name="action" value="enregistrer" class="min-h-[44px] px-5 rounded-lg border bg-white font-semibold">💾 Enregistrer le comptage</button>
        <button name="action" value="valider" onclick="return confirm('Valider l\'inventaire ? Les écarts deviendront des ajustements de stock.');" class="min-h-[44px] px-5 bg-green-700 hover:bg-green-800 text-white rounded-lg font-semibold">✓ Valider l'inventaire</button>
    </div>
    @endif
</form>
@endsection
