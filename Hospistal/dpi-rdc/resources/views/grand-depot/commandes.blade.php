@extends('layouts.app')
@section('title', 'Commandes fournisseurs')
@section('content')
@include('grand-depot._onglets')
<div class="flex justify-end mb-3"><a href="{{ route('grand-depot.commandes.create') }}" class="min-h-[44px] inline-flex items-center px-5 bg-blue-700 hover:bg-blue-800 text-white rounded-lg font-semibold">⊕ Nouveau bon de commande</a></div>
<div class="bg-white rounded-xl shadow overflow-x-auto">
    <table class="w-full text-sm">
        <thead class="bg-gray-50 text-left text-gray-600"><tr><th class="px-4 py-2">N°</th><th class="px-4 py-2">Date</th><th class="px-4 py-2">Fournisseur</th><th class="px-4 py-2">Lignes</th><th class="px-4 py-2 text-right">Montant</th><th class="px-4 py-2">Statut</th></tr></thead>
        <tbody class="divide-y">
        @forelse($commandes as $c)
            <tr>
                <td class="px-4 py-2 font-mono text-xs"><a href="{{ route('grand-depot.commandes.show', $c) }}" class="text-blue-700 hover:underline">{{ $c->numero }}</a></td>
                <td class="px-4 py-2">{{ $c->date_commande->format('d/m/Y') }}</td>
                <td class="px-4 py-2">{{ $c->fournisseur?->nom }}</td>
                <td class="px-4 py-2">{{ $c->lignes->count() }}</td>
                <td class="px-4 py-2 text-right">{{ number_format($c->montant(), 2, ',', ' ') }} {{ $c->devise }}</td>
                <td class="px-4 py-2">{{ \App\Models\CommandeFournisseur::STATUTS[$c->statut] }}</td>
            </tr>
        @empty
            <tr><td colspan="6" class="px-4 py-8 text-center text-gray-400">Aucune commande.</td></tr>
        @endforelse
        </tbody>
    </table>
    <div class="px-4 py-3 border-t">{{ $commandes->links() }}</div>
</div>
@endsection
