@extends('layouts.app')
@section('title', 'Fiche de stock — '.$medicament->denomination_commune)
@section('content')
@include('grand-depot._onglets')
<h3 class="text-xl font-bold mb-1">Fiche de stock : {{ $medicament->denomination_commune }} {{ $medicament->dosage }} {{ $medicament->forme }}</h3>
<p class="text-sm text-gray-600 mb-3">{{ $depot->nom }}</p>
<form method="GET" class="flex flex-wrap items-end gap-2 mb-3 dpi-sans-impression">
    <div><label for="fs-du" class="block text-xs text-gray-600 mb-1">Du</label><input id="fs-du" type="date" name="du" value="{{ $du }}" class="min-h-[44px] border rounded-lg px-3"></div>
    <div><label for="fs-au" class="block text-xs text-gray-600 mb-1">Au</label><input id="fs-au" type="date" name="au" value="{{ $au }}" class="min-h-[44px] border rounded-lg px-3"></div>
    <button class="min-h-[44px] px-4 bg-gray-100 hover:bg-gray-200 rounded-lg">Filtrer</button>
    <button type="button" onclick="window.print()" class="min-h-[44px] px-4 rounded-lg border bg-white">🖨 Imprimer</button>
</form>
<div class="grid md:grid-cols-3 gap-4 items-start">
    <div class="md:col-span-2 bg-white rounded-xl shadow overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-left"><tr><th class="px-3 py-2">Date</th><th class="px-3 py-2">Mouvement</th><th class="px-3 py-2">Référence / tiers</th><th class="px-3 py-2 text-right">Entrée</th><th class="px-3 py-2 text-right">Sortie</th><th class="px-3 py-2 text-right">Solde du lot</th><th class="px-3 py-2">Par</th></tr></thead>
            <tbody class="divide-y">
            @forelse($mouvements as $m)
                @php $entree = in_array($m->type, ['entree', 'transfert_entree'], true) || ($m->type === 'ajustement_inventaire' && $m->quantite_apres >= $m->quantite_avant); @endphp
                <tr>
                    <td class="px-3 py-2 whitespace-nowrap">{{ \Illuminate\Support\Carbon::parse($m->created_at)->format('d/m/Y H:i') }}</td>
                    <td class="px-3 py-2">{{ ['entree' => 'Entrée', 'transfert_entree' => 'Transfert reçu', 'transfert_sortie' => 'Sortie vers officine', 'sortie_dispensation' => 'Dispensation', 'sortie_peremption' => 'Destruction / péremption', 'sortie_perte' => 'Perte', 'ajustement_inventaire' => 'Ajustement d\'inventaire'][$m->type] ?? $m->type }}</td>
                    <td class="px-3 py-2 text-xs">{{ $m->reference }} {{ $m->provenance ?? $m->destination }}</td>
                    <td class="px-3 py-2 text-right text-green-700">{{ $entree ? abs((float) $m->quantite) + 0 : '' }}</td>
                    <td class="px-3 py-2 text-right text-red-700">{{ $entree ? '' : abs((float) $m->quantite) + 0 }}</td>
                    <td class="px-3 py-2 text-right">{{ $m->quantite_apres + 0 }}</td>
                    <td class="px-3 py-2 text-xs">{{ $m->user?->nom_complet }}</td>
                </tr>
            @empty
                <tr><td colspan="7" class="px-3 py-8 text-center text-gray-400">Aucun mouvement sur la période.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <div class="bg-white rounded-xl shadow p-4">
        <h4 class="font-semibold mb-2">Lots en stock</h4>
        <table class="w-full text-sm"><thead><tr class="text-left text-gray-600"><th>Lot</th><th>Péremption</th><th class="text-right">Qté</th></tr></thead>
            <tbody class="divide-y">@forelse($lots as $l)<tr><td class="py-1">{{ $l->lot ?? '—' }}</td><td class="py-1 {{ $l->date_peremption?->isPast() ? 'text-red-700' : '' }}">{{ $l->date_peremption?->format('d/m/Y') ?? '—' }}</td><td class="py-1 text-right">{{ $l->quantite_disponible + 0 }}</td></tr>@empty<tr><td colspan="3" class="py-2 text-gray-500">Aucun lot.</td></tr>@endforelse</tbody>
            <tfoot class="font-semibold"><tr><td colspan="2" class="pt-2">Total</td><td class="pt-2 text-right">{{ $lots->sum('quantite_disponible') + 0 }}</td></tr></tfoot>
        </table>
    </div>
</div>
@endsection
