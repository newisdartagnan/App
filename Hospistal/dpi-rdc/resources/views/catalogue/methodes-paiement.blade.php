@extends('layouts.app')
@section('title', 'Méthodes de paiement')
@section('content')
@include('catalogue._onglets')
<h2 class="text-2xl font-bold text-gray-800 mb-1">Méthodes de paiement ({{ $methodes->count() }})</h2>
<p class="text-sm text-gray-500 mb-4">Une méthode créée pour un patient se réutilise pour les autres. Liée à une assurance ou une convention, elle active le tiers payant.</p>
@include('catalogue._erreurs')
<form method="POST" action="{{ route('methodes-paiement.store') }}" class="bg-white rounded-xl shadow p-4 mb-4 grid md:grid-cols-4 gap-3 items-end">
    @csrf
    <div class="md:col-span-2"><label for="mp-lib" class="block text-sm font-semibold mb-1">Libellé de la méthode <span class="text-red-500">*</span></label>
        <input id="mp-lib" name="libelle" required class="w-full min-h-[44px] border rounded-lg px-3" placeholder="Ex. : Abonné mutuelle Solidarité"></div>
    <div><label for="mp-type" class="block text-sm font-semibold mb-1">Type</label>
        <select id="mp-type" name="type" class="w-full min-h-[44px] border rounded-lg px-3">@foreach(\App\Models\MethodePaiement::TYPES as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach</select></div>
    <div><label for="mp-ass" class="block text-sm font-semibold mb-1">Assurance / convention liée</label>
        <select id="mp-ass" name="assurance_id" class="w-full min-h-[44px] border rounded-lg px-3"><option value="">— Aucune —</option>@foreach($assurances as $a)<option value="{{ $a->id }}">{{ $a->nom }}</option>@endforeach</select></div>
    <div><button class="min-h-[44px] px-5 bg-blue-700 hover:bg-blue-800 text-white rounded-lg font-semibold">⊕ Ajouter</button></div>
</form>
<div class="bg-white rounded-xl shadow overflow-x-auto">
    <table class="w-full text-sm">
        <thead class="bg-gray-50 text-left text-gray-600"><tr><th class="px-4 py-3">Libellé</th><th class="px-4 py-3">Type</th><th class="px-4 py-3">Assurance liée</th><th class="px-4 py-3">Patients</th><th class="px-4 py-3">État</th><th></th></tr></thead>
        <tbody class="divide-y">
        @forelse($methodes as $m)
            <tr class="{{ $m->est_actif ? '' : 'opacity-50' }}">
                <td class="px-4 py-2 font-semibold">{{ $m->libelle }}</td>
                <td class="px-4 py-2">{{ \App\Models\MethodePaiement::TYPES[$m->type] ?? $m->type }}</td>
                <td class="px-4 py-2">{{ $m->assurance?->nom ?? '—' }}</td>
                <td class="px-4 py-2">{{ $m->liens_count }}</td>
                <td class="px-4 py-2">{{ $m->est_actif ? 'Active' : 'Désactivée' }}</td>
                <td class="px-4 py-2 text-right"><form method="POST" action="{{ route('methodes-paiement.basculer', $m) }}">@csrf<button class="text-xs {{ $m->est_actif ? 'text-red-700' : 'text-green-700' }} hover:underline">{{ $m->est_actif ? 'Désactiver' : 'Réactiver' }}</button></form></td>
            </tr>
        @empty
            <tr><td colspan="6" class="px-4 py-8 text-center text-gray-400">Aucune méthode encore.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@endsection
