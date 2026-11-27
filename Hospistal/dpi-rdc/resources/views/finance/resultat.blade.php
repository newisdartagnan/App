@extends('layouts.app')
@section('title', 'Compte de résultat')
@section('content')
@include('finance._onglets')
@php $fc = fn ($n) => number_format((float) $n, 0, ',', ' ').' FC'; @endphp
<form method="GET" class="flex flex-wrap items-end gap-2 mb-3 dpi-sans-impression">
    <div><label for="re-du" class="block text-xs text-gray-600 mb-1">Du</label><input id="re-du" type="date" name="du" value="{{ $du }}" class="min-h-[44px] border rounded-lg px-3"></div>
    <div><label for="re-au" class="block text-xs text-gray-600 mb-1">Au</label><input id="re-au" type="date" name="au" value="{{ $au }}" class="min-h-[44px] border rounded-lg px-3"></div>
    <button class="min-h-[44px] px-4 bg-gray-100 hover:bg-gray-200 rounded-lg">Afficher</button>
    <button type="button" onclick="window.print()" class="min-h-[44px] px-4 rounded-lg border bg-white">🖨 Imprimer</button>
</form>
<h3 class="text-xl font-bold mb-3">Compte de résultat du {{ \Illuminate\Support\Carbon::parse($du)->format('d/m/Y') }} au {{ \Illuminate\Support\Carbon::parse($au)->format('d/m/Y') }}</h3>
<div class="grid md:grid-cols-2 gap-4 items-start mb-6">
    <section class="bg-white rounded-xl shadow p-4">
        <h4 class="font-semibold text-green-800 mb-2">Recettes (encaissements)</h4>
        <table class="w-full text-sm"><tbody class="divide-y">
            @forelse($resultat['recettes_par_mode'] as $mode => $total)<tr><td class="py-1">{{ \App\Models\CompteTresorerie::MODES[$mode] ?? $mode }}</td><td class="py-1 text-right">{{ $fc($total) }}</td></tr>@empty<tr><td class="py-1 text-gray-500">Aucune recette.</td></tr>@endforelse
            <tr class="font-bold"><td class="py-2">Total recettes</td><td class="py-2 text-right">{{ $fc($resultat['recettes']) }}</td></tr>
        </tbody></table>
    </section>
    <section class="bg-white rounded-xl shadow p-4">
        <h4 class="font-semibold text-red-800 mb-2">Dépenses (payées)</h4>
        <table class="w-full text-sm"><tbody class="divide-y">
            @forelse($resultat['depenses_par_rubrique'] as $rub => $total)<tr><td class="py-1">{{ $rub }}</td><td class="py-1 text-right">{{ $fc($total) }}</td></tr>@empty<tr><td class="py-1 text-gray-500">Aucune dépense.</td></tr>@endforelse
            <tr class="font-bold"><td class="py-2">Total dépenses</td><td class="py-2 text-right">{{ $fc($resultat['depenses']) }}</td></tr>
        </tbody></table>
    </section>
</div>
<div class="rounded-xl border p-4 mb-6 {{ $resultat['resultat'] >= 0 ? 'bg-blue-50' : 'bg-amber-50' }}"><span class="font-semibold">Résultat de la période : </span><span class="text-2xl font-bold">{{ $fc($resultat['resultat']) }}</span></div>

<section class="bg-white rounded-xl shadow p-4 dpi-sans-impression">
    <h4 class="font-semibold mb-2">Clôture mensuelle</h4>
    <p class="text-xs text-gray-500 mb-2">Clôturer un mois fige ses opérations : plus aucune dépense ni transfert ne peut y être enregistré.</p>
    @if(auth()->user()->can('finance.valider') || auth()->user()->hasAnyRole(['super_admin', 'directeur']))
    <form method="POST" action="{{ route('finance.cloturer') }}" class="flex flex-wrap items-end gap-2 mb-3">@csrf
        <div><label for="cl-mois" class="block text-xs text-gray-600 mb-1">Mois</label><input id="cl-mois" type="month" name="mois" value="{{ now()->subMonth()->format('Y-m') }}" max="{{ now()->subMonth()->format('Y-m') }}" required class="min-h-[44px] border rounded-lg px-3"></div>
        <div class="flex-1"><label for="cl-obs" class="block text-xs text-gray-600 mb-1">Observations</label><input id="cl-obs" name="observations" class="w-full min-h-[44px] border rounded-lg px-3"></div>
        <button onclick="return confirm('Clôturer ce mois ? Cette action est définitive.');" class="min-h-[44px] px-5 bg-gray-800 text-white rounded-lg font-semibold">🔒 Clôturer</button>
    </form>
    @endif
    <table class="w-full text-sm"><thead class="bg-gray-50 text-left"><tr><th class="px-3 py-2">Mois</th><th class="px-3 py-2 text-right">Recettes</th><th class="px-3 py-2 text-right">Dépenses</th><th class="px-3 py-2">Clôturé par</th></tr></thead>
        <tbody class="divide-y">@forelse($clotures as $c)<tr><td class="px-3 py-2">{{ $c->mois }}</td><td class="px-3 py-2 text-right">{{ $fc($c->total_recettes_cdf) }}</td><td class="px-3 py-2 text-right">{{ $fc($c->total_depenses_cdf) }}</td><td class="px-3 py-2">{{ $c->auteur?->nom_complet }} — {{ $c->created_at->format('d/m/Y') }}</td></tr>@empty<tr><td colspan="4" class="px-3 py-4 text-gray-400">Aucun mois clôturé.</td></tr>@endforelse</tbody></table>
</section>
@endsection
