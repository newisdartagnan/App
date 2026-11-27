@extends('layouts.app')
@section('title', 'Trésorerie')
@section('content')
@include('finance._onglets')
<div class="grid md:grid-cols-3 gap-4 items-start">
    <section class="md:col-span-2 bg-white rounded-xl shadow overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-left text-gray-600"><tr><th class="px-3 py-2">Compte</th><th class="px-3 py-2">Type</th><th class="px-3 py-2">Reçoit les paiements</th><th class="px-3 py-2 text-right">Solde</th><th></th></tr></thead>
            <tbody class="divide-y">
            @foreach($comptes as $c)
                <tr>
                    <td class="px-3 py-2 font-semibold">{{ $c->compte->nom }}<div class="text-xs text-gray-500 font-normal">{{ $c->compte->numero }}</div></td>
                    <td class="px-3 py-2">{{ \App\Models\CompteTresorerie::TYPES[$c->compte->type] }}</td>
                    <td class="px-3 py-2 text-xs">{{ collect($c->compte->modes_paiement)->map(fn ($m) => \App\Models\CompteTresorerie::MODES[$m] ?? $m)->implode(', ') ?: '—' }}</td>
                    <td class="px-3 py-2 text-right font-semibold {{ $c->solde < 0 ? 'text-red-700' : '' }}">{{ number_format($c->solde, 0, ',', ' ') }} FC</td>
                    <td class="px-3 py-2 text-right"><a href="{{ route('finance.journal', $c->compte) }}" class="text-xs text-blue-700 hover:underline">Journal</a></td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </section>
    <section class="bg-white rounded-xl shadow p-4">
        <h3 class="font-semibold text-green-800 mb-2">Transfert entre comptes</h3>
        <p class="text-xs text-gray-500 mb-2">Ex. : versement de la caisse à la banque.</p>
        <form method="POST" action="{{ route('finance.transferts.store') }}" class="space-y-2">
            @csrf
            <label for="tr-src" class="block text-xs font-semibold">De</label><select id="tr-src" name="compte_source_id" class="w-full min-h-[40px] border rounded-lg px-2">@foreach($comptes as $c)<option value="{{ $c->compte->id }}">{{ $c->compte->nom }}</option>@endforeach</select>
            <label for="tr-dst" class="block text-xs font-semibold">Vers</label><select id="tr-dst" name="compte_destination_id" class="w-full min-h-[40px] border rounded-lg px-2">@foreach($comptes as $c)<option value="{{ $c->compte->id }}" @selected($loop->last)>{{ $c->compte->nom }}</option>@endforeach</select>
            <label for="tr-mt" class="block text-xs font-semibold">Montant (FC)</label><input id="tr-mt" name="montant_cdf" type="number" step="0.01" min="1" required class="w-full min-h-[40px] border rounded-lg px-2">
            <label for="tr-dt" class="block text-xs font-semibold">Date</label><input id="tr-dt" name="date_transfert" type="date" value="{{ today()->toDateString() }}" required class="w-full min-h-[40px] border rounded-lg px-2">
            <label for="tr-ref" class="block text-xs font-semibold">Référence (bordereau)</label><input id="tr-ref" name="reference" class="w-full min-h-[40px] border rounded-lg px-2">
            <button class="min-h-[40px] px-4 bg-blue-700 text-white rounded-lg text-sm font-semibold">Enregistrer le transfert</button>
        </form>
    </section>
</div>
@if(auth()->user()->can('finance.valider') || auth()->user()->hasAnyRole(['super_admin', 'directeur']))
<details class="bg-white rounded-xl shadow p-4 mt-4">
    <summary class="font-semibold text-blue-700 cursor-pointer">⊕ Ajouter un compte de trésorerie</summary>
    <form method="POST" action="{{ route('finance.comptes.store') }}" class="mt-3 grid md:grid-cols-4 gap-3 items-end">
        @csrf
        <div><label for="ct-nom" class="block text-sm font-semibold mb-1">Nom</label><input id="ct-nom" name="nom" required class="w-full min-h-[44px] border rounded-lg px-3"></div>
        <div><label for="ct-type" class="block text-sm font-semibold mb-1">Type</label><select id="ct-type" name="type" class="w-full min-h-[44px] border rounded-lg px-3">@foreach(\App\Models\CompteTresorerie::TYPES as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach</select></div>
        <div><label for="ct-num" class="block text-sm font-semibold mb-1">N° de compte</label><input id="ct-num" name="numero" class="w-full min-h-[44px] border rounded-lg px-3"></div>
        <div><label for="ct-solde" class="block text-sm font-semibold mb-1">Solde initial (FC)</label><input id="ct-solde" name="solde_initial" type="number" step="0.01" class="w-full min-h-[44px] border rounded-lg px-3"></div>
        <div class="md:col-span-3"><span class="block text-sm font-semibold mb-1">Reçoit les encaissements par</span>@foreach(\App\Models\CompteTresorerie::MODES as $k => $v)<label class="mr-4 text-sm"><input type="checkbox" name="modes_paiement[]" value="{{ $k }}"> {{ $v }}</label>@endforeach</div>
        <div><button class="min-h-[44px] px-5 bg-blue-700 text-white rounded-lg font-semibold">Ajouter</button></div>
    </form>
</details>
@endif
<h3 class="font-semibold mt-6 mb-2">Derniers transferts</h3>
<div class="bg-white rounded-xl shadow overflow-x-auto">
    <table class="w-full text-sm"><thead class="bg-gray-50 text-left"><tr><th class="px-3 py-2">N°</th><th class="px-3 py-2">Date</th><th class="px-3 py-2">De</th><th class="px-3 py-2">Vers</th><th class="px-3 py-2 text-right">Montant</th></tr></thead>
        <tbody class="divide-y">@forelse($transferts as $t)<tr><td class="px-3 py-2 font-mono text-xs">{{ $t->numero }}</td><td class="px-3 py-2">{{ $t->date_transfert->format('d/m/Y') }}</td><td class="px-3 py-2">{{ $t->source?->nom }}</td><td class="px-3 py-2">{{ $t->destination?->nom }}</td><td class="px-3 py-2 text-right">{{ number_format((float) $t->montant_cdf, 0, ',', ' ') }} FC</td></tr>@empty<tr><td colspan="5" class="px-3 py-6 text-center text-gray-400">Aucun transfert.</td></tr>@endforelse</tbody></table>
</div>
@endsection
