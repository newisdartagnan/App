@extends('layouts.app')
@section('title', 'Dépôt central')
@section('content')
<div class="max-w-7xl mx-auto px-4 py-6">

    @include('pharmacie._onglets')
    <div class="flex items-center gap-3 mb-6 flex-wrap">
        <a href="{{ route('officines.index') }}" class="text-blue-700 hover:underline text-sm">← Officines</a>
        <h2 class="text-2xl font-bold text-gray-800">🏛 {{ $depot->nom }}</h2>
        <span class="text-xs bg-blue-100 text-blue-800 px-2 py-1 rounded-full font-semibold">
            {{ $requisitions->count() }} demande(s) en attente
        </span>
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

    {{-- ── Demandes des officines ───────────────────────────────── --}}
    <div class="bg-white rounded-xl shadow mb-6 overflow-hidden">
        <div class="px-4 py-3 border-b bg-amber-50 font-semibold text-amber-900">📥 Demandes des officines</div>
        <div class="divide-y divide-gray-100">
            @forelse($requisitions as $requisition)
            <div class="px-4 py-3">
                <div class="flex flex-wrap items-center gap-x-4 gap-y-1 mb-2 text-sm">
                    <span class="font-mono text-xs bg-gray-100 px-2 py-0.5 rounded">{{ $requisition->numero }}</span>
                    <span class="font-semibold text-blue-900">{{ $requisition->officine->nom }}</span>
                    <span class="text-xs text-gray-500">
                        demandé le {{ $requisition->date_demande->format('d/m/Y à H:i') }}
                        par {{ $requisition->demandeur?->nom }}
                    </span>
                    @if($requisition->statut === 'partiellement_servie')
                    <span class="text-[10px] font-bold uppercase bg-amber-100 text-amber-800 px-2 py-0.5 rounded">partiellement servie</span>
                    @endif
                    @if($requisition->motif)<span class="text-xs italic text-gray-500">« {{ $requisition->motif }} »</span>@endif
                </div>

                <form method="POST" action="{{ route('requisitions.servir', $requisition) }}" class="bg-gray-50 rounded-lg p-3">
                    @csrf
                    <table class="w-full text-xs mb-2">
                        <thead>
                            <tr class="text-gray-500">
                                <th class="text-left py-1">Produit</th>
                                <th class="text-right py-1">Demandé</th>
                                <th class="text-right py-1">Déjà servi</th>
                                <th class="text-right py-1">Stock dépôt</th>
                                <th class="text-right py-1">À servir</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($requisition->lignes as $ligne)
                            @php
                                $dispo = $stocks->where('medicament_id', $ligne->medicament_id)->sum('quantite_disponible');
                                $insuffisant = $dispo < $ligne->reste();
                            @endphp
                            <tr class="border-t border-gray-200 {{ $insuffisant ? 'bg-red-50' : '' }}">
                                <td class="py-1.5">
                                    {{ $ligne->medicament->denomination_commune }}
                                    <span class="text-gray-400">{{ $ligne->medicament->dosage }}</span>
                                </td>
                                <td class="py-1.5 text-right">{{ $ligne->quantite_demandee + 0 }}</td>
                                <td class="py-1.5 text-right text-gray-500">{{ $ligne->quantite_servie + 0 }}</td>
                                <td class="py-1.5 text-right {{ $insuffisant ? 'text-red-700 font-semibold' : 'text-gray-600' }}">
                                    {{ $dispo + 0 }}@if($insuffisant) ⚠@endif
                                </td>
                                <td class="py-1.5 text-right">
                                    <label for="srv-{{ $ligne->id }}" class="sr-only">Quantité à servir</label>
                                    <input id="srv-{{ $ligne->id }}" type="number" step="0.01" min="0"
                                        max="{{ min($ligne->reste(), $dispo) }}"
                                        name="servies[{{ $ligne->id }}]"
                                        value="{{ min($ligne->reste(), $dispo) > 0 ? min($ligne->reste(), $dispo) : '' }}"
                                        class="w-20 border border-gray-300 rounded px-2 py-1 text-right">
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                    <div class="flex gap-2 justify-end">
                        <button class="bg-blue-700 hover:bg-blue-800 text-white text-xs px-4 py-1.5 rounded-lg font-semibold">
                            Servir (transfert vers l'officine)
                        </button>
                    </div>
                </form>
                <form method="POST" action="{{ route('requisitions.refuser', $requisition) }}" class="mt-2 flex gap-2 justify-end items-center">
                    @csrf
                    <label for="ref-{{ $requisition->id }}" class="sr-only">Motif de refus</label>
                    <input id="ref-{{ $requisition->id }}" name="motif" placeholder="Motif du refus"
                        class="border border-gray-300 rounded px-2 py-1 text-xs w-64">
                    <button class="text-xs text-red-700 border border-red-300 rounded px-3 py-1 hover:bg-red-50">Refuser</button>
                </form>
            </div>
            @empty
            <p class="px-4 py-10 text-center text-sm text-gray-400">Aucune demande en attente</p>
            @endforelse
        </div>
    </div>

    <div class="grid lg:grid-cols-2 gap-4 mb-6">
        {{-- ── Entrée fournisseur ───────────────────────────────── --}}
        <div class="bg-white rounded-xl shadow overflow-hidden">
            <div class="px-4 py-3 border-b font-semibold text-gray-700">📦 Entrée en stock</div>
            <form method="POST" action="{{ route('officines.entree') }}" class="p-4 grid grid-cols-2 gap-3">
                @csrf
                <input type="hidden" name="officine_id" value="{{ $depot->id }}">
                <div class="col-span-2">
                    <label for="e-med" class="block text-xs text-gray-500 mb-1">Produit</label>
                    <select id="e-med" name="medicament_id" required class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                        <option value="">— Choisir —</option>
                        @foreach($medicaments as $medicament)
                        <option value="{{ $medicament->id }}">{{ $medicament->denomination_commune }} {{ $medicament->dosage }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="e-qte" class="block text-xs text-gray-500 mb-1">Quantité</label>
                    <input id="e-qte" type="number" step="0.01" min="0.01" name="quantite" required
                        class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                </div>
                <div>
                    <label for="e-prov" class="block text-xs text-gray-500 mb-1">Provenance</label>
                    <input id="e-prov" name="provenance" placeholder="Fournisseur, don…"
                        class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                </div>
                <div>
                    <label for="e-lot" class="block text-xs text-gray-500 mb-1">Lot</label>
                    <input id="e-lot" name="lot" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                </div>
                <div>
                    <label for="e-per" class="block text-xs text-gray-500 mb-1">Péremption</label>
                    <input id="e-per" type="date" name="date_peremption" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                </div>
                <div class="col-span-2">
                    <label for="e-prix" class="block text-xs text-gray-500 mb-1">Prix de vente unitaire (CDF)</label>
                    <input id="e-prix" type="number" step="0.01" min="0" name="prix_unitaire_vente"
                        class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                </div>
                <div class="col-span-2">
                    <button class="w-full bg-blue-700 hover:bg-blue-800 text-white text-sm py-2 rounded-lg font-semibold">
                        Enregistrer l'entrée
                    </button>
                </div>
            </form>
        </div>

        {{-- ── Stock des officines ──────────────────────────────── --}}
        <div class="bg-white rounded-xl shadow overflow-hidden">
            <div class="px-4 py-3 border-b font-semibold text-gray-700">Stock des officines</div>
            <table class="w-full text-sm">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-3 py-2 text-left">Officine</th>
                        <th class="px-3 py-2 text-right">Références</th>
                        <th class="px-3 py-2 text-right">Sous seuil</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach($stockOfficines as $ligne)
                    <tr>
                        <td class="px-3 py-2">{{ $ligne['officine']->nom }}</td>
                        <td class="px-3 py-2 text-right">{{ $ligne['references'] }}</td>
                        <td class="px-3 py-2 text-right {{ $ligne['alertes'] > 0 ? 'text-amber-700 font-semibold' : 'text-gray-400' }}">
                            {{ $ligne['alertes'] }}
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    {{-- ── Stock du dépôt ───────────────────────────────────────── --}}
    <div class="bg-white rounded-xl shadow overflow-hidden mb-6">
        <div class="px-4 py-3 border-b flex justify-between items-center flex-wrap gap-3">
            <span class="font-semibold text-gray-700">Stock du dépôt central</span>
            <form method="GET" class="flex gap-2">
                <label for="qd" class="sr-only">Rechercher</label>
                <input id="qd" name="q" value="{{ request('q') }}" placeholder="Rechercher…"
                    class="border border-gray-300 rounded-lg px-3 py-1.5 text-sm">
                <button class="px-3 py-1.5 bg-blue-700 text-white rounded-lg text-sm">Chercher</button>
            </form>
        </div>
        <div class="overflow-x-auto max-h-96">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 sticky top-0">
                    <tr>
                        <th class="px-3 py-2 text-left">Produit</th>
                        <th class="px-3 py-2 text-right">Quantité</th>
                        <th class="px-3 py-2 text-right">Seuil</th>
                        <th class="px-3 py-2 text-left">Lot</th>
                        <th class="px-3 py-2 text-left">Péremption</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse($stocks as $stock)
                    @php $sousSeuil = $stock->quantite_disponible <= $stock->quantite_alerte; @endphp
                    <tr class="{{ $sousSeuil ? 'bg-amber-50' : '' }}">
                        <td class="px-3 py-2">{{ $stock->medicament->denomination_commune }} <span class="text-gray-500">{{ $stock->medicament->dosage }}</span></td>
                        <td class="px-3 py-2 text-right font-semibold {{ $sousSeuil ? 'text-amber-700' : '' }}">{{ $stock->quantite_disponible + 0 }}</td>
                        <td class="px-3 py-2 text-right text-gray-400">{{ $stock->quantite_alerte }}</td>
                        <td class="px-3 py-2 text-xs text-gray-500">{{ $stock->lot ?: '—' }}</td>
                        <td class="px-3 py-2 text-xs text-gray-500">{{ $stock->date_peremption ? \Carbon\Carbon::parse($stock->date_peremption)->format('m/Y') : '—' }}</td>
                    </tr>
                    @empty
                    <tr><td colspan="5" class="px-4 py-8 text-center text-gray-400">Aucun produit</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- ── Historique ───────────────────────────────────────────── --}}
    <div class="bg-white rounded-xl shadow overflow-hidden">
        <div class="px-4 py-3 border-b font-semibold text-gray-700">Réquisitions traitées</div>
        <table class="w-full text-sm">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-3 py-2 text-left">Numéro</th>
                    <th class="px-3 py-2 text-left">Officine</th>
                    <th class="px-3 py-2 text-left">Statut</th>
                    <th class="px-3 py-2 text-left">Traitée le</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse($historique as $requisition)
                <tr>
                    <td class="px-3 py-2 font-mono text-xs">{{ $requisition->numero }}</td>
                    <td class="px-3 py-2">{{ $requisition->officine->nom }}</td>
                    <td class="px-3 py-2">
                        <span class="text-[10px] font-bold uppercase px-2 py-0.5 rounded
                            {{ $requisition->statut === 'servie' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' }}">
                            {{ $requisition->statut }}
                        </span>
                    </td>
                    <td class="px-3 py-2 text-xs text-gray-500">{{ $requisition->date_service?->format('d/m/Y H:i') ?? '—' }}</td>
                </tr>
                @empty
                <tr><td colspan="4" class="px-4 py-8 text-center text-gray-400">Aucune réquisition traitée</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
