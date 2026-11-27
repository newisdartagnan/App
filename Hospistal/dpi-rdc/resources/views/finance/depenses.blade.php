@extends('layouts.app')
@section('title', 'Dépenses')
@section('content')
@include('finance._onglets')
<div class="flex flex-wrap items-end justify-between gap-2 mb-3">
    <form method="GET" class="flex flex-wrap gap-2 items-end">
        <div><label for="dp-du" class="block text-xs text-gray-600 mb-1">Du</label><input id="dp-du" type="date" name="du" value="{{ $du }}" class="min-h-[44px] border rounded-lg px-3"></div>
        <div><label for="dp-au" class="block text-xs text-gray-600 mb-1">Au</label><input id="dp-au" type="date" name="au" value="{{ $au }}" class="min-h-[44px] border rounded-lg px-3"></div>
        <div><label for="dp-st" class="block text-xs text-gray-600 mb-1">Statut</label><select id="dp-st" name="statut" class="min-h-[44px] border rounded-lg px-3"><option value="">Tous</option>@foreach(\App\Models\Depense::STATUTS as $k => $v)<option value="{{ $k }}" @selected($statut === $k)>{{ $v }}</option>@endforeach</select></div>
        <div><label for="dp-rub" class="block text-xs text-gray-600 mb-1">Rubrique</label><select id="dp-rub" name="rubrique" class="min-h-[44px] border rounded-lg px-3"><option value="">Toutes</option>@foreach($rubriques as $r)<option value="{{ $r->id }}" @selected($rubrique === $r->id)>{{ $r->libelle }}</option>@endforeach</select></div>
        <button class="min-h-[44px] px-4 bg-gray-100 hover:bg-gray-200 rounded-lg">Filtrer</button>
        <a href="{{ request()->fullUrlWithQuery(['format' => 'csv']) }}" class="min-h-[44px] inline-flex items-center px-4 rounded-lg border bg-white">⬇ CSV</a>
    </form>
    @if(auth()->user()->can('finance.depense') || auth()->user()->can('finance.valider') || auth()->user()->hasAnyRole(['super_admin', 'directeur']))
    <a href="{{ route('finance.depenses.create') }}" class="min-h-[44px] inline-flex items-center px-5 bg-blue-700 hover:bg-blue-800 text-white rounded-lg font-semibold">⊕ Nouvelle dépense</a>
    @endif
</div>
<p class="text-sm mb-2">Total sur la période (hors rejets) : <strong>{{ number_format($total, 0, ',', ' ') }} FC</strong></p>
@php $peutValider = auth()->user()->can('finance.valider') || auth()->user()->hasAnyRole(['super_admin', 'directeur']); $peutPayer = $peutValider || auth()->user()->can('finance.depense'); @endphp
<div class="bg-white rounded-xl shadow overflow-x-auto">
    <table class="w-full text-sm">
        <thead class="bg-gray-50 text-left text-gray-600"><tr><th class="px-3 py-2">N°</th><th class="px-3 py-2">Date</th><th class="px-3 py-2">Rubrique</th><th class="px-3 py-2">Libellé</th><th class="px-3 py-2 text-right">Montant</th><th class="px-3 py-2">Statut</th><th class="px-3 py-2">Actions</th></tr></thead>
        <tbody class="divide-y">
        @forelse($depenses as $d)
            <tr class="align-top">
                <td class="px-3 py-2 font-mono text-xs">{{ $d->numero }}</td>
                <td class="px-3 py-2">{{ $d->date_depense->format('d/m/Y') }}</td>
                <td class="px-3 py-2">{{ $d->rubrique?->libelle }}@if($d->service)<div class="text-xs text-gray-500">{{ $d->service->nom }}</div>@endif</td>
                <td class="px-3 py-2">{{ $d->libelle }}<div class="text-xs text-gray-500">{{ $d->beneficiaire }} {{ $d->reference_piece }} — saisie par {{ $d->saisiePar?->nom_complet }}</div>
                    @if($d->piece_path)<a href="{{ route('finance.depenses.piece', $d) }}" class="text-xs text-blue-700 hover:underline">📎 Pièce</a>@endif
                    @if($d->statut === 'rejetee')<div class="text-xs text-red-700">Rejet : {{ $d->motif_rejet }}</div>@endif</td>
                <td class="px-3 py-2 text-right whitespace-nowrap">{{ number_format((float) $d->montant, 2, ',', ' ') }} {{ $d->devise }}@if($d->devise !== 'CDF')<div class="text-xs text-gray-500">{{ number_format((float) $d->montant_cdf, 0, ',', ' ') }} FC</div>@endif</td>
                <td class="px-3 py-2"><span class="px-2 py-0.5 rounded-full text-xs {{ ['en_attente' => 'bg-amber-100 text-amber-800', 'approuvee' => 'bg-blue-100 text-blue-800', 'payee' => 'bg-green-100 text-green-800', 'rejetee' => 'bg-gray-200 text-gray-700'][$d->statut] }}">{{ \App\Models\Depense::STATUTS[$d->statut] }}</span>@if($d->compte)<div class="text-xs text-gray-500">{{ $d->compte->nom }}</div>@endif</td>
                <td class="px-3 py-2">
                    @if($d->statut === 'en_attente' && $peutValider)
                    <form method="POST" action="{{ route('finance.depenses.approuver', $d) }}" class="inline">@csrf<button class="text-xs text-green-700 font-semibold hover:underline">✓ Approuver</button></form>
                    @endif
                    @if(in_array($d->statut, ['en_attente', 'approuvee'], true) && $peutValider)
                    <details class="inline-block ml-2"><summary class="text-xs text-red-700 cursor-pointer">Rejeter</summary>
                        <form method="POST" action="{{ route('finance.depenses.rejeter', $d) }}" class="mt-1 flex gap-1">@csrf<label class="sr-only" for="rj-{{ $d->id }}">Motif</label><input id="rj-{{ $d->id }}" name="motif_rejet" required placeholder="Motif" class="border rounded px-2 text-xs min-h-[32px]"><button class="text-xs px-2 bg-red-700 text-white rounded">OK</button></form></details>
                    @endif
                    @if($d->statut === 'approuvee' && $peutPayer)
                    <form method="POST" action="{{ route('finance.depenses.payer', $d) }}" class="mt-1 flex gap-1">@csrf
                        <label class="sr-only" for="pc-{{ $d->id }}">Compte</label>
                        <select id="pc-{{ $d->id }}" name="compte_tresorerie_id" class="border rounded px-1 text-xs min-h-[32px]">@foreach($comptes as $c)<option value="{{ $c->id }}">{{ $c->nom }}</option>@endforeach</select>
                        <button class="text-xs px-2 bg-blue-700 text-white rounded">💸 Payer</button></form>
                    @endif
                </td>
            </tr>
        @empty
            <tr><td colspan="7" class="px-3 py-8 text-center text-gray-400">Aucune dépense.</td></tr>
        @endforelse
        </tbody>
    </table>
    <div class="px-4 py-3 border-t">{{ $depenses->links() }}</div>
</div>
@endsection
