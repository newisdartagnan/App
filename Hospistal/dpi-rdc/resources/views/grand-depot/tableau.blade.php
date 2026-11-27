@extends('layouts.app')
@section('title', 'Grand dépôt')
@section('content')
@include('grand-depot._onglets')
@php $fc = fn ($n) => number_format((float) $n, 0, ',', ' '); $nb = fn ($n) => rtrim(rtrim(number_format((float) $n, 2, ',', ' '), '0'), ','); @endphp
<div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-7 gap-3 mb-4">
    @foreach([
        ['Valeur du stock', $fc($kpi['valeur']).' FC', 'bg-blue-50 text-blue-900', null],
        ['Références en stock', $kpi['references'], 'bg-gray-50 text-gray-900', null],
        ['Ruptures', $kpi['ruptures'], 'bg-red-50 text-red-800', 'rupture'],
        ['Stock bas', $kpi['alertes'], 'bg-amber-50 text-amber-800', 'alerte'],
        ['À commander', $kpi['a_commander'], 'bg-indigo-50 text-indigo-800', null],
        ['Lots périmés', $kpi['perimes'], 'bg-red-50 text-red-800', null],
        ['Périment < 90 j', $kpi['bientot'], 'bg-amber-50 text-amber-800', null],
    ] as [$lib, $val, $classe, $f])
    <a href="{{ $f ? route('grand-depot.tableau', ['statut' => $f]) : '#' }}" class="rounded-xl border p-3 {{ $classe }}">
        <p class="text-xs font-medium opacity-80">{{ $lib }}</p><p class="text-2xl font-bold mt-1">{{ $val }}</p>
    </a>
    @endforeach
</div>

@if($commandesOuvertes->isNotEmpty())
<div class="bg-white border rounded-xl p-3 mb-4 text-sm">
    <strong>Commandes en cours :</strong>
    @foreach($commandesOuvertes as $c)<a href="{{ route('grand-depot.commandes.show', $c) }}" class="ml-2 text-blue-700 hover:underline">{{ $c->numero }} ({{ $c->fournisseur?->nom }} — {{ \App\Models\CommandeFournisseur::STATUTS[$c->statut] }})</a>@endforeach
</div>
@endif

<div class="flex flex-wrap items-end justify-between gap-2 mb-3">
    <form method="GET" class="flex flex-wrap gap-2 items-end">
        <div><label for="gd-q" class="block text-xs text-gray-600 mb-1">Produit</label><input id="gd-q" name="q" value="{{ $q }}" class="min-h-[44px] border rounded-lg px-3"></div>
        <div><label for="gd-s" class="block text-xs text-gray-600 mb-1">Statut</label>
            <select id="gd-s" name="statut" class="min-h-[44px] border rounded-lg px-3"><option value="">Tous (actifs)</option>@foreach(['rupture' => 'Rupture', 'alerte' => 'Stock bas', 'normal' => 'Normal', 'surstock' => 'Surstock'] as $k => $v)<option value="{{ $k }}" @selected($filtre === $k)>{{ $v }}</option>@endforeach</select></div>
        <button class="min-h-[44px] px-4 bg-gray-100 hover:bg-gray-200 rounded-lg">Filtrer</button>
    </form>
    <div class="flex gap-2">
        <a href="{{ route('grand-depot.commandes.create', ['propositions' => 1]) }}" class="min-h-[44px] inline-flex items-center px-4 bg-indigo-700 hover:bg-indigo-800 text-white rounded-lg text-sm font-semibold">🧾 Commander les propositions</a>
        <a href="{{ route('grand-depot.receptions.create') }}" class="min-h-[44px] inline-flex items-center px-4 bg-blue-700 hover:bg-blue-800 text-white rounded-lg text-sm font-semibold">📦 Réception</a>
    </div>
</div>

<div class="bg-white rounded-xl shadow overflow-x-auto mb-6">
    <table class="w-full text-sm">
        <thead class="bg-gray-50 text-left text-gray-600"><tr>
            <th class="px-3 py-2">Produit</th><th class="px-3 py-2 text-right">Stock</th><th class="px-3 py-2 text-right">CMM</th>
            <th class="px-3 py-2 text-right">Mois de stock</th><th class="px-3 py-2 text-right">En commande</th><th class="px-3 py-2 text-right">À commander</th>
            <th class="px-3 py-2 text-right">Valeur (FC)</th><th class="px-3 py-2">Statut</th><th></th>
        </tr></thead>
        <tbody class="divide-y">
        @forelse($situation as $s)
            <tr>
                <td class="px-3 py-2 font-medium">{{ $s->medicament->denomination_commune }} <span class="text-xs text-gray-500">{{ $s->medicament->dosage }} {{ $s->medicament->forme }}</span></td>
                <td class="px-3 py-2 text-right">{{ $nb($s->stock) }}</td>
                <td class="px-3 py-2 text-right">{{ $nb($s->cmm) }}</td>
                <td class="px-3 py-2 text-right">{{ $s->mois_stock !== null ? $nb($s->mois_stock) : '—' }}</td>
                <td class="px-3 py-2 text-right">{{ $nb($s->en_commande) }}</td>
                <td class="px-3 py-2 text-right font-semibold {{ $s->a_commander > 0 ? 'text-indigo-700' : '' }}">{{ $s->a_commander ?: '' }}</td>
                <td class="px-3 py-2 text-right">{{ $fc($s->valeur) }}</td>
                <td class="px-3 py-2"><span class="px-2 py-0.5 rounded-full text-xs {{ ['rupture' => 'bg-red-100 text-red-800', 'alerte' => 'bg-amber-100 text-amber-800', 'normal' => 'bg-green-100 text-green-800', 'surstock' => 'bg-purple-100 text-purple-800'][$s->statut] }}">{{ ['rupture' => 'Rupture', 'alerte' => 'Stock bas', 'normal' => 'Normal', 'surstock' => 'Surstock'][$s->statut] }}</span></td>
                <td class="px-3 py-2 text-right"><a href="{{ route('grand-depot.fiche', $s->medicament) }}" class="text-xs text-blue-700 hover:underline">Fiche de stock</a></td>
            </tr>
        @empty
            <tr><td colspan="9" class="px-3 py-8 text-center text-gray-400">Aucun produit pour ce filtre.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
<p class="text-xs text-gray-500">CMM : consommation moyenne mensuelle des 3 derniers mois (sorties vers les officines et dispensations). « À commander » vise {{ \App\Services\GrandDepotService::MOIS_COUVERTURE }} mois de couverture, déduction faite du stock et de ce qui est déjà commandé.</p>
@endsection
