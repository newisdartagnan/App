@extends('layouts.app')
@section('title', 'Réception au dépôt')
@section('content')
@include('grand-depot._onglets')
<h3 class="text-xl font-semibold mb-3">Réception sans commande</h3>
<form method="POST" action="{{ route('grand-depot.receptions.store') }}" class="space-y-4">
    @csrf
    <div class="bg-white rounded-xl shadow p-4 grid md:grid-cols-4 gap-3">
        <div><label for="rf-or" class="block text-sm font-semibold mb-1">Origine</label><select id="rf-or" name="origine" class="w-full min-h-[44px] border rounded-lg px-3">@foreach(\App\Models\ReceptionStock::ORIGINES as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach</select></div>
        <div><label for="rf-f" class="block text-sm font-semibold mb-1">Fournisseur / donateur</label><select id="rf-f" name="fournisseur_id" class="w-full min-h-[44px] border rounded-lg px-3"><option value="">—</option>@foreach($fournisseurs as $f)<option value="{{ $f->id }}">{{ $f->nom }}</option>@endforeach</select></div>
        <div><label for="rf-d" class="block text-sm font-semibold mb-1">Date</label><input id="rf-d" type="date" name="date_reception" value="{{ today()->toDateString() }}" max="{{ today()->toDateString() }}" required class="w-full min-h-[44px] border rounded-lg px-3"></div>
        <div><label for="rf-dev" class="block text-sm font-semibold mb-1">Devise</label><select id="rf-dev" name="devise" class="w-full min-h-[44px] border rounded-lg px-3"><option>CDF</option><option>USD</option></select></div>
        <div><label for="rf-bl" class="block text-sm font-semibold mb-1">N° bon de livraison</label><input id="rf-bl" name="numero_bon_livraison" class="w-full min-h-[44px] border rounded-lg px-3"></div>
        <div><label for="rf-fac" class="block text-sm font-semibold mb-1">N° facture</label><input id="rf-fac" name="numero_facture_fournisseur" class="w-full min-h-[44px] border rounded-lg px-3"></div>
        <div class="md:col-span-2"><label for="rf-obs" class="block text-sm font-semibold mb-1">Observations</label><input id="rf-obs" name="observations" class="w-full min-h-[44px] border rounded-lg px-3"></div>
    </div>
    <div class="bg-white rounded-xl shadow p-4 overflow-x-auto">
        <table class="w-full text-sm">
            <thead><tr class="text-left text-gray-600"><th class="py-1">Produit</th><th class="py-1">Quantité</th><th class="py-1">Lot</th><th class="py-1">Péremption</th><th class="py-1">PU achat</th><th class="py-1">PU vente</th></tr></thead>
            <tbody>
            @for($i = 0; $i < 8; $i++)
                <tr>
                    <td class="py-1 pr-2"><label class="sr-only" for="rl-m-{{ $i }}">Produit</label><select id="rl-m-{{ $i }}" name="lignes[{{ $i }}][medicament_id]" class="w-full min-h-[40px] border rounded px-2"><option value="">—</option>@foreach($medicaments as $m)<option value="{{ $m->id }}">{{ $m->denomination_commune }} {{ $m->dosage }} {{ $m->forme }}</option>@endforeach</select></td>
                    <td class="py-1 pr-2"><label class="sr-only" for="rl-q-{{ $i }}">Quantité</label><input id="rl-q-{{ $i }}" name="lignes[{{ $i }}][quantite]" type="number" step="0.01" min="0" class="w-24 min-h-[40px] border rounded px-2 text-right"></td>
                    <td class="py-1 pr-2"><label class="sr-only" for="rl-l-{{ $i }}">Lot</label><input id="rl-l-{{ $i }}" name="lignes[{{ $i }}][lot]" class="w-28 min-h-[40px] border rounded px-2"></td>
                    <td class="py-1 pr-2"><label class="sr-only" for="rl-p-{{ $i }}">Péremption</label><input id="rl-p-{{ $i }}" name="lignes[{{ $i }}][date_peremption]" type="date" class="min-h-[40px] border rounded px-2"></td>
                    <td class="py-1 pr-2"><label class="sr-only" for="rl-a-{{ $i }}">PU achat</label><input id="rl-a-{{ $i }}" name="lignes[{{ $i }}][prix_unitaire_achat]" type="number" step="0.01" min="0" class="w-24 min-h-[40px] border rounded px-2 text-right"></td>
                    <td class="py-1"><label class="sr-only" for="rl-v-{{ $i }}">PU vente</label><input id="rl-v-{{ $i }}" name="lignes[{{ $i }}][prix_unitaire_vente]" type="number" step="0.01" min="0" class="w-24 min-h-[40px] border rounded px-2 text-right"></td>
                </tr>
            @endfor
            </tbody>
        </table>
    </div>
    <button class="min-h-[44px] px-6 bg-green-700 hover:bg-green-800 text-white rounded-lg font-semibold">📦 Enregistrer la réception</button>
</form>
@endsection
