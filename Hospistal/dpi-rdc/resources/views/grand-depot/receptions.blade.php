@extends('layouts.app')
@section('title', 'Réceptions')
@section('content')
@include('grand-depot._onglets')
<div class="flex justify-end mb-3"><a href="{{ route('grand-depot.receptions.create') }}" class="min-h-[44px] inline-flex items-center px-5 bg-blue-700 hover:bg-blue-800 text-white rounded-lg font-semibold">⊕ Réception sans commande (don, achat direct)</a></div>
<div class="bg-white rounded-xl shadow overflow-x-auto">
    <table class="w-full text-sm">
        <thead class="bg-gray-50 text-left text-gray-600"><tr><th class="px-4 py-2">N°</th><th class="px-4 py-2">Date</th><th class="px-4 py-2">Origine</th><th class="px-4 py-2">Fournisseur</th><th class="px-4 py-2">Commande</th><th class="px-4 py-2 text-right">Montant</th><th class="px-4 py-2 text-right">Reste à payer</th></tr></thead>
        <tbody class="divide-y">
        @forelse($receptions as $r)
            <tr>
                <td class="px-4 py-2 font-mono text-xs"><a href="{{ route('grand-depot.receptions.show', $r) }}" class="text-blue-700 hover:underline">{{ $r->numero }}</a></td>
                <td class="px-4 py-2">{{ $r->date_reception->format('d/m/Y') }}</td>
                <td class="px-4 py-2">{{ \App\Models\ReceptionStock::ORIGINES[$r->origine] ?? $r->origine }}</td>
                <td class="px-4 py-2">{{ $r->fournisseur?->nom ?? '—' }}</td>
                <td class="px-4 py-2 font-mono text-xs">{{ $r->commande?->numero ?? '—' }}</td>
                <td class="px-4 py-2 text-right">{{ number_format((float) $r->montant_total, 2, ',', ' ') }} {{ $r->devise }}</td>
                <td class="px-4 py-2 text-right">{{ number_format($r->resteAPayer(), 2, ',', ' ') }}</td>
            </tr>
        @empty
            <tr><td colspan="7" class="px-4 py-8 text-center text-gray-400">Aucune réception.</td></tr>
        @endforelse
        </tbody>
    </table>
    <div class="px-4 py-3 border-t">{{ $receptions->links() }}</div>
</div>
@endsection
