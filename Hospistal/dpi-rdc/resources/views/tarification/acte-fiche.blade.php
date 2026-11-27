@extends('layouts.app')
@section('title', 'Fiche acte')
@section('content')
@include('tarification._onglets')
<a href="{{ route('tarification.actes') }}" class="text-blue-700 hover:underline text-sm">← Catalogue des actes</a>
<h2 class="text-2xl font-bold text-gray-800 mb-4">Fiche acte {{ $acte->exists ? '— '.$acte->libelle : '' }}</h2>
@include('catalogue._erreurs')
@php $devise = old('devise', $acte->devise) === 'USD' ? '$' : 'FC'; @endphp
<div class="grid lg:grid-cols-3 gap-4 items-start">
<form method="POST" action="{{ $acte->exists ? route('tarification.actes.update', $acte) : route('tarification.actes.store') }}" class="lg:col-span-2 bg-white rounded-xl shadow p-5 space-y-4">
    @csrf
    <h3 class="font-semibold text-gray-700 border-b pb-2">Signalétique</h3>
    <div class="grid md:grid-cols-4 gap-3">
        <div><label for="fa-num" class="block text-sm font-semibold mb-1">Identifiant de l'acte</label>
            <input id="fa-num" name="numero" type="number" min="1" required value="{{ old('numero', $acte->numero) }}" class="w-full min-h-[44px] border rounded-lg px-3 font-mono"></div>
        <div class="md:col-span-3"><label for="fa-lib" class="block text-sm font-semibold mb-1">Libellé <span class="text-red-500">*</span></label>
            <input id="fa-lib" name="libelle" required value="{{ old('libelle', $acte->libelle) }}" class="w-full min-h-[44px] border rounded-lg px-3"></div>
    </div>
    <fieldset class="border rounded-lg p-3 bg-rose-50/40">
        <legend class="px-1 text-sm font-semibold">Fonction tarifaire</legend>
        <div class="flex flex-wrap gap-4">
            @for($k = 1; $k <= $nbFonctions; $k++)
            <label class="inline-flex items-center gap-1 text-sm cursor-pointer"><input type="radio" name="fonction_tarifaire" value="{{ $k }}" class="h-5 w-5" @checked((int) old('fonction_tarifaire', $acte->fonction_tarifaire) === $k)> {{ $k }}</label>
            @endfor
        </div>
    </fieldset>
    <fieldset class="border rounded-lg p-3 bg-rose-50/40">
        <legend class="px-1 text-sm font-semibold">Prix</legend>
        <div class="grid sm:grid-cols-4 gap-3">
            <div><label for="fa-pn" class="block text-xs font-semibold mb-1">Normal <span class="text-red-500">*</span></label><input id="fa-pn" name="prix_normal" type="number" step="0.01" min="0" required value="{{ old('prix_normal', $acte->prix_normal) }}" class="w-full min-h-[44px] border rounded-lg px-3 text-right"></div>
            <div><label for="fa-pu" class="block text-xs font-semibold mb-1">Urgent</label><input id="fa-pu" name="prix_urgent" type="number" step="0.01" min="0" value="{{ old('prix_urgent', $acte->prix_urgent) }}" class="w-full min-h-[44px] border rounded-lg px-3 text-right"></div>
            <div><label for="fa-pe" class="block text-xs font-semibold mb-1">Externe</label><input id="fa-pe" name="prix_externe" type="number" step="0.01" min="0" value="{{ old('prix_externe', $acte->prix_externe) }}" class="w-full min-h-[44px] border rounded-lg px-3 text-right"></div>
            <div><label for="fa-dev" class="block text-xs font-semibold mb-1">Devise</label><select id="fa-dev" name="devise" class="w-full min-h-[44px] border rounded-lg px-3"><option value="USD" @selected(old('devise', $acte->devise) === 'USD')>USD ($)</option><option value="CDF" @selected(old('devise', $acte->devise) === 'CDF')>CDF (FC)</option></select></div>
        </div>
    </fieldset>
    <div class="grid md:grid-cols-2 gap-3">
        <div><label for="fa-spe" class="block text-sm font-semibold mb-1">Spécialité</label>
            <input id="fa-spe" name="specialite" list="liste-specialites" value="{{ old('specialite', $acte->specialite) }}" placeholder="Ex. : Laboratoire - Anatomo-Cytopathologie" class="w-full min-h-[44px] border rounded-lg px-3">
            <datalist id="liste-specialites">@foreach($specialites as $s)<option value="{{ $s }}">@endforeach</datalist></div>
        <div><label for="fa-dom" class="block text-sm font-semibold mb-1">Plateau où il se demande</label>
            <select id="fa-dom" name="domaine" class="w-full min-h-[44px] border rounded-lg px-3">@foreach(\App\Models\ActeCatalogue::DOMAINES as $k => $v)<option value="{{ $k }}" @selected(old('domaine', $acte->domaine) === $k)>{{ $v }}</option>@endforeach</select></div>
    </div>
    <fieldset class="border rounded-lg p-3 bg-rose-50/40">
        <legend class="px-1 text-sm font-semibold">Disponible</legend>
        <div class="flex flex-wrap gap-6">
            @foreach(\App\Models\ActeCatalogue::DISPONIBILITES as $k => $v)
            <label class="inline-flex items-center gap-2 text-sm cursor-pointer"><input type="radio" name="disponibilite" value="{{ $k }}" class="h-5 w-5" @checked(old('disponibilite', $acte->disponibilite) === $k)> {{ $v }}</label>
            @endforeach
        </div>
    </fieldset>
    <div class="grid md:grid-cols-2 gap-3">
        <div><label for="fa-cat" class="block text-sm font-semibold mb-1">Catégorie acte</label>
            <select id="fa-cat" name="categorie_acte" class="w-full min-h-[44px] border rounded-lg px-3"><option value="">—</option>@foreach(\App\Models\ActeCatalogue::CATEGORIES as $c)<option value="{{ $c }}" @selected(old('categorie_acte', $acte->categorie_acte) === $c)>{{ mb_strtoupper($c) }}</option>@endforeach</select></div>
        <div><label for="fa-duree" class="block text-sm font-semibold mb-1">Durée prévue (minutes)</label>
            <input id="fa-duree" name="duree_minutes" type="number" min="5" value="{{ old('duree_minutes', $acte->duree_minutes) }}" class="w-full min-h-[44px] border rounded-lg px-3"></div>
    </div>
    <div><label for="fa-obs" class="block text-sm font-semibold mb-1">Observation</label>
        <textarea id="fa-obs" name="observation" rows="3" class="w-full border rounded-lg px-3 py-2">{{ old('observation', $acte->observation) }}</textarea></div>
    <div class="flex gap-3">
        <button class="min-h-[44px] px-6 bg-blue-900 hover:bg-blue-950 text-white rounded-lg font-semibold">Valider ✓</button>
        <a href="{{ route('tarification.actes') }}" class="min-h-[44px] inline-flex items-center px-6 bg-gray-600 text-white rounded-lg font-semibold">Annuler ✕</a>
    </div>
</form>

<section class="bg-white rounded-xl shadow p-5">
    <h3 class="font-semibold text-gray-700 border-b pb-2 mb-3">Prise en charge selon tarif</h3>
    @if($acte->exists)
    <p class="text-xs text-gray-500 mb-2">Fonction tarifaire {{ $acte->fonction_tarifaire }} : prix dans chaque catégorie de convention.</p>
    <table class="w-full text-sm">
        <thead class="bg-gray-50"><tr><th class="text-left px-2 py-1">Cat.</th><th class="text-right px-2 py-1">Coeff.</th><th class="text-right px-2 py-1">Normal</th><th class="text-right px-2 py-1">Urgent</th><th class="text-right px-2 py-1">Externe</th></tr></thead>
        <tbody class="divide-y">
        @foreach($categories as $c)
            <tr>
                <td class="px-2 py-1 font-semibold">{{ $c->nom }}</td>
                <td class="px-2 py-1 text-right font-mono text-xs">{{ rtrim(rtrim(number_format($c->coefficient($acte->fonction_tarifaire), 4, ',', ''), '0'), ',') }}</td>
                <td class="px-2 py-1 text-right">{{ number_format($acte->prixPourCategorie($c, 'normal'), 2, ',', ' ') }} {{ $devise }}</td>
                <td class="px-2 py-1 text-right">{{ number_format($acte->prixPourCategorie($c, 'urgent'), 2, ',', ' ') }} {{ $devise }}</td>
                <td class="px-2 py-1 text-right">{{ number_format($acte->prixPourCategorie($c, 'externe'), 2, ',', ' ') }} {{ $devise }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
    @else
    <p class="text-sm text-gray-500">Enregistrez l'acte pour voir ses prix par catégorie.</p>
    @endif
</section>
</div>
@endsection
