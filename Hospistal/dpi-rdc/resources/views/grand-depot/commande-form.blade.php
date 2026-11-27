@extends('layouts.app')
@section('title', 'Nouveau bon de commande')
@section('content')
@include('grand-depot._onglets')
<h3 class="text-xl font-semibold mb-3">Nouveau bon de commande</h3>
@if($fournisseurs->isEmpty())
<div class="bg-amber-50 border border-amber-200 rounded-lg p-4 text-sm">Ajoutez d'abord un <a href="{{ route('grand-depot.fournisseurs') }}" class="text-blue-700 underline">fournisseur</a>.</div>
@else
@php $lignes = old('lignes', $propositions->map(fn ($p) => ['medicament_id' => $p->medicament->id, 'quantite' => $p->a_commander, 'prix_unitaire' => ''])->all()); $nbVides = max(5, 3); @endphp
<form method="POST" action="{{ route('grand-depot.commandes.store') }}" class="space-y-4">
    @csrf
    <div class="bg-white rounded-xl shadow p-4 grid md:grid-cols-4 gap-3">
        <div class="md:col-span-2"><label for="c-f" class="block text-sm font-semibold mb-1">Fournisseur <span class="text-red-500">*</span></label>
            <select id="c-f" name="fournisseur_id" required class="w-full min-h-[44px] border rounded-lg px-3"><option value="">— Choisir —</option>@foreach($fournisseurs as $f)<option value="{{ $f->id }}" @selected(old('fournisseur_id') === $f->id)>{{ $f->nom }}</option>@endforeach</select></div>
        <div><label for="c-liv" class="block text-sm font-semibold mb-1">Livraison prévue</label><input id="c-liv" type="date" name="date_livraison_prevue" value="{{ old('date_livraison_prevue') }}" class="w-full min-h-[44px] border rounded-lg px-3"></div>
        <div><label for="c-dev" class="block text-sm font-semibold mb-1">Devise</label><select id="c-dev" name="devise" class="w-full min-h-[44px] border rounded-lg px-3"><option value="CDF">CDF</option><option value="USD" @selected(old('devise') === 'USD')>USD</option></select></div>
        <div class="md:col-span-4"><label for="c-obs" class="block text-sm font-semibold mb-1">Observations</label><input id="c-obs" name="observations" value="{{ old('observations') }}" class="w-full min-h-[44px] border rounded-lg px-3"></div>
    </div>
    <div class="bg-white rounded-xl shadow p-4 overflow-x-auto">
        <table class="w-full text-sm">
            <thead><tr class="text-left text-gray-600"><th class="py-1">Produit</th><th class="py-1 w-32">Quantité</th><th class="py-1 w-36">Prix unitaire</th></tr></thead>
            <tbody>
            @foreach(array_merge(array_values($lignes), array_fill(0, $nbVides, [])) as $i => $l)
                <tr>
                    <td class="py-1 pr-2"><label class="sr-only" for="cl-m-{{ $i }}">Produit {{ $i + 1 }}</label>
                        <select id="cl-m-{{ $i }}" name="lignes[{{ $i }}][medicament_id]" class="w-full min-h-[40px] border rounded px-2"><option value="">—</option>@foreach($medicaments as $m)<option value="{{ $m->id }}" @selected(($l['medicament_id'] ?? null) === $m->id)>{{ $m->denomination_commune }} {{ $m->dosage }} {{ $m->forme }}</option>@endforeach</select></td>
                    <td class="py-1 pr-2"><label class="sr-only" for="cl-q-{{ $i }}">Quantité {{ $i + 1 }}</label><input id="cl-q-{{ $i }}" name="lignes[{{ $i }}][quantite]" type="number" step="0.01" min="0" value="{{ $l['quantite'] ?? '' }}" class="w-full min-h-[40px] border rounded px-2 text-right"></td>
                    <td class="py-1"><label class="sr-only" for="cl-p-{{ $i }}">Prix {{ $i + 1 }}</label><input id="cl-p-{{ $i }}" name="lignes[{{ $i }}][prix_unitaire]" type="number" step="0.01" min="0" value="{{ $l['prix_unitaire'] ?? '' }}" class="w-full min-h-[40px] border rounded px-2 text-right"></td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
    <button class="min-h-[44px] px-6 bg-blue-700 hover:bg-blue-800 text-white rounded-lg font-semibold">💾 Enregistrer le brouillon</button>
</form>
@endif
@endsection
