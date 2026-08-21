@extends('layouts.app')
@section('title', 'Stock — ' . $officine->nom)
@section('content')
<div class="max-w-7xl mx-auto px-4 py-6">

    @include('pharmacie._onglets')
    <div class="flex items-center gap-3 mb-6 flex-wrap">
        <a href="{{ route('officines.index') }}" class="text-blue-700 hover:underline text-sm">← Officines</a>
        <h2 class="text-2xl font-bold text-gray-800">{{ $officine->nom }}</h2>
        <span class="text-xs bg-gray-100 text-gray-600 px-2 py-1 rounded-full">{{ str_replace('_', ' ', $officine->type) }}</span>
        <a href="{{ route('officines.depot') }}" class="ml-auto text-sm text-blue-700 hover:underline">Dépôt central →</a>
    </div>

    @foreach(['success','error'] as $t)
        @if(session($t))
        <div class="mb-4 rounded-lg px-4 py-3 text-sm border {{ $t==='success' ? 'bg-green-50 border-green-200 text-green-800' : 'bg-red-50 border-red-200 text-red-800' }}">{{ session($t) }}</div>
        @endif
    @endforeach

    @if ($errors->any())
    <div class="bg-red-50 border border-red-200 text-red-800 rounded-lg px-4 py-3 mb-4 text-sm">
        @foreach ($errors->all() as $err)<p>{{ $err }}</p>@endforeach
    </div>
    @endif

    @php
        $alertes = $stocks->filter(fn ($s) => $s->quantite_disponible <= $s->quantite_alerte);
        $peremption = $stocks->filter(fn ($s) => $s->date_peremption && \Carbon\Carbon::parse($s->date_peremption)->lessThan(now()->addMonths(3)));
    @endphp
    <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-6">
        <div class="bg-white rounded-xl shadow p-4 text-center">
            <p class="text-2xl font-bold text-blue-700">{{ $stocks->count() }}</p><p class="text-xs text-gray-500">Références</p>
        </div>
        <div class="bg-white rounded-xl shadow p-4 text-center">
            <p class="text-2xl font-bold text-amber-600">{{ $alertes->count() }}</p><p class="text-xs text-gray-500">Sous seuil d'alerte</p>
        </div>
        <div class="bg-white rounded-xl shadow p-4 text-center">
            <p class="text-2xl font-bold text-red-600">{{ $peremption->count() }}</p><p class="text-xs text-gray-500">Péremption &lt; 3 mois</p>
        </div>
        <div class="bg-white rounded-xl shadow p-4 text-center">
            <p class="text-2xl font-bold text-indigo-700">{{ $requisitions->whereIn('statut', ['envoyee','partiellement_servie'])->count() }}</p>
            <p class="text-xs text-gray-500">Réquisitions en cours</p>
        </div>
    </div>

    <div class="grid lg:grid-cols-3 gap-4">
        {{-- ── Stock de l'officine ─────────────────────────────── --}}
        <div class="lg:col-span-2 bg-white rounded-xl shadow overflow-hidden">
            <div class="px-4 py-3 border-b flex items-center justify-between gap-3 flex-wrap">
                <span class="font-semibold text-gray-700">Stock de l'officine</span>
                <form method="GET" class="flex gap-2">
                    <label for="q" class="sr-only">Rechercher un produit</label>
                    <input id="q" name="q" value="{{ request('q') }}" placeholder="Rechercher un produit…"
                        class="border border-gray-300 rounded-lg px-3 py-1.5 text-sm">
                    <button class="px-3 py-1.5 bg-blue-700 text-white rounded-lg text-sm">Chercher</button>
                </form>
            </div>
            <div class="overflow-x-auto max-h-[28rem]">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 sticky top-0">
                        <tr>
                            <th class="px-3 py-2 text-left">Produit</th>
                            <th class="px-3 py-2 text-right">Quantité</th>
                            <th class="px-3 py-2 text-right">Seuil</th>
                            <th class="px-3 py-2 text-left">Lot</th>
                            <th class="px-3 py-2 text-left">Péremption</th>
                            <th class="px-3 py-2 text-right">Prix</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse($stocks as $stock)
                        @php
                            $sousSeuil = $stock->quantite_disponible <= $stock->quantite_alerte;
                            $perime = $stock->date_peremption && \Carbon\Carbon::parse($stock->date_peremption)->lessThan(now()->addMonths(3));
                        @endphp
                        <tr class="{{ $sousSeuil ? 'bg-amber-50' : '' }}">
                            <td class="px-3 py-2">
                                {{ $stock->medicament->denomination_commune }}
                                <span class="text-gray-500">{{ $stock->medicament->dosage }}</span>
                            </td>
                            <td class="px-3 py-2 text-right font-semibold {{ $sousSeuil ? 'text-amber-700' : '' }}">
                                {{ $stock->quantite_disponible + 0 }}
                                @if($sousSeuil)<span class="text-xs"> ⚠</span>@endif
                            </td>
                            <td class="px-3 py-2 text-right text-gray-400">{{ $stock->quantite_alerte }}</td>
                            <td class="px-3 py-2 text-xs text-gray-500">{{ $stock->lot ?: '—' }}</td>
                            <td class="px-3 py-2 text-xs {{ $perime ? 'text-red-700 font-semibold' : 'text-gray-500' }}">
                                {{ $stock->date_peremption ? \Carbon\Carbon::parse($stock->date_peremption)->format('m/Y') : '—' }}
                            </td>
                            <td class="px-3 py-2 text-right">{{ number_format($stock->prix_unitaire_vente ?? 0, 0, ',', ' ') }}</td>
                        </tr>
                        @empty
                        <tr><td colspan="6" class="px-4 py-10 text-center text-gray-400">Aucun produit en stock dans cette officine.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{-- ── Demander au dépôt central ────────────────────────── --}}
        <div class="bg-white rounded-xl shadow overflow-hidden">
            <div class="px-4 py-3 border-b font-semibold text-gray-700">📋 Réquisition au dépôt central</div>
            <form method="POST" action="{{ route('officines.requisition.store') }}" class="p-4">
                @csrf
                <p class="text-xs text-gray-500 mb-3">Indiquez les quantités souhaitées ; laissez vide ce dont vous n'avez pas besoin.</p>
                <div class="max-h-64 overflow-y-auto space-y-1.5 mb-3">
                    @foreach($medicaments as $medicament)
                    @php $enStock = $stocks->firstWhere('medicament_id', $medicament->id); @endphp
                    <div class="flex items-center gap-2">
                        <label for="req-{{ $medicament->id }}" class="flex-1 text-xs text-gray-700 truncate" title="{{ $medicament->denomination_commune }} {{ $medicament->dosage }}">
                            {{ $medicament->denomination_commune }} <span class="text-gray-400">{{ $medicament->dosage }}</span>
                            <span class="text-gray-400">· en stock {{ $enStock?->quantite_disponible + 0 }}</span>
                        </label>
                        <input id="req-{{ $medicament->id }}" type="number" step="0.01" min="0"
                            name="quantites[{{ $medicament->id }}]"
                            class="w-20 border border-gray-300 rounded px-2 py-1 text-sm text-right">
                    </div>
                    @endforeach
                </div>
                <label for="motif" class="sr-only">Motif</label>
                <input id="motif" name="motif" placeholder="Motif (facultatif)"
                    class="w-full border border-gray-300 rounded-lg px-3 py-1.5 text-sm mb-3">
                <button class="w-full bg-blue-700 hover:bg-blue-800 text-white text-sm py-2 rounded-lg font-semibold">
                    Envoyer la réquisition
                </button>
            </form>
        </div>
    </div>

    {{-- ── Réquisitions de l'officine ───────────────────────────── --}}
    <div class="bg-white rounded-xl shadow overflow-hidden mt-4">
        <div class="px-4 py-3 border-b font-semibold text-gray-700">Réquisitions récentes</div>
        <table class="w-full text-sm">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-3 py-2 text-left">Numéro</th>
                    <th class="px-3 py-2 text-left">Demandée le</th>
                    <th class="px-3 py-2 text-left">Produits</th>
                    <th class="px-3 py-2 text-left">Statut</th>
                    <th class="px-3 py-2 text-left">Servie le</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse($requisitions as $requisition)
                <tr>
                    <td class="px-3 py-2 font-mono text-xs">{{ $requisition->numero }}</td>
                    <td class="px-3 py-2 text-xs">{{ $requisition->date_demande->format('d/m/Y H:i') }}</td>
                    <td class="px-3 py-2 text-xs text-gray-600">
                        {{ $requisition->lignes->count() }} ligne(s) —
                        {{ $requisition->lignes->sum('quantite_servie') + 0 }}/{{ $requisition->lignes->sum('quantite_demandee') + 0 }} servi
                    </td>
                    <td class="px-3 py-2">
                        <span class="text-[10px] font-bold uppercase px-2 py-0.5 rounded
                            {{ match($requisition->statut) {
                                'servie' => 'bg-green-100 text-green-800',
                                'partiellement_servie' => 'bg-amber-100 text-amber-800',
                                'refusee' => 'bg-red-100 text-red-800',
                                default => 'bg-blue-100 text-blue-800',
                            } }}">{{ str_replace('_', ' ', $requisition->statut) }}</span>
                    </td>
                    <td class="px-3 py-2 text-xs text-gray-500">{{ $requisition->date_service?->format('d/m/Y H:i') ?? '—' }}</td>
                </tr>
                @empty
                <tr><td colspan="5" class="px-4 py-8 text-center text-gray-400">Aucune réquisition</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- ── Mouvements ──────────────────────────────────────────── --}}
    <div class="bg-white rounded-xl shadow overflow-hidden mt-4">
        <div class="px-4 py-3 border-b font-semibold text-gray-700">Derniers mouvements</div>
        <table class="w-full text-sm">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-3 py-2 text-left">Date</th>
                    <th class="px-3 py-2 text-left">Produit</th>
                    <th class="px-3 py-2 text-left">Type</th>
                    <th class="px-3 py-2 text-right">Quantité</th>
                    <th class="px-3 py-2 text-left">Provenance / destination</th>
                    <th class="px-3 py-2 text-left">Par</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse($mouvements as $mouvement)
                <tr>
                    <td class="px-3 py-2 text-xs">{{ $mouvement->created_at?->format('d/m/Y H:i') }}</td>
                    <td class="px-3 py-2 text-xs">{{ $mouvement->medicament->denomination_commune }}</td>
                    <td class="px-3 py-2 text-xs">{{ str_replace('_', ' ', $mouvement->type) }}</td>
                    <td class="px-3 py-2 text-right text-xs font-semibold {{ str_contains($mouvement->type, 'entree') ? 'text-green-700' : 'text-red-700' }}">
                        {{ str_contains($mouvement->type, 'entree') ? '+' : '−' }}{{ $mouvement->quantite + 0 }}
                    </td>
                    <td class="px-3 py-2 text-xs text-gray-500">{{ $mouvement->provenance ?: ($mouvement->destination ?: '—') }}</td>
                    <td class="px-3 py-2 text-xs text-gray-500">{{ $mouvement->user?->nom }}</td>
                </tr>
                @empty
                <tr><td colspan="6" class="px-4 py-8 text-center text-gray-400">Aucun mouvement</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
