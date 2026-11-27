@extends('layouts.app')
@section('title', 'Catégories de convention')
@section('content')
@include('tarification._onglets')
<h2 class="text-2xl font-bold text-gray-800 mb-1">Catégories de convention et coefficients</h2>
<p class="text-sm text-gray-500 mb-4">Prix conventionné = prix de base de l'acte × coefficient de la catégorie de la convention, dans la colonne de la <strong>fonction tarifaire</strong> de l'acte (la 1<sup>re</sup> colonne est la fonction 1).</p>
@include('catalogue._erreurs')

<div class="bg-white rounded-xl shadow overflow-x-auto mb-6">
    <table class="w-full text-sm">
        <thead class="bg-gray-50 text-left text-gray-600"><tr>
            <th class="px-2 py-2">N°</th><th class="px-2 py-2">Nom</th><th class="px-2 py-2">Commentaire</th>
            @for($k = 1; $k <= $nbFonctions; $k++)<th class="px-2 py-2 text-center">Coeff. {{ $k }}</th>@endfor
            <th class="px-2 py-2">Conventions</th><th></th>
        </tr></thead>
        <tbody class="divide-y">
        @foreach($categories as $c)
            <tr>
                <td class="px-2 py-1 text-gray-500">{{ $c->numero }}</td>
                <td colspan="{{ $nbFonctions + 4 }}" class="p-0">
                    <form method="POST" action="{{ route('tarification.categories.update', $c) }}" class="flex items-center gap-1 px-2 py-1">
                        @csrf
                        <label class="sr-only" for="cat-nom-{{ $c->id }}">Nom</label>
                        <input id="cat-nom-{{ $c->id }}" name="nom" value="{{ $c->nom }}" class="w-16 min-h-[36px] border rounded px-2 font-semibold">
                        <label class="sr-only" for="cat-com-{{ $c->id }}">Commentaire</label>
                        <input id="cat-com-{{ $c->id }}" name="commentaire" value="{{ $c->commentaire }}" class="w-36 min-h-[36px] border rounded px-2">
                        @for($k = 1; $k <= $nbFonctions; $k++)
                        <label class="sr-only" for="cat-{{ $c->id }}-{{ $k }}">Coefficient {{ $k }}</label>
                        <input id="cat-{{ $c->id }}-{{ $k }}" name="coefficients[]" value="{{ rtrim(rtrim(number_format($c->coefficient($k), 4, '.', ''), '0'), '.') }}" inputmode="decimal" class="w-20 min-h-[36px] border rounded px-1 text-right font-mono text-xs">
                        @endfor
                        <span class="w-20 text-center text-xs">{{ $c->assurances_count }}</span>
                        <button class="min-h-[36px] px-3 bg-blue-700 text-white rounded text-xs font-semibold">💾</button>
                    </form>
                </td>
                <td class="px-2 py-1">
                    <form method="POST" action="{{ route('tarification.categories.destroy', $c) }}" onsubmit="return confirm('Supprimer la catégorie {{ $c->nom }} ?');">@csrf @method('DELETE')
                        <button class="text-red-700 text-xs" @disabled($c->assurances_count > 0)>🗑</button></form>
                </td>
            </tr>
        @endforeach
            <tr class="bg-blue-50/50">
                <td class="px-2 py-1 text-gray-500">+</td>
                <td colspan="{{ $nbFonctions + 5 }}" class="p-0">
                    <form method="POST" action="{{ route('tarification.categories.store') }}" class="flex items-center gap-1 px-2 py-1">
                        @csrf
                        <label class="sr-only" for="cat-nv">Nom</label>
                        <input id="cat-nv" name="nom" placeholder="Nom" required class="w-16 min-h-[36px] border rounded px-2 font-semibold">
                        <label class="sr-only" for="cat-nv-com">Commentaire</label>
                        <input id="cat-nv-com" name="commentaire" placeholder="Commentaire" class="w-36 min-h-[36px] border rounded px-2">
                        @for($k = 1; $k <= $nbFonctions; $k++)
                        <label class="sr-only" for="cat-nv-{{ $k }}">Coefficient {{ $k }}</label>
                        <input id="cat-nv-{{ $k }}" name="coefficients[]" value="1" inputmode="decimal" class="w-20 min-h-[36px] border rounded px-1 text-right font-mono text-xs">
                        @endfor
                        <button class="min-h-[36px] px-3 bg-green-700 text-white rounded text-xs font-semibold">⊕ Ajouter</button>
                    </form>
                </td>
            </tr>
        </tbody>
    </table>
</div>

<h3 class="text-lg font-semibold text-gray-800 mb-2">Classement des conventions</h3>
<div class="bg-white rounded-xl shadow overflow-x-auto">
    <table class="w-full text-sm">
        <thead class="bg-gray-50 text-left text-gray-600"><tr><th class="px-4 py-2">Convention / société</th><th class="px-4 py-2">Couverture</th><th class="px-4 py-2">Catégorie</th></tr></thead>
        <tbody class="divide-y">
        @forelse($conventions as $a)
            <tr>
                <td class="px-4 py-2 font-medium">{{ $a->nom }} <span class="text-xs text-gray-400 font-mono">{{ $a->code }}</span></td>
                <td class="px-4 py-2">{{ $a->taux_couverture + 0 }} %</td>
                <td class="px-4 py-2">
                    <form method="POST" action="{{ route('tarification.conventions.classer', $a) }}" class="flex gap-2">@csrf
                        <label class="sr-only" for="cl-{{ $a->id }}">Catégorie de {{ $a->nom }}</label>
                        <select id="cl-{{ $a->id }}" name="categorie_tarifaire_id" class="min-h-[36px] border rounded px-2">
                            <option value="">— Sans catégorie ({{ $a->libelleCoefficient() }}) —</option>
                            @foreach($categories as $c)<option value="{{ $c->id }}" @selected($a->categorie_tarifaire_id === $c->id)>Catégorie {{ $c->nom }}</option>@endforeach
                        </select>
                        <button class="min-h-[36px] px-3 border rounded text-xs font-semibold text-blue-700">Classer</button>
                    </form>
                </td>
            </tr>
        @empty
            <tr><td colspan="3" class="px-4 py-6 text-center text-gray-400">Aucune convention enregistrée.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@endsection
