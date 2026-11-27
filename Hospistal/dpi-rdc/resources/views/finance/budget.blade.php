@extends('layouts.app')
@section('title', 'Budget '.$annee)
@section('content')
@include('finance._onglets')
@php $peutValider = auth()->user()->can('finance.valider') || auth()->user()->hasAnyRole(['super_admin', 'directeur']); @endphp
<form method="GET" class="flex items-end gap-2 mb-3"><div><label for="bu-an" class="block text-xs text-gray-600 mb-1">Année</label><input id="bu-an" type="number" name="annee" value="{{ $annee }}" class="w-28 min-h-[44px] border rounded-lg px-3"></div><button class="min-h-[44px] px-4 bg-gray-100 hover:bg-gray-200 rounded-lg">Afficher</button></form>
<form method="POST" action="{{ route('finance.budget.store') }}">
    @csrf <input type="hidden" name="annee" value="{{ $annee }}">
    <div class="bg-white rounded-xl shadow overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-left"><tr><th class="px-3 py-2">Rubrique</th><th class="px-3 py-2 text-right">Prévu (FC)</th><th class="px-3 py-2 text-right">Réalisé (FC)</th><th class="px-3 py-2 text-right">Écart</th><th class="px-3 py-2 text-right">Exécution</th></tr></thead>
            <tbody class="divide-y">
            @foreach($lignes as $l)
                <tr>
                    <td class="px-3 py-2">{{ $l->rubrique->libelle }} <span class="text-xs text-gray-400 font-mono">{{ $l->rubrique->code }}</span></td>
                    <td class="px-3 py-2 text-right">@if($peutValider)<label class="sr-only" for="bp-{{ $l->rubrique->id }}">Prévu</label><input id="bp-{{ $l->rubrique->id }}" name="prevus[{{ $l->rubrique->id }}]" type="number" step="1" min="0" value="{{ $l->prevu ?: '' }}" class="w-36 min-h-[36px] border rounded px-2 text-right">@else{{ number_format($l->prevu, 0, ',', ' ') }}@endif</td>
                    <td class="px-3 py-2 text-right">{{ number_format($l->realise, 0, ',', ' ') }}</td>
                    <td class="px-3 py-2 text-right {{ $l->ecart < 0 ? 'text-red-700 font-semibold' : '' }}">{{ number_format($l->ecart, 0, ',', ' ') }}</td>
                    <td class="px-3 py-2 text-right">{{ $l->taux !== null ? $l->taux.' %' : '—' }}</td>
                </tr>
            @endforeach
            </tbody>
            <tfoot class="bg-gray-50 font-bold"><tr><td class="px-3 py-2">Total</td><td class="px-3 py-2 text-right">{{ number_format($lignes->sum('prevu'), 0, ',', ' ') }}</td><td class="px-3 py-2 text-right">{{ number_format($lignes->sum('realise'), 0, ',', ' ') }}</td><td class="px-3 py-2 text-right">{{ number_format($lignes->sum('ecart'), 0, ',', ' ') }}</td><td></td></tr></tfoot>
        </table>
    </div>
    @if($peutValider)<button class="mt-3 min-h-[44px] px-6 bg-blue-700 hover:bg-blue-800 text-white rounded-lg font-semibold">💾 Enregistrer le budget</button>@endif
</form>
@if($peutValider)
<details class="bg-white rounded-xl shadow p-4 mt-4">
    <summary class="font-semibold text-blue-700 cursor-pointer">⊕ Ajouter une rubrique</summary>
    <form method="POST" action="{{ route('finance.rubriques.store') }}" class="mt-3 grid md:grid-cols-4 gap-3 items-end">@csrf
        <div><label for="ru-code" class="block text-sm font-semibold mb-1">Code</label><input id="ru-code" name="code" required class="w-full min-h-[44px] border rounded-lg px-3"></div>
        <div><label for="ru-lib" class="block text-sm font-semibold mb-1">Libellé</label><input id="ru-lib" name="libelle" required class="w-full min-h-[44px] border rounded-lg px-3"></div>
        <div><label for="ru-nat" class="block text-sm font-semibold mb-1">Nature</label><select id="ru-nat" name="nature" class="w-full min-h-[44px] border rounded-lg px-3">@foreach(\App\Models\RubriqueDepense::NATURES as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach</select></div>
        <div><button class="min-h-[44px] px-5 bg-blue-700 text-white rounded-lg font-semibold">Ajouter</button></div>
    </form>
</details>
@endif
@endsection
