@extends('layouts.app')
@section('title', $type->exists ? 'Modifier '.$type->libelle : 'Nouvel examen')
@section('content')
@include('catalogue._onglets')
<h2 class="text-2xl font-bold text-gray-800 mb-4">{{ $type->exists ? 'Modifier « '.$type->libelle.' »' : ($domaine === 'imagerie' ? 'Nouvelle technique d\'imagerie' : 'Nouvel examen de laboratoire') }}</h2>
@include('catalogue._erreurs')
<form method="POST" action="{{ $type->exists ? route('catalogue.examens.update', $type) : route('catalogue.examens.store') }}" class="space-y-4">
    @csrf
    <input type="hidden" name="domaine" value="{{ $domaine }}">
    <div class="bg-white rounded-xl shadow p-4 grid md:grid-cols-3 gap-3">
        <div class="md:col-span-2"><label for="e-lib" class="block text-sm font-semibold mb-1">Désignation <span class="text-red-500">*</span></label>
            <input id="e-lib" name="libelle" required value="{{ old('libelle', $type->libelle) }}" class="w-full min-h-[44px] border rounded-lg px-3"></div>
        <div><label for="e-code" class="block text-sm font-semibold mb-1">Code <span class="text-xs font-normal text-gray-500">(généré si vide)</span></label>
            <input id="e-code" name="code" value="{{ old('code', $type->code) }}" class="w-full min-h-[44px] border rounded-lg px-3 font-mono"></div>
        @if($domaine === 'imagerie')
        <input type="hidden" name="categorie" value="imagerie">
        <div><label for="e-mod" class="block text-sm font-semibold mb-1">Modalité</label>
            <select id="e-mod" name="modalite" class="w-full min-h-[44px] border rounded-lg px-3">
                @foreach(\App\Models\TypeExamen::MODALITES as $k => $v)<option value="{{ $k }}" @selected(old('modalite', $type->modalite) === $k)>{{ $v }}</option>@endforeach
            </select></div>
        @else
        <div><label for="e-cat" class="block text-sm font-semibold mb-1">Catégorie</label>
            <select id="e-cat" name="categorie" class="w-full min-h-[44px] border rounded-lg px-3">
                @foreach(['hematologie' => 'Hématologie', 'biochimie' => 'Biochimie', 'microbiologie' => 'Microbiologie', 'serologie' => 'Sérologie', 'parasitologie' => 'Parasitologie', 'anatomopathologie' => 'Anatomopathologie', 'autre' => 'Autre'] as $k => $v)
                <option value="{{ $k }}" @selected(old('categorie', $type->categorie) === $k)>{{ $v }}</option>@endforeach
            </select></div>
        @endif
        <div><label for="e-prix" class="block text-sm font-semibold mb-1">Prix</label>
            <input id="e-prix" name="prix" type="number" step="0.01" min="0" value="{{ old('prix', $type->prix) }}" class="w-full min-h-[44px] border rounded-lg px-3"></div>
        <div><label for="e-delai" class="block text-sm font-semibold mb-1">Délai de rendu (heures)</label>
            <input id="e-delai" name="delai_heures" type="number" min="0" value="{{ old('delai_heures', $type->delai_heures) }}" class="w-full min-h-[44px] border rounded-lg px-3"></div>
        <div><label for="e-ft" class="block text-sm font-semibold mb-1">Fonction tarifaire <span class="text-xs font-normal text-gray-500">(conventions)</span></label>
            <select id="e-ft" name="fonction_tarifaire" class="w-full min-h-[44px] border rounded-lg px-3">
                <option value="">— Coefficient du contrat —</option>
                @for($k = 1; $k <= \App\Models\CategorieTarifaire::NB_FONCTIONS; $k++)<option value="{{ $k }}" @selected((int) old('fonction_tarifaire', $type->fonction_tarifaire) === $k)>{{ $k }}</option>@endfor
            </select></div>
        <label class="flex items-center gap-2 text-sm"><input type="hidden" name="est_actif" value="0"><input type="checkbox" name="est_actif" value="1" @checked(old('est_actif', $type->est_actif ?? true))> Actif (proposé à la prescription)</label>
    </div>

    @if($domaine === 'labo')
    @php
        $params = old('parametres', $type->valeurs_reference['parametres'] ?? []);
        $params = array_values($params);
        $cible = max(6, count($params) + 3);
        while (count($params) < $cible) { $params[] = []; }
    @endphp
    <div class="bg-white rounded-xl shadow p-4 overflow-x-auto">
        <h3 class="font-semibold mb-1">Paramètres et valeurs de référence</h3>
        <p class="text-xs text-gray-500 mb-3">Chaque paramètre est une ligne de résultat. Les bornes servent à l'interprétation automatique (normal, bas, élevé). Laissez une ligne vide pour l'ignorer.</p>
        <table class="w-full text-sm">
            <thead><tr class="text-left text-gray-600">
                <th class="py-1 pr-2">Paramètre</th><th class="py-1 pr-2">Unité</th>
                <th class="py-1 pr-2">Homme min–max</th><th class="py-1 pr-2">Femme min–max</th><th class="py-1">Enfant min–max</th>
            </tr></thead>
            <tbody>
            @foreach($params as $i => $p)
                <tr>
                    <td class="py-1 pr-2"><input aria-label="Paramètre {{ $i + 1 }}" name="parametres[{{ $i }}][nom]" value="{{ $p['nom'] ?? '' }}" class="w-full min-h-[38px] border rounded px-2"></td>
                    <td class="py-1 pr-2"><input aria-label="Unité {{ $i + 1 }}" name="parametres[{{ $i }}][unite]" value="{{ $p['unite'] ?? '' }}" class="w-24 min-h-[38px] border rounded px-2"></td>
                    @foreach(['homme', 'femme', 'enfant'] as $cible)
                    <td class="py-1 pr-2 whitespace-nowrap">
                        <input aria-label="{{ $cible }} min {{ $i + 1 }}" type="number" step="any" name="parametres[{{ $i }}][{{ $cible }}][min]" value="{{ $p[$cible]['min'] ?? '' }}" class="w-20 min-h-[38px] border rounded px-2">
                        <input aria-label="{{ $cible }} max {{ $i + 1 }}" type="number" step="any" name="parametres[{{ $i }}][{{ $cible }}][max]" value="{{ $p[$cible]['max'] ?? '' }}" class="w-20 min-h-[38px] border rounded px-2">
                    </td>
                    @endforeach
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
    @endif

    <button class="min-h-[44px] px-6 bg-blue-700 hover:bg-blue-800 text-white rounded-lg font-semibold">💾 Enregistrer</button>
</form>
@endsection
