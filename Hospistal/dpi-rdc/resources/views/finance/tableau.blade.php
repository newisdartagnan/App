@extends('layouts.app')
@section('title', 'Gestion financière')
@section('content')
@include('finance._onglets')
@php $fc = fn ($n) => number_format((float) $n, 0, ',', ' ').' FC'; @endphp
<div class="grid grid-cols-1 md:grid-cols-3 gap-3 mb-4">
    <div class="rounded-xl border p-4 bg-green-50"><p class="text-sm text-green-700">Recettes du mois</p><p class="text-2xl font-bold text-green-900">{{ $fc($resultat['recettes']) }}</p></div>
    <div class="rounded-xl border p-4 bg-red-50"><p class="text-sm text-red-700">Dépenses payées du mois</p><p class="text-2xl font-bold text-red-900">{{ $fc($resultat['depenses']) }}</p></div>
    <div class="rounded-xl border p-4 {{ $resultat['resultat'] >= 0 ? 'bg-blue-50' : 'bg-amber-50' }}"><p class="text-sm">Résultat du mois</p><p class="text-2xl font-bold">{{ $fc($resultat['resultat']) }}</p></div>
</div>
<div class="grid md:grid-cols-2 gap-4 items-start">
    <section class="bg-white border rounded-xl shadow-sm p-4">
        <h3 class="font-semibold text-green-800 mb-2">Soldes de trésorerie</h3>
        <table class="w-full text-sm"><tbody class="divide-y">
            @foreach($comptes as $c)<tr><td class="py-2"><a href="{{ route('finance.journal', $c->compte) }}" class="text-blue-700 hover:underline">{{ $c->compte->nom }}</a> <span class="text-xs text-gray-500">{{ \App\Models\CompteTresorerie::TYPES[$c->compte->type] }}</span></td><td class="py-2 text-right font-semibold {{ $c->solde < 0 ? 'text-red-700' : '' }}">{{ $fc($c->solde) }}</td></tr>@endforeach
            <tr class="font-bold"><td class="py-2">Total</td><td class="py-2 text-right">{{ $fc($comptes->sum('solde')) }}</td></tr>
        </tbody></table>
    </section>
    <section class="bg-white border rounded-xl shadow-sm p-4">
        <h3 class="font-semibold text-green-800 mb-2">Dépenses en attente d'approbation ({{ $enAttente->count() }})</h3>
        @forelse($enAttente as $d)<p class="text-sm py-1 border-b"><span class="font-mono text-xs">{{ $d->numero }}</span> — {{ $d->libelle }} — <strong>{{ number_format((float) $d->montant, 2, ',', ' ') }} {{ $d->devise }}</strong></p>@empty<p class="text-sm text-gray-500">Rien en attente.</p>@endforelse
        <h3 class="font-semibold text-green-800 mt-4 mb-2">Approuvées, à payer ({{ $aPayer->count() }})</h3>
        @forelse($aPayer as $d)<p class="text-sm py-1 border-b"><span class="font-mono text-xs">{{ $d->numero }}</span> — {{ $d->libelle }} — <strong>{{ number_format((float) $d->montant, 2, ',', ' ') }} {{ $d->devise }}</strong></p>@empty<p class="text-sm text-gray-500">Rien à payer.</p>@endforelse
        <a href="{{ route('finance.depenses') }}" class="inline-block mt-2 text-sm text-blue-700 hover:underline">Toutes les dépenses →</a>
    </section>
    <section class="bg-white border rounded-xl shadow-sm p-4">
        <h3 class="font-semibold text-green-800 mb-2">Fournisseurs à payer</h3>
        @forelse($fournisseursAPayer as $r)
        <p class="text-sm py-1 border-b flex justify-between gap-2"><span>{{ $r->fournisseur?->nom ?? '—' }} — <a href="{{ route('grand-depot.receptions.show', $r) }}" class="text-blue-700 hover:underline font-mono text-xs">{{ $r->numero }}</a></span><strong>{{ number_format($r->resteAPayer(), 2, ',', ' ') }} {{ $r->devise }}</strong></p>
        @empty<p class="text-sm text-gray-500">Aucune dette fournisseur.</p>@endforelse
    </section>
    <section class="bg-white border rounded-xl shadow-sm p-4">
        <h3 class="font-semibold text-green-800 mb-2">Exécution du budget {{ now()->year }}</h3>
        @forelse($budget as $b)
        <div class="py-1 text-sm">
            <div class="flex justify-between"><span>{{ $b->rubrique->libelle }}</span><span>{{ $b->taux !== null ? $b->taux.' %' : 'non budgété' }}</span></div>
            @if($b->taux !== null)<div class="h-2 bg-gray-100 rounded"><div class="h-2 rounded {{ $b->taux > 100 ? 'bg-red-500' : 'bg-blue-600' }}" style="width: {{ min(100, $b->taux) }}%"></div></div>@endif
        </div>
        @empty<p class="text-sm text-gray-500">Aucun budget saisi. <a href="{{ route('finance.budget') }}" class="text-blue-700 hover:underline">Saisir le budget</a></p>@endforelse
    </section>
</div>
@endsection
