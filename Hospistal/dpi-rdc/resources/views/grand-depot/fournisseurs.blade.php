@extends('layouts.app')
@section('title', 'Fournisseurs')
@section('content')
@include('grand-depot._onglets')
<form method="POST" action="{{ route('grand-depot.fournisseurs.store') }}" class="bg-white rounded-xl shadow p-4 mb-4 grid md:grid-cols-4 gap-3 items-end">
    @csrf
    <div class="md:col-span-2"><label for="f-nom" class="block text-sm font-semibold mb-1">Nom du fournisseur <span class="text-red-500">*</span></label><input id="f-nom" name="nom" required class="w-full min-h-[44px] border rounded-lg px-3"></div>
    <div><label for="f-contact" class="block text-sm font-semibold mb-1">Contact</label><input id="f-contact" name="contact" class="w-full min-h-[44px] border rounded-lg px-3"></div>
    <div><label for="f-tel" class="block text-sm font-semibold mb-1">Téléphone</label><input id="f-tel" name="telephone" class="w-full min-h-[44px] border rounded-lg px-3"></div>
    <div><label for="f-mail" class="block text-sm font-semibold mb-1">Email</label><input id="f-mail" name="email" type="email" class="w-full min-h-[44px] border rounded-lg px-3"></div>
    <div><label for="f-nif" class="block text-sm font-semibold mb-1">N° impôt / RCCM</label><input id="f-nif" name="identifiant_fiscal" class="w-full min-h-[44px] border rounded-lg px-3"></div>
    <div><label for="f-delai" class="block text-sm font-semibold mb-1">Délai de livraison (jours)</label><input id="f-delai" name="delai_livraison_jours" type="number" min="0" class="w-full min-h-[44px] border rounded-lg px-3"></div>
    <div><button class="min-h-[44px] px-5 bg-blue-700 hover:bg-blue-800 text-white rounded-lg font-semibold">⊕ Ajouter</button></div>
</form>
<div class="bg-white rounded-xl shadow overflow-x-auto">
    <table class="w-full text-sm">
        <thead class="bg-gray-50 text-left text-gray-600"><tr><th class="px-4 py-2">Fournisseur</th><th class="px-4 py-2">Contact</th><th class="px-4 py-2">Délai</th><th class="px-4 py-2">Commandes</th><th class="px-4 py-2">Réceptions</th><th></th></tr></thead>
        <tbody class="divide-y">
        @forelse($fournisseurs as $f)
            <tr class="{{ $f->est_actif ? '' : 'opacity-50' }}">
                <td class="px-4 py-2 font-semibold">{{ $f->nom }}<div class="text-xs text-gray-500 font-normal">{{ $f->identifiant_fiscal }}</div></td>
                <td class="px-4 py-2">{{ $f->contact }} {{ $f->telephone }} <span class="text-xs text-gray-500">{{ $f->email }}</span></td>
                <td class="px-4 py-2">{{ $f->delai_livraison_jours ? $f->delai_livraison_jours.' j' : '—' }}</td>
                <td class="px-4 py-2">{{ $f->commandes_count }}</td>
                <td class="px-4 py-2">{{ $f->receptions_count }}</td>
                <td class="px-4 py-2 text-right"><form method="POST" action="{{ route('grand-depot.fournisseurs.basculer', $f) }}">@csrf<button class="text-xs {{ $f->est_actif ? 'text-red-700' : 'text-green-700' }} hover:underline">{{ $f->est_actif ? 'Désactiver' : 'Réactiver' }}</button></form></td>
            </tr>
        @empty
            <tr><td colspan="6" class="px-4 py-8 text-center text-gray-400">Aucun fournisseur.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@endsection
